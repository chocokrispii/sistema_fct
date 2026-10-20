<h2>Pruebas manuales de API y webhooks</h2>
<p>Prueba la API REST y el recorrido local de webhooks desde la interfaz del sistema.</p>

<?php require __DIR__ . '/_api_console.php'; ?>

<section class="integration-test__section">
    <h3>Recepción de un webhook sintético</h3>
    <p>Envía por HTTP un evento firmado al endpoint de entrada. Se archiva en <code>webhook_inbox</code>; no crea ni actualiza datos académicos.</p>
    <form class="page-form integration-test__form" method="POST" action="index.php?action=pruebas-integracion">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="test_action" value="receive">
        <label>Tipo de evento
            <input type="text" name="event_type" value="<?= htmlspecialchars($formValues['event_type'], ENT_QUOTES, 'UTF-8') ?>" maxlength="100" required>
        </label>
        <label>Datos JSON ficticios
            <textarea name="event_data" rows="5" spellcheck="false" required><?= htmlspecialchars($formValues['event_data'], ENT_QUOTES, 'UTF-8') ?></textarea>
        </label>
        <label class="integration-test__warning">
            <input type="checkbox" required>
            Confirmo que usaré datos ficticios, sin información personal.
        </label>
        <button type="submit">Enviar webhook al receptor local</button>
    </form>
</section>

<section class="integration-test__section">
    <h3>Envío saliente local</h3>
    <p>Encola un evento de prueba, ejecuta el despachador real y lo entrega al endpoint local. No usa ni modifica el destino externo configurado.</p>
    <form class="page-form integration-test__form" method="POST" action="index.php?action=pruebas-integracion">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="test_action" value="send">
        <label>Tipo de evento
            <input type="text" name="event_type" value="prueba.integracion" maxlength="100" required>
        </label>
        <label>Datos JSON ficticios
            <textarea name="event_data" rows="5" spellcheck="false" required>{"referencia":"dato-sintetico"}</textarea>
        </label>
        <label class="integration-test__warning">
            <input type="checkbox" required>
            Confirmo que se guardará un evento sintético en las bandejas locales de entrada y salida.
        </label>
        <button type="submit">Encolar y enviar al receptor local</button>
    </form>
</section>

<?php if ($error !== ''): ?>
    <p class="message message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if (is_array($result)): ?>
    <section class="integration-test__section" aria-live="polite">
        <h3>Resultado: <?= htmlspecialchars($result['direction'], ENT_QUOTES, 'UTF-8') ?></h3>
        <p>HTTP <?= (int)$result['status'] ?> · Evento <?= htmlspecialchars($result['event_id'], ENT_QUOTES, 'UTF-8') ?></p>
        <pre class="integration-test__response"><?= htmlspecialchars(
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ENT_QUOTES,
            'UTF-8'
        ) ?></pre>
    </section>
<?php endif; ?>
