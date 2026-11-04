<?php

declare(strict_types=1);

final class WebhookPublisher
{
    public const EVENTS = [
        'alumno.alta',
        'convenio.aprobado_firmado',
        'alumno.evaluacion_apto',
        'oferta.nueva_publicada',
    ];

    public static function publish(PDO $db, string $event, array $data): string
    {
        if (!in_array($event, self::EVENTS, true)) {
            throw new InvalidArgumentException('El evento de webhook no está permitido.');
        }
        if (!$db->inTransaction()) {
            throw new LogicException('Los eventos webhook deben encolarse dentro de la transacción de negocio.');
        }

        $eventId = 'evt_' . bin2hex(random_bytes(16));
        $payload = json_encode([
            'event' => $event,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'event_id' => $eventId,
            'data' => $data,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = $db->prepare(
            "INSERT INTO webhook_deliveries
                (subscription_id, event_id, event_type, payload)
             SELECT id, :event_id, :event_type, :payload
             FROM webhook_subscriptions
             WHERE state = 'ACTIVE' AND FIND_IN_SET(:subscribed_event, REPLACE(event_types, ' ', '')) > 0"
        );
        $stmt->execute([
            'event_id' => $eventId,
            'event_type' => $event,
            'payload' => $payload,
            'subscribed_event' => $event,
        ]);

        return $eventId;
    }
}
