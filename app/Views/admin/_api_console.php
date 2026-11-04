<section class="integration-test__section">
    <h3>API REST propia</h3>
    <p>Consulta y gestiona los recursos de la API autenticada. Se requiere un token activo, que puedes crear o revocar desde <a href="index.php?action=api-tokens">Tokens API</a>. Las escrituras mediante POST o PATCH modifican datos de forma permanente.</p>
    <div class="page-form integration-test__form" data-api-test-form role="group" aria-label="Solicitud manual a la API">
        <label>Token API
            <?php
            $tooltipId = 'api-console-token-help';
            $tooltipText = 'Credencial de tipo Bearer. Se utiliza para autenticar la solicitud y se borra al terminar; no se guarda en el servidor.';
            require __DIR__ . '/../components/tooltip.php';
            ?>
            <input type="password" name="token" autocomplete="off" required>
        </label>
        <div class="integration-test__grid">
            <label>Método
                <?php
                $tooltipId = 'api-console-method-help';
                $tooltipText = 'GET consulta datos. POST crea un registro y PATCH actualiza o revisa uno existente; estos dos últimos pueden guardar cambios permanentes.';
                require __DIR__ . '/../components/tooltip.php';
                ?>
                <select name="method">
                    <option value="GET">GET — consultar</option>
                    <option value="POST">POST — crear</option>
                    <option value="PATCH">PATCH — actualizar/revisar</option>
                </select>
            </label>
            <label>Recurso
                <?php
                $tooltipId = 'api-console-resource-help';
                $tooltipText = 'Tipo de datos al que se dirige la solicitud. Los recursos disponibles dependen del rol del token.';
                require __DIR__ . '/../components/tooltip.php';
                ?>
                <select name="resource">
                    <option value="empresas">empresas</option>
                    <option value="alumnos">alumnos</option>
                    <option value="ofertas">ofertas</option>
                    <option value="convenios">convenios</option>
                    <option value="asignaciones">asignaciones</option>
                </select>
            </label>
            <label>ID (vacío para una lista o creación)
                <?php
                $tooltipId = 'api-console-id-help';
                $tooltipText = 'Identificador numérico para consultar o actualizar un registro. Déjalo vacío para consultar una lista o crear un registro.';
                require __DIR__ . '/../components/tooltip.php';
                ?>
                <input type="number" name="resource_id" min="1" step="1">
            </label>
        </div>
        <div class="integration-test__grid" data-api-query-fields>
            <label>Página
                <?php
                $tooltipId = 'api-console-page-help';
                $tooltipText = 'Número de página que quieres consultar. La primera página es la 1.';
                require __DIR__ . '/../components/tooltip.php';
                ?>
                <input type="number" name="page" value="1" min="1" step="1">
            </label>
            <label>Registros por página
                <?php
                $tooltipId = 'api-console-per-page-help';
                $tooltipText = 'Cantidad de registros devueltos en cada página; el máximo permitido es 100.';
                require __DIR__ . '/../components/tooltip.php';
                ?>
                <input type="number" name="per_page" value="20" min="1" max="100" step="1">
            </label>
        </div>
        <label>JSON de la solicitud (para POST o PATCH)
            <?php
            $tooltipId = 'api-console-body-help';
            $tooltipText = 'Cuerpo de la solicitud en formato JSON. Debe ser un objeto y ajustarse a los campos aceptados por el recurso seleccionado.';
            require __DIR__ . '/../components/tooltip.php';
            ?>
            <textarea name="body" rows="10" spellcheck="false" placeholder='{"nombre_comercial":"Empresa de prueba"}'></textarea>
        </label>
        <label class="integration-test__warning">
            <input type="checkbox" name="confirm_write">
            Confirmo que POST y PATCH pueden guardar cambios permanentes en la base de datos actual.
            <?php
            $tooltipId = 'api-console-confirm-help';
            $tooltipText = 'La consola solo enviará una solicitud POST o PATCH después de marcar esta confirmación.';
            require __DIR__ . '/../components/tooltip.php';
            ?>
        </label>
        <button type="button" data-api-send>Enviar solicitud</button>
    </div>
    <p class="integration-test__status" data-api-status role="status" aria-live="polite"></p>
    <pre class="integration-test__response" data-api-response>No se ha enviado ninguna solicitud.</pre>
</section>
<script type="module" src="assets/js/integration-test.js"></script>
