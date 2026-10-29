<?php

declare(strict_types=1);

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/Empresa.php';
require_once __DIR__ . '/SpanishDataValidator.php';
require_once __DIR__ . '/AccountActivationMailer.php';
require_once __DIR__ . '/ActivationUrl.php';
require_once __DIR__ . '/AuditLogService.php';

/**
 * Alta masiva: cada fila se procesa en su propia transacción para que una fila inválida
 * (NIF duplicado, correo repetido, SMTP caído) no impida importar las demás.
 */
final class CompanyImporter
{
    private Usuario $usuarios;
    private Empresa $empresas;
    private AuditLogService $audit;

    public function __construct(private PDO $db)
    {
        $this->usuarios = new Usuario($db);
        $this->empresas = new Empresa($db);
        $this->audit = new AuditLogService($db);
    }

    /**
     * @param list<array{linea:int, razon_social:string, nif:string, email:string, representante:string}> $rows
     * @return array{total_procesados:int, insertados:int, fallidos:int, errores:list<array{linea:int, motivo:string}>}
     */
    public function import(array $rows, int $adminId, string $fileName = '', string $fileHash = ''): array
    {
        $appUrl = rtrim(getenv('FCT_APP_URL') ?: '', '/');
        if (!ActivationUrl::isAllowedBaseUrl($appUrl)) {
            throw new RuntimeException('FCT_APP_URL no está configurada.');
        }

        $seenNif = [];
        $seenEmail = [];
        $created = 0;
        $errors = [];
        foreach ($rows as $row) {
            $nif = SpanishDataValidator::normalizeFiscalIdentifier($row['nif']);
            $email = strtolower(trim($row['email']));
            $error = $this->validate($row, $nif, $email);
            if ($error === null && (isset($seenNif[$nif]) || isset($seenEmail[$email]))) {
                $error = 'NIF o correo duplicado dentro del archivo.';
            }
            if ($error !== null) {
                $errors[] = ['linea' => $row['linea'], 'motivo' => $error];
                continue;
            }
            $seenNif[$nif] = true;
            $seenEmail[$email] = true;

            try {
                $this->importRow($row, $nif, $email, $adminId, $appUrl);
                $created++;
            } catch (DomainException $e) {
                $errors[] = ['linea' => $row['linea'], 'motivo' => $e->getMessage()];
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                error_log('Importación de empresas, línea ' . $row['linea'] . ': ' . $e->getMessage());
                $errors[] = ['linea' => $row['linea'], 'motivo' => 'No se pudo crear la cuenta o enviar el correo de activación.'];
            }
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($fileName)) ?? '';
        $this->audit->registrar(
            $adminId,
            'empresas.importacion_creada',
            'empresas',
            null,
            'archivo=' . mb_substr($safeName, 0, 80) . '; sha256=' . $fileHash . '; filas=' . count($rows)
                . '; exitosas=' . $created . '; fallidas=' . count($errors),
            'creación'
        );
        return [
            'total_procesados' => count($rows),
            'insertados' => $created,
            'fallidos' => count($errors),
            'errores' => $errors,
        ];
    }

    /** @param array{linea:int, razon_social:string, nif:string, email:string, representante:string} $row */
    private function validate(array $row, string $nif, string $email): ?string
    {
        if (!SpanishDataValidator::isNifPersonaJuridica($nif)) {
            return 'NIF de persona jurídica no válido.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
            return 'Correo electrónico no válido.';
        }
        if ($row['razon_social'] === '' || mb_strlen($row['razon_social']) > 150) {
            return 'Razón social vacía o superior a 150 caracteres.';
        }
        if ($row['representante'] === '' || mb_strlen($row['representante']) > 150) {
            return 'Representante vacío o superior a 150 caracteres.';
        }
        return null;
    }

    /** @param array{linea:int, razon_social:string, nif:string, email:string, representante:string} $row */
    private function importRow(array $row, string $nif, string $email, int $adminId, string $appUrl): void
    {
        $this->db->beginTransaction();
        try {
            if ($this->usuarios->obtenerPorEmail($email) !== null) {
                throw new DomainException('El correo ya está registrado.');
            }
            $this->usuarios->assertIdentificadorFiscalDisponible($nif);

            $usuarioId = $this->usuarios->crear($email, bin2hex(random_bytes(32)), 'Empresa', false);
            if ($usuarioId < 1) {
                throw new RuntimeException('No se pudo crear el usuario.');
            }
            $empresaId = $this->empresas->crearImportada($usuarioId, $nif, $row['razon_social'], $row['representante'], $adminId);

            $token = bin2hex(random_bytes(32));
            $stmt = $this->db->prepare(
                'INSERT INTO company_activation_tokens (usuario_id, token_hash, expires_at)
                 VALUES (:usuario, :hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR))'
            );
            $stmt->execute(['usuario' => $usuarioId, 'hash' => hash('sha256', $token)]);

            $this->audit->registrar($adminId, 'empresa.importada_creada', 'empresas', $empresaId, 'origen=importacion_masiva', 'creación');
            $url = $appUrl . '/index.php?action=activar-cuenta&token=' . rawurlencode($token);
            (new AccountActivationMailer())->send($email, $row['razon_social'], $url);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
