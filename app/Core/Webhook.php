<?php

declare(strict_types=1);

final class WebhookRequestException extends RuntimeException
{
    public function __construct(public readonly int $httpStatus, string $message)
    {
        parent::__construct($message);
    }
}

final class Webhook
{
    private const MAX_CLOCK_SKEW_SECONDS = 300;

    public static function secretFromEnvironment(string $name): string
    {
        $secret = getenv($name);
        if (!is_string($secret) || strlen($secret) < 32) {
            throw new RuntimeException("$name debe configurarse con al menos 32 caracteres aleatorios.");
        }

        return $secret;
    }

    public static function enqueue(PDO $db, string $eventType, array $data): void
    {
        $destination = getenv('FCT_WEBHOOK_OUTBOUND_URL');
        if (!is_string($destination) || trim($destination) === '') {
            return;
        }
        if (!preg_match('/^[a-z][a-z0-9._-]{0,99}$/', $eventType)) {
            throw new InvalidArgumentException('El tipo de evento webhook no es válido.');
        }
        $companyId = self::companyIdForEvent($db, $eventType, $data);
        if ($companyId !== null) {
            if (isset($data['empresa_id'])) {
                $providedCompanyId = filter_var(
                    $data['empresa_id'],
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );
                if ($providedCompanyId === false || $providedCompanyId !== $companyId) {
                    throw new InvalidArgumentException('El ID de empresa no coincide con el registro del evento.');
                }
            }
            $data['empresa_id'] = $companyId;
        } elseif (isset($data['empresa_id'])) {
            $providedCompanyId = filter_var(
                $data['empresa_id'],
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            if ($providedCompanyId === false) {
                throw new InvalidArgumentException('El ID de empresa del evento no es válido.');
            }
            $data['empresa_id'] = $providedCompanyId;
        }

        $eventId = self::uuid();
        $payload = json_encode([
            'event_id' => $eventId,
            'event' => $eventType,
            'version' => 1,
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'data' => (object)$data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $stmt = $db->prepare(
            'INSERT INTO webhook_outbox (event_id, event_type, payload)
             VALUES (:event_id, :event_type, :payload)'
        );
        $stmt->execute([
            'event_id' => $eventId,
            'event_type' => $eventType,
            'payload' => $payload,
        ]);
    }

    private static function companyIdForEvent(PDO $db, string $eventType, array $data): ?int
    {
        $lookups = [
            'convenio.creado' => ['convenios', 'convenio_id'],
            'convenio.revisado' => ['convenios', 'convenio_id'],
            'oferta.creada' => ['empresa_especialidades_plazas', 'oferta_id'],
            'oferta.revisada' => ['empresa_especialidades_plazas', 'oferta_id'],
        ];

        if (isset($lookups[$eventType])) {
            [$table, $idField] = $lookups[$eventType];
            $id = filter_var($data[$idField] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new InvalidArgumentException('El evento de webhook no identifica un registro válido.');
            }
            $stmt = $db->prepare("SELECT empresa_id FROM $table WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $companyId = $stmt->fetchColumn();
            if ($companyId === false) {
                throw new RuntimeException('No se pudo asociar el evento webhook a una empresa.');
            }

            return (int)$companyId;
        }

        if (in_array($eventType, ['practica.asignada', 'practica.cerrada'], true)) {
            $assignmentId = filter_var(
                $data['asignacion_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            if ($assignmentId === false) {
                throw new InvalidArgumentException('El evento de práctica no identifica una asignación válida.');
            }
            $stmt = $db->prepare(
                'SELECT p.empresa_id
                 FROM asignaciones_fct a
                 INNER JOIN empresa_especialidades_plazas p ON p.id = a.oferta_id
                 WHERE a.id = :id'
            );
            $stmt->execute(['id' => $assignmentId]);
            $companyId = $stmt->fetchColumn();
            if ($companyId === false) {
                throw new RuntimeException('No se pudo asociar el evento de práctica a una empresa.');
            }

            return (int)$companyId;
        }

        return null;
    }

    public static function receive(
        PDO $db,
        string $body,
        ?string $eventIdHeader,
        ?string $timestampHeader,
        ?string $signatureHeader,
        string $secret
    ): bool {
        if (strlen($secret) < 32) {
            throw new RuntimeException('El secreto de recepción webhook debe tener al menos 32 caracteres.');
        }
        if (
            $eventIdHeader === null
            || !preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $eventIdHeader)
            || $timestampHeader === null
            || !preg_match('/^[0-9]{10,12}$/', $timestampHeader)
            || abs(time() - (int)$timestampHeader) > self::MAX_CLOCK_SKEW_SECONDS
        ) {
            throw new WebhookRequestException(401, 'La autenticación del webhook no es válida.');
        }

        $expectedSignature = 'sha256=' . hash_hmac('sha256', $timestampHeader . '.' . $body, $secret);
        if (
            $signatureHeader === null
            || !preg_match('/^sha256=[a-f0-9]{64}$/', $signatureHeader)
            || !hash_equals($expectedSignature, $signatureHeader)
        ) {
            throw new WebhookRequestException(401, 'La autenticación del webhook no es válida.');
        }

        try {
            $payload = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new WebhookRequestException(400, 'El cuerpo debe contener JSON válido.');
        }
        if (
            !$payload instanceof stdClass
            || !is_string($payload->event_id ?? null)
            || !hash_equals($eventIdHeader, $payload->event_id)
            || !is_string($payload->event ?? null)
            || !preg_match('/^[a-z][a-z0-9._-]{0,99}$/', $payload->event)
            || ($payload->version ?? null) !== 1
            || !($payload->data ?? null) instanceof stdClass
        ) {
            throw new WebhookRequestException(400, 'El evento debe incluir event_id, event, version 1 y data como objeto JSON.');
        }

        try {
            $stmt = $db->prepare(
                'INSERT INTO webhook_inbox (event_id, event_type, payload)
                 VALUES (:event_id, :event_type, :payload)'
            );
            $stmt->execute([
                'event_id' => $eventIdHeader,
                'event_type' => $payload->event,
                'payload' => $body,
            ]);
            return true;
        } catch (PDOException $exception) {
            if ((int)($exception->errorInfo[1] ?? 0) === 1062) {
                $existing = $db->prepare('SELECT payload FROM webhook_inbox WHERE event_id = :event_id');
                $existing->execute(['event_id' => $eventIdHeader]);
                $storedPayload = $existing->fetchColumn();
                if (!is_string($storedPayload)) {
                    throw $exception;
                }
                if (!hash_equals($storedPayload, $body)) {
                    throw new WebhookRequestException(409, 'El identificador del evento ya se usó con otro contenido.');
                }
                return false;
            }
            throw $exception;
        }
    }

    public static function recordInboundResponse(PDO $db, string $eventId, int $status, string $body): void
    {
        $stmt = $db->prepare(
            'UPDATE webhook_inbox
             SET response_status = :status, response_body = :body
             WHERE event_id = :event_id'
        );
        $stmt->execute([
            'status' => $status,
            'body' => $body,
            'event_id' => $eventId,
        ]);
        if ($stmt->rowCount() !== 1) {
            $exists = $db->prepare('SELECT 1 FROM webhook_inbox WHERE event_id = :event_id');
            $exists->execute(['event_id' => $eventId]);
            if ($exists->fetchColumn() === false) {
                throw new RuntimeException('No se encontró el evento entrante para guardar su respuesta.');
            }
        }
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }
}
