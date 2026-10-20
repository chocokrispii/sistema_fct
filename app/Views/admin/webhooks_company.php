<h2>Webhooks por empresa</h2>
<p>Selecciona una empresa para ver juntos sus eventos enviados y recibidos. Los entrantes se asocian usando <code>data.empresa_id</code>; los salientes incluyen ese identificador cuando se puede determinar.</p>

<form class="page-form integration-test__form" method="GET" action="index.php">
    <input type="hidden" name="action" value="webhooks-empresa">
    <label>Empresa
        <select name="empresa_id" required>
            <option value="" disabled <?= $companyId === null ? 'selected' : '' ?>>Selecciona una empresa</option>
            <option value="0" <?= $companyId === 0 ? 'selected' : '' ?>>Sin empresa identificada</option>
            <?php foreach ($companies as $company): ?>
                <option value="<?= (int)$company['id'] ?>" <?= $companyId === (int)$company['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($company['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?> (ID <?= (int)$company['id'] ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="submit">Ver actividad</button>
</form>

<?php if ($companyId !== null): ?>
    <?php if ($companyId > 0): ?>
        <?php
        $selectedCompany = null;
        foreach ($companies as $company) {
            if ((int)$company['id'] === $companyId) {
                $selectedCompany = $company;
                break;
            }
        }
        ?>
        <h3>Actividad: <?= htmlspecialchars((string)$selectedCompany['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></h3>
    <?php else: ?>
        <h3>Eventos sin empresa identificada</h3>
    <?php endif; ?>

    <section class="integration-test__section">
        <h3>Enviados (<?= count($sent) ?>)</h3>
        <?php if ($sent === []): ?>
            <p>No hay eventos enviados asociados a esta selección.</p>
        <?php else: ?>
            <?php foreach ($sent as $event): ?>
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
                    <h4><?= htmlspecialchars($event['event_type'], ENT_QUOTES, 'UTF-8') ?></h4>
                    <p>Creado: <?= htmlspecialchars($event['created_at'], ENT_QUOTES, 'UTF-8') ?> · Evento: <code><?= htmlspecialchars($event['event_id'], ENT_QUOTES, 'UTF-8') ?></code></p>
                    <p>Estado: <?= htmlspecialchars($deliveryStatus, ENT_QUOTES, 'UTF-8') ?> · Intentos: <?= (int)$event['attempts'] ?> · HTTP <?= $event['http_status'] === null ? '—' : (int)$event['http_status'] ?></p>
                    <?php if (is_string($event['last_error']) && $event['last_error'] !== ''): ?>
                        <p>Error: <?= htmlspecialchars($event['last_error'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                    <details>
                        <summary>Ver JSON enviado y respuesta del receptor</summary>
                        <h5>Petición enviada</h5>
                        <pre class="integration-test__response"><?= htmlspecialchars($event['payload'], ENT_QUOTES, 'UTF-8') ?></pre>
                        <h5>Respuesta recibida</h5>
                        <pre class="integration-test__response"><?= htmlspecialchars($event['response_body'] ?? 'No se conservó la respuesta de este evento anterior al registro de respuestas.', ENT_QUOTES, 'UTF-8') ?></pre>
                    </details>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <section class="integration-test__section">
        <h3>Recibidos (<?= count($received) ?>)</h3>
        <?php if ($received === []): ?>
            <p>No hay eventos recibidos asociados a esta selección.</p>
        <?php else: ?>
            <?php foreach ($received as $event): ?>
                <article class="integration-test__section">
                    <h4><?= htmlspecialchars($event['event_type'], ENT_QUOTES, 'UTF-8') ?></h4>
                    <p>Recibido: <?= htmlspecialchars($event['received_at'], ENT_QUOTES, 'UTF-8') ?> · Evento: <code><?= htmlspecialchars($event['event_id'], ENT_QUOTES, 'UTF-8') ?></code></p>
                    <p>Última respuesta HTTP: <?= $event['response_status'] === null ? 'Aún no registrada' : (int)$event['response_status'] ?></p>
                    <details>
                        <summary>Ver JSON recibido y respuesta enviada</summary>
                        <h5>Petición recibida</h5>
                        <pre class="integration-test__response"><?= htmlspecialchars($event['payload'], ENT_QUOTES, 'UTF-8') ?></pre>
                        <h5>Respuesta enviada</h5>
                        <pre class="integration-test__response"><?= htmlspecialchars($event['response_body'] ?? 'No se conservó la respuesta de este evento anterior al registro de respuestas.', ENT_QUOTES, 'UTF-8') ?></pre>
                    </details>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
