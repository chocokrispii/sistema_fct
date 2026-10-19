<?php

declare(strict_types=1);

final class WebhookCompanyActivityController
{
    public function __construct(private ?PDO $db = null)
    {
        $this->db ??= getPDOConnection();
    }

    public function listar(): void
    {
        $companies = $this->db->query(
            'SELECT id, nombre_empresa
             FROM empresas
             ORDER BY nombre_empresa, id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $rawCompanyId = $_GET['empresa_id'] ?? null;
        $companyId = null;
        if ($rawCompanyId !== null) {
            $companyId = filter_var($rawCompanyId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($companyId === false) {
                throw new InvalidArgumentException('La empresa seleccionada no es válida.');
            }
            if ($companyId > 0) {
                $knownCompanyIds = array_map(static fn(array $company): int => (int)$company['id'], $companies);
                if (!in_array($companyId, $knownCompanyIds, true)) {
                    throw new InvalidArgumentException('No se encontró la empresa seleccionada.');
                }
            }
        }

        $received = [];
        $sent = [];
        if ($companyId !== null) {
            $companyFilter = $companyId === 0
                ? 'CASE
                       WHEN JSON_UNQUOTE(JSON_EXTRACT(i.payload, \'$.data.empresa_id\')) REGEXP \'^[1-9][0-9]*$\'
                         AND EXISTS (
                             SELECT 1 FROM empresas known_company
                             WHERE known_company.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(i.payload, \'$.data.empresa_id\')) AS UNSIGNED)
                         )
                       THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(i.payload, \'$.data.empresa_id\')) AS UNSIGNED)
                   END IS NULL'
                : 'CASE
                       WHEN JSON_UNQUOTE(JSON_EXTRACT(i.payload, \'$.data.empresa_id\')) REGEXP \'^[1-9][0-9]*$\'
                         AND EXISTS (
                             SELECT 1 FROM empresas known_company
                             WHERE known_company.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(i.payload, \'$.data.empresa_id\')) AS UNSIGNED)
                         )
                       THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(i.payload, \'$.data.empresa_id\')) AS UNSIGNED)
                   END = :company_id';
            $receivedQuery = $this->db->prepare(
                'SELECT i.event_id, i.event_type, i.payload, i.received_at,
                        i.response_status, i.response_body
                 FROM webhook_inbox i
                 WHERE ' . $companyFilter . '
                 ORDER BY i.received_at DESC, i.event_id DESC
                 LIMIT 50'
            );
            if ($companyId > 0) {
                $receivedQuery->bindValue(':company_id', $companyId, PDO::PARAM_INT);
            }
            $receivedQuery->execute();
            $received = $receivedQuery->fetchAll(PDO::FETCH_ASSOC);

            $companyExpression = 'COALESCE(
                direct_company.id,
                c.empresa_id,
                p.empresa_id,
                ap.empresa_id
            )';
            $sentFilter = $companyId === 0
                ? $companyExpression . ' IS NULL'
                : $companyExpression . ' = :company_id';
            $sentQuery = $this->db->prepare(
                'SELECT o.event_id, o.event_type, o.payload, o.created_at, o.attempts,
                        o.delivered_at, o.failed_at, o.http_status, o.response_body, o.last_error
                 FROM webhook_outbox o
                 LEFT JOIN convenios c
                    ON c.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(o.payload, \'$.data.convenio_id\')) AS UNSIGNED)
                 LEFT JOIN empresa_especialidades_plazas p
                    ON p.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(o.payload, \'$.data.oferta_id\')) AS UNSIGNED)
                 LEFT JOIN asignaciones_fct a
                    ON a.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(o.payload, \'$.data.asignacion_id\')) AS UNSIGNED)
                 LEFT JOIN empresa_especialidades_plazas ap ON ap.id = a.oferta_id
                 LEFT JOIN empresas direct_company
                    ON direct_company.id = CASE
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(o.payload, \'$.data.empresa_id\')) REGEXP \'^[1-9][0-9]*$\'
                        THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(o.payload, \'$.data.empresa_id\')) AS UNSIGNED)
                    END
                 WHERE ' . $sentFilter . '
                 ORDER BY o.created_at DESC, o.event_id DESC
                 LIMIT 50'
            );
            if ($companyId > 0) {
                $sentQuery->bindValue(':company_id', $companyId, PDO::PARAM_INT);
            }
            $sentQuery->execute();
            $sent = $sentQuery->fetchAll(PDO::FETCH_ASSOC);
        }

        View::render('admin/webhooks_company', [
            'companies' => $companies,
            'companyId' => $companyId,
            'received' => $received,
            'sent' => $sent,
        ]);
    }
}
