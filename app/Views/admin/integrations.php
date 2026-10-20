<?php
$tabs = [
    'api' => 'API propia',
    'recibidos' => 'Webhooks recibidos',
    'enviados' => 'Webhooks enviados',
];
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<h2>Integraciones</h2>
<p>Consulta la API propia y la actividad webhook, organizada por empresa.</p>

<nav class="integration-hub__tabs" aria-label="Secciones de integraciones">
    <?php foreach ($tabs as $tabId => $label): ?>
        <a href="index.php?action=integraciones&amp;tab=<?= $escape($tabId) ?>"
           <?= $tab === $tabId ? 'aria-current="page"' : '' ?>><?= $escape($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'api'): ?>
    <?php require __DIR__ . '/_api_console.php'; ?>
<?php else: ?>
    <h3><?= $tab === 'recibidos' ? 'Webhooks recibidos por empresa' : 'Webhooks enviados por empresa' ?></h3>
    <p>Se muestran hasta 500 eventos, ordenados por empresa y, dentro de cada empresa, del más reciente al más antiguo.</p>
    <?php if ($groups === []): ?>
        <section class="integration-test__section">
            <p><?= $tab === 'recibidos' ? 'Todavía no hay webhooks recibidos.' : 'Todavía no hay webhooks enviados.' ?></p>
        </section>
    <?php else: ?>
        <?php foreach ($groups as $group): ?>
            <section class="integration-test__section">
                <h3><?= $escape($group['company_name']) ?><?= $group['company_id'] === null ? '' : ' (ID ' . (int)$group['company_id'] . ')' ?></h3>
                <?php foreach ($group['events'] as $event): ?>
                    <article class="integration-test__section">
                        <h4><?= $escape((string)$event['event_type']) ?></h4>
                        <?php if ($tab === 'recibidos'): ?>
                            <p>Recibido: <?= $escape((string)$event['received_at']) ?></p>
                            <p>Última respuesta HTTP: <?= $event['response_status'] === null ? 'Aún no registrada' : (int)$event['response_status'] ?></p>
                            <details>
                                <summary>Ver JSON recibido y respuesta enviada</summary>
                                <h5>Petición recibida</h5>
                                <pre class="integration-test__response"><?= $escape((string)$event['payload']) ?></pre>
                                <h5>Respuesta enviada</h5>
                                <pre class="integration-test__response"><?= $escape((string)($event['response_body'] ?? 'No hay respuesta HTTP registrada.')) ?></pre>
                            </details>
                        <?php else: ?>
                            <?php
                            if ($event['delivered_at'] !== null) {
                                $status = 'Entregado';
                            } elseif ($event['failed_at'] !== null) {
                                $status = 'Fallido';
                            } else {
                                $status = 'Pendiente o en reintento';
                            }
                            ?>
                            <p>Creado: <?= $escape((string)$event['created_at']) ?> · Estado: <?= $escape($status) ?> · Intentos: <?= (int)$event['attempts'] ?> · HTTP <?= $event['http_status'] === null ? '—' : (int)$event['http_status'] ?></p>
                            <?php if (is_string($event['last_error']) && $event['last_error'] !== ''): ?>
                                <p>Error: <?= $escape($event['last_error']) ?></p>
                            <?php endif; ?>
                            <details>
                                <summary>Ver JSON enviado y respuesta del receptor</summary>
                                <h5>Petición enviada</h5>
                                <pre class="integration-test__response"><?= $escape((string)$event['payload']) ?></pre>
                                <h5>Respuesta del receptor</h5>
                                <pre class="integration-test__response"><?= $escape((string)($event['response_body'] ?? 'No hay respuesta HTTP registrada.')) ?></pre>
                            </details>
                        <?php endif; ?>
                        <p>Evento: <code><?= $escape((string)$event['event_id']) ?></code></p>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>
