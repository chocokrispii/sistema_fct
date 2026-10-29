<section class="integration-test__section">
    <h3>API REST propia</h3>
    <p>Consulta y gestiona los recursos de la API autenticada. Se requiere un token activo, que puedes crear o revocar desde <a href="index.php?action=api-tokens">Tokens API</a>. Las escrituras mediante POST o PATCH modifican datos de forma permanente.</p>
    <div class="page-form integration-test__form" data-api-test-form role="group" aria-label="Solicitud manual a la API">
        <label>Token API
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
<script type="module" src="assets/js/integration-test.js"></script>
