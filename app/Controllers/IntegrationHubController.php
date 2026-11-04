<?php

declare(strict_types=1);

require_once __DIR__ . '/../Core/WebhookCrypto.php';
require_once __DIR__ . '/../Core/WebhookEndpoint.php';
require_once __DIR__ . '/../Services/WebhookPublisher.php';

final class IntegrationHubController
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? getPDOConnection();
    }

    public function gestionar(): void
    {
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try {
                $this->handlePost();
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            } catch (Throwable $exception) {
                error_log('No se pudo actualizar una suscripción webhook: ' . $exception->getMessage());
                $error = 'No se pudo completar la operación de webhook. Revisa la configuración y vuelve a intentarlo.';
            }
        }

        $notice = $_SESSION['webhook_secret_once'] ?? null;
        unset($_SESSION['webhook_secret_once']);
        View::render('admin/integrations', [
            'error' => $error,
            'subscriptions' => $this->subscriptions(),
            'deliveries' => $this->deliveries(),
            'plainSecret' => is_string($notice) ? $notice : null,
            'encryptionKeyConfigured' => WebhookCrypto::configured(),
        ]);
    }

    private function handlePost(): void
    {
        $action = $_POST['webhook_action'] ?? null;
        if (!is_string($action)) {
            throw new InvalidArgumentException('Selecciona una acción de webhook válida.');
        }

        if ($action === 'create') {
            if (!WebhookCrypto::configured()) {
                throw new InvalidArgumentException('Configura FCT_WEBHOOK_ENCRYPTION_KEY antes de crear suscripciones.');
            }
            $name = $_POST['name'] ?? null;
            $endpoint = $_POST['endpoint_url'] ?? null;
            $events = $_POST['events'] ?? null;
            if (!is_string($name) || !is_string($endpoint) || !is_array($events)) {
                throw new InvalidArgumentException('Completa el nombre, endpoint y al menos un evento.');
            }
            $name = trim($name);
            $endpoint = trim($endpoint);
            $events = array_values(array_unique(array_filter(
                $events,
                static fn($event): bool => is_string($event) && in_array($event, WebhookPublisher::EVENTS, true)
            )));
            if ($name === '' || mb_strlen($name) > 100 || strlen($endpoint) > 2048 || $events === []) {
                throw new InvalidArgumentException('El nombre admite hasta 100 caracteres y debes elegir eventos válidos.');
            }
            if (filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException('Indica una URL válida para el endpoint.');
            }
            WebhookEndpoint::resolvePublicAddress($endpoint);

            $secret = bin2hex(random_bytes(32));
            $stmt = $this->db->prepare(
                'INSERT INTO webhook_subscriptions (name, endpoint_url, event_types, secret_ciphertext)
                 VALUES (:name, :endpoint, :events, :secret)'
            );
            $stmt->execute([
                'name' => $name,
                'endpoint' => $endpoint,
                'events' => implode(',', $events),
                'secret' => WebhookCrypto::encrypt($secret),
            ]);
            $_SESSION['webhook_secret_once'] = $secret;
            header('Location: index.php?action=integraciones&webhook=created');
            exit;
        }

        $subscriptionId = filter_var(
            $_POST['subscription_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($subscriptionId === false) {
            throw new InvalidArgumentException('El identificador de la suscripción no es válido.');
        }

        if ($action === 'disable') {
            $stmt = $this->db->prepare(
                "UPDATE webhook_subscriptions SET state = 'DISABLED' WHERE id = :id AND state <> 'DISABLED'"
            );
            $stmt->execute(['id' => $subscriptionId]);
        } elseif ($action === 'retry') {
            $this->db->beginTransaction();
            try {
                $stmt = $this->db->prepare(
                    "UPDATE webhook_subscriptions
                     SET state = 'ACTIVE', failure_count = 0, last_failure_at = NULL
                     WHERE id = :id AND state IN ('DEGRADED', 'DISABLED')"
                );
                $stmt->execute(['id' => $subscriptionId]);
                $deliveries = $this->db->prepare(
                    "UPDATE webhook_deliveries SET state = 'PENDING', attempts = 0,
                         available_at = UTC_TIMESTAMP(), locked_at = NULL, http_status = NULL, last_error = NULL
                     WHERE subscription_id = :id AND state = 'FAILED'"
                );
                $deliveries->execute(['id' => $subscriptionId]);
                $this->db->commit();
            } catch (Throwable $exception) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $exception;
            }
        } else {
            throw new InvalidArgumentException('La acción de webhook no está permitida.');
        }

        header('Location: index.php?action=integraciones');
        exit;
    }

    private function subscriptions(): array
    {
        return $this->db->query(
            'SELECT id, name, endpoint_url, event_types, state, failure_count, last_failure_at, created_at
             FROM webhook_subscriptions ORDER BY id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function deliveries(): array
    {
        return $this->db->query(
            'SELECT d.id, d.event_id, d.event_type, d.state, d.attempts, d.http_status,
                    d.last_error, d.created_at, d.delivered_at, s.name AS subscription_name
             FROM webhook_deliveries d
             INNER JOIN webhook_subscriptions s ON s.id = d.subscription_id
             ORDER BY d.id DESC LIMIT 100'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
