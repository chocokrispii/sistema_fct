<?php

declare(strict_types=1);

final class WebhookInboxController
{
    public function __construct(private ?PDO $db = null)
    {
        $this->db ??= getPDOConnection();
    }

    public function listarRecibidos(): void
    {
        $inbox = $this->db->query(
            'SELECT event_id, event_type, payload, received_at, response_status, response_body
             FROM webhook_inbox
             ORDER BY received_at DESC, event_id DESC
             LIMIT 50'
        )->fetchAll(PDO::FETCH_ASSOC);

        View::render('admin/webhooks_received', [
            'events' => $inbox,
        ]);
    }

    public function listarEnviados(): void
    {
        $outbox = $this->db->query(
            'SELECT event_id, event_type, payload, created_at, attempts, delivered_at,
                    failed_at, http_status, response_body, last_error
             FROM webhook_outbox
             ORDER BY created_at DESC, event_id DESC
             LIMIT 50'
        )->fetchAll(PDO::FETCH_ASSOC);

        View::render('admin/webhooks_sent', [
            'events' => $outbox,
        ]);
    }
}
