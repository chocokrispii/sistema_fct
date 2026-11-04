<section class="integration-webhooks" aria-labelledby="webhooks-title">
    <h2 id="webhooks-title">Webhooks salientes</h2>
    <p>Los eventos se guardan en una cola transaccional y se entregan en segundo plano. Los envíos incluyen una firma HMAC-SHA256 en <code>X-FCT-Signature</code>.</p>
    <?php if ($error !== ''): ?>
        <p class="message message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <?php if (is_string($plainSecret)): ?>
        <p class="message message-success" role="status">Copia este secreto ahora. No volverá a mostrarse.</p>
        <pre class="token-secret"><?= htmlspecialchars($plainSecret, ENT_QUOTES, 'UTF-8') ?></pre>
    <?php endif; ?>
    <?php if (!$encryptionKeyConfigured): ?>
        <p class="message message-error" role="alert">Configura FCT_WEBHOOK_ENCRYPTION_KEY (64 caracteres hexadecimales) y reinicia el worker antes de crear suscripciones.</p>
    <?php endif; ?>

    <form class="page-form page-form--narrow" method="POST" action="index.php?action=integraciones">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="webhook_action" value="create">
        <label>Nombre de la integración
            <input type="text" name="name" maxlength="100" required>
        </label>
        <label>Endpoint HTTPS público
            <input type="url" name="endpoint_url" maxlength="2048" placeholder="https://integracion.example/webhooks/fct" required>
        </label>
        <fieldset>
            <legend>Eventos</legend>
            <?php foreach (WebhookPublisher::EVENTS as $event): ?>
                <label class="form-checkbox">
                    <input type="checkbox" name="events[]" value="<?= htmlspecialchars($event, ENT_QUOTES, 'UTF-8') ?>">
                    <code><?= htmlspecialchars($event, ENT_QUOTES, 'UTF-8') ?></code>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <button type="submit" <?= !$encryptionKeyConfigured ? 'disabled' : '' ?>>Crear suscripción y secreto</button>
    </form>

    <h3>Suscripciones</h3>
    <div class="table-responsive">
        <table>
            <thead><tr><th>Nombre</th><th>Endpoint</th><th>Eventos</th><th>Estado</th><th>Fallos</th><th>Acción</th></tr></thead>
            <tbody>
            <?php foreach ($subscriptions as $subscription): ?>
                <tr>
                    <td><?= htmlspecialchars($subscription['name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><code><?= htmlspecialchars($subscription['endpoint_url'], ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td><?= htmlspecialchars(str_replace(',', ', ', $subscription['event_types']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($subscription['state'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int)$subscription['failure_count'] ?></td>
                    <td>
                        <?php if ($subscription['state'] !== 'DISABLED'): ?>
                            <form method="POST" action="index.php?action=integraciones">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="webhook_action" value="disable">
                                <input type="hidden" name="subscription_id" value="<?= (int)$subscription['id'] ?>">
                                <button type="submit">Desactivar</button>
                            </form>
                        <?php endif; ?>
                        <?php if (in_array($subscription['state'], ['DEGRADED', 'DISABLED'], true)): ?>
                            <form method="POST" action="index.php?action=integraciones">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="webhook_action" value="retry">
                                <input type="hidden" name="subscription_id" value="<?= (int)$subscription['id'] ?>">
                                <button type="submit">Reactivar y reintentar fallidos</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($subscriptions === []): ?><tr><td colspan="6">Todavía no hay suscripciones.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <h3>Auditoría de entregas (últimas 100)</h3>
    <div class="table-responsive">
        <table>
            <thead><tr><th>Evento</th><th>Suscripción</th><th>Estado</th><th>Intentos</th><th>HTTP</th><th>Último error</th><th>Creado</th></tr></thead>
            <tbody>
            <?php foreach ($deliveries as $delivery): ?>
                <tr>
                    <td><code><?= htmlspecialchars($delivery['event_type'], ENT_QUOTES, 'UTF-8') ?></code><br><small><?= htmlspecialchars($delivery['event_id'], ENT_QUOTES, 'UTF-8') ?></small></td>
                    <td><?= htmlspecialchars($delivery['subscription_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($delivery['state'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int)$delivery['attempts'] ?>/4</td>
                    <td><?= $delivery['http_status'] === null ? '—' : (int)$delivery['http_status'] ?></td>
                    <td><?= htmlspecialchars((string)($delivery['last_error'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(EuropeanDate::dateTime($delivery['created_at']), ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($deliveries === []): ?><tr><td colspan="7">No hay entregas registradas.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
