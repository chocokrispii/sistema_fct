<h2>Webhooks enviados</h2>
<p>Eventos que esta aplicación envía a otros sistemas. Aquí puedes consultar la petición, el estado de entrega y la respuesta del receptor.</p>

<section class="integration-test__section">
    <?php if ($events === []): ?>
        <p>No hay eventos enviados.</p>
    <?php else: ?>
        <?php foreach ($events as $event): ?>
            <?php
            if ($event['delivered_at'] !== null) {
                $deliveryStatus = 'Entregado';
            } elseif ($event['failed_at'] !== null) {
                $deliveryStatus = 'Fallido';
            } else {
                $deliveryStatus = 'Pendiente o en reintento';
            }
            ?>
            <article class="integration-test__section">
                <h3><?= htmlspecialchars($event['event_type'], ENT_QUOTES, 'UTF-8') ?></h3>
                <p>Creado: <?= htmlspecialchars($event['created_at'], ENT_QUOTES, 'UTF-8') ?> · Evento: <code><?= htmlspecialchars($event['event_id'], ENT_QUOTES, 'UTF-8') ?></code></p>
                <p>Estado: <?= htmlspecialchars($deliveryStatus, ENT_QUOTES, 'UTF-8') ?> · Intentos: <?= (int)$event['attempts'] ?> · HTTP <?= $event['http_status'] === null ? '—' : (int)$event['http_status'] ?></p>
                <?php if (is_string($event['delivered_at'])): ?>
                    <p>Entregado: <?= htmlspecialchars($event['delivered_at'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <?php if (is_string($event['last_error']) && $event['last_error'] !== ''): ?>
                    <p>Error: <?= htmlspecialchars($event['last_error'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <details>
                    <summary>Ver JSON enviado y respuesta recibida</summary>
                    <h4>Petición enviada</h4>
                    <pre class="integration-test__response"><?= htmlspecialchars($event['payload'], ENT_QUOTES, 'UTF-8') ?></pre>
                    <h4>Respuesta del receptor</h4>
                    <pre class="integration-test__response"><?= htmlspecialchars($event['response_body'] ?? 'No se conservó la respuesta de este evento anterior al registro de respuestas.', ENT_QUOTES, 'UTF-8') ?></pre>
                </details>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
