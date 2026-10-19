<h2>Pruebas manuales de API y webhooks</h2>
<p>Prueba la API REST y el recorrido de webhooks desde la interfaz del sistema.</p>

<section class="integration-test__section">
    <h3>Solicitudes a la API REST</h3>
    <p>Introduce un token API activo. El navegador lo usará solo para la solicitud y lo borrará del campo al enviarla.</p>
    <div class="page-form integration-test__form" data-api-test-form role="group" aria-label="Solicitud manual a la API">
        <label>Token Bearer
            <input type="password" name="token" autocomplete="off" required>
        </label>
        <div class="integration-test__grid">
            <label>Método
                <select name="method">
                    <option value="GET">GET — consultar</option>
                    <option value="POST">POST — crear</option>
                    <option value="PATCH">PATCH — actualizar/revisar</option>
                </select>
            </label>
            <label>Recurso
                <select name="resource">
                    <option value="empresas">empresas</option>
                    <option value="alumnos">alumnos</option>
                    <option value="ofertas">ofertas</option>
                    <option value="convenios">convenios</option>
                    <option value="asignaciones">asignaciones</option>
                </select>
            </label>
            <label>ID (vacío para una lista o creación)
                <input type="number" name="resource_id" min="1" step="1">
            </label>
        </div>
        <div class="integration-test__grid" data-api-query-fields>
            <label>Página
                <input type="number" name="page" value="1" min="1" step="1">
            </label>
            <label>Registros por página
                <input type="number" name="per_page" value="20" min="1" max="100" step="1">
            </label>
        </div>
        <label>JSON de la solicitud (para POST o PATCH)
            <textarea name="body" rows="10" spellcheck="false" placeholder='{"nombre_comercial":"Empresa de prueba"}'></textarea>
        </label>
        <label class="integration-test__warning">
            <input type="checkbox" name="confirm_write">
            Confirmo que POST y PATCH pueden guardar cambios permanentes en la base de datos actual.
        </label>
        <button type="button" data-api-send>Enviar solicitud</button>
    </div>
    <p class="integration-test__status" data-api-status role="status" aria-live="polite"></p>
    <pre class="integration-test__response" data-api-response>No se ha enviado ninguna solicitud.</pre>
</section>

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
    <p>Encola un evento de prueba, ejecuta el despachador real y lo entrega al endpoint del propio Docker. No usa ni modifica el destino externo configurado.</p>
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
<script src="assets/js/integration-test.js" defer></script>
