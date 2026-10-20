<?php

declare(strict_types=1);

final class IntegrationHubController
{
    public function __construct(private ?PDO $db = null)
    {
        $this->db ??= getPDOConnection();
    }

    public function mostrar(): void
    {
        $tab = $_GET['tab'] ?? 'api';
        if (!is_string($tab) || !in_array($tab, ['api', 'recibidos', 'enviados'], true)) {
            throw new InvalidArgumentException('La sección de integraciones seleccionada no es válida.');
        }

        $groups = [];
        if ($tab === 'recibidos') {
            $events = $this->db->query(
                'SELECT i.event_id, i.event_type, i.payload, i.received_at,
                        i.response_status, i.response_body,
                        e.id AS company_id, e.nombre_empresa AS company_name
                 FROM webhook_inbox i
                 LEFT JOIN empresas e
                    ON e.id = CASE
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(i.payload, \'$.data.empresa_id\')) REGEXP \'^[1-9][0-9]*$\'
                        THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(i.payload, \'$.data.empresa_id\')) AS UNSIGNED)
                    END
                 ORDER BY e.nombre_empresa IS NULL, e.nombre_empresa, e.id,
                          i.received_at DESC, i.event_id DESC
                 LIMIT 500'
            )->fetchAll(PDO::FETCH_ASSOC);
            $groups = $this->groupByCompany($events);
        } elseif ($tab === 'enviados') {
            $events = $this->db->query(
                'SELECT o.event_id, o.event_type, o.payload, o.created_at, o.attempts,
                        o.delivered_at, o.failed_at, o.http_status, o.response_body, o.last_error,
                        e.id AS company_id, e.nombre_empresa AS company_name
                 FROM webhook_outbox o
                 LEFT JOIN convenios c
                    ON c.id = CASE
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(o.payload, \'$.data.convenio_id\')) REGEXP \'^[1-9][0-9]*$\'
                        THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(o.payload, \'$.data.convenio_id\')) AS UNSIGNED)
                    END
                 LEFT JOIN empresa_especialidades_plazas p
                    ON p.id = CASE
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(o.payload, \'$.data.oferta_id\')) REGEXP \'^[1-9][0-9]*$\'
                        THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(o.payload, \'$.data.oferta_id\')) AS UNSIGNED)
                    END
                 LEFT JOIN asignaciones_fct a
                    ON a.id = CASE
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(o.payload, \'$.data.asignacion_id\')) REGEXP \'^[1-9][0-9]*$\'
                        THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(o.payload, \'$.data.asignacion_id\')) AS UNSIGNED)
                    END
                 LEFT JOIN empresa_especialidades_plazas ap ON ap.id = a.oferta_id
                 LEFT JOIN empresas direct_company
                    ON direct_company.id = CASE
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(o.payload, \'$.data.empresa_id\')) REGEXP \'^[1-9][0-9]*$\'
                        THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(o.payload, \'$.data.empresa_id\')) AS UNSIGNED)
                    END
                 LEFT JOIN empresas e
                    ON e.id = COALESCE(direct_company.id, c.empresa_id, p.empresa_id, ap.empresa_id)
                 ORDER BY e.nombre_empresa IS NULL, e.nombre_empresa, e.id,
                          o.created_at DESC, o.event_id DESC
                 LIMIT 500'
            )->fetchAll(PDO::FETCH_ASSOC);
            $groups = $this->groupByCompany($events);
        }

        View::render('admin/integrations', [
            'tab' => $tab,
            'groups' => $groups,
            'csrfToken' => CsrfMiddleware::token(),
        ]);
    }

    private function groupByCompany(array $events): array
    {
        $groups = [];
        foreach ($events as $event) {
            $companyId = $event['company_id'] === null ? null : (int)$event['company_id'];
            $key = $companyId === null ? 'unknown' : (string)$companyId;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'company_name' => is_string($event['company_name']) && $event['company_name'] !== ''
                        ? $event['company_name']
                        : 'Sin empresa identificada',
                    'company_id' => $companyId,
                    'events' => [],
                ];
            }
            $groups[$key]['events'][] = $event;
        }

        return array_values($groups);
    }
}
