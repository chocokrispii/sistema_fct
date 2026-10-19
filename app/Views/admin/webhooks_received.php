<h2>Webhooks recibidos</h2>
<p>Eventos que otros sistemas han enviado a esta aplicación. Se guardan para consulta; recibirlos no modifica ofertas, convenios ni prácticas.</p>

<section class="integration-test__section">
    <?php if ($events === []): ?>
        <p>No hay eventos recibidos.</p>
    <?php else: ?>
        <?php foreach ($events as $event): ?>
            <article class="integration-test__section">
                <h3><?= htmlspecialchars($event['event_type'], ENT_QUOTES, 'UTF-8') ?></h3>
                <p>Recibido: <?= htmlspecialchars($event['received_at'], ENT_QUOTES, 'UTF-8') ?> · Evento: <code><?= htmlspecialchars($event['event_id'], ENT_QUOTES, 'UTF-8') ?></code></p>
                <p>Última respuesta HTTP:
                    <?php if ($event['response_status'] === null): ?>
                        Aún no registrada
                    <?php else: ?>
                        <?= (int)$event['response_status'] ?>
                    <?php endif; ?>
                </p>
                <details>
                    <summary>Ver JSON recibido y respuesta enviada</summary>
                    <h4>Petición recibida</h4>
                    <pre class="integration-test__response"><?= htmlspecialchars($event['payload'], ENT_QUOTES, 'UTF-8') ?></pre>
                    <h4>Respuesta enviada</h4>
                    <pre class="integration-test__response"><?= htmlspecialchars($event['response_body'] ?? 'No se conservó la respuesta de este evento anterior al registro de respuestas.', ENT_QUOTES, 'UTF-8') ?></pre>
                </details>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
