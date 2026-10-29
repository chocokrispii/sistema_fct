<section class="company-registration" aria-labelledby="company-registration-title">
    <div class="company-registration__intro">
        <span class="company-registration__eyebrow">Área de empresas</span>
        <h2 id="company-registration-title">Contacte con Nosotros / Alta de Empresa</h2>
        <p>Crea tu cuenta para gestionar ofertas de prácticas y conectar con estudiantes.</p>
    </div>

    <?php if ($error !== ''): ?>
        <p class="message message-error company-registration__error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <?php if (isset($_GET['registrada'])): ?>
        <section class="message message-success" role="status">
            <h3>Solicitud registrada</h3>
            <p>La solicitud queda en estado <strong>Pendiente_Aprobacion</strong>. No podrás acceder hasta que un administrador la valide.</p>
        </section>
    <?php endif; ?>

    <form class="company-registration__form" method="POST" action="index.php?action=registro-empresa">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">

        <fieldset class="company-registration__section">
            <legend>Acceso a la cuenta</legend>
            <p class="company-registration__section-help">Usarás estos datos para iniciar sesión.</p>

            <div class="company-registration__grid">
                <div class="company-registration__field">
                    <label for="company-email">Correo electrónico <span aria-hidden="true">*</span></label>
                    <input id="company-email" type="email" name="email" maxlength="100" autocomplete="email" placeholder="nombre@empresa.es" required>
                </div>

            </div>
        </fieldset>

        <fieldset class="company-registration__section">
            <legend>Datos de la empresa</legend>
            <p class="company-registration__section-help">Indica la información legal y dónde podemos localizarte.</p>

            <div class="company-registration__grid">
                <div class="company-registration__field">
                    <label for="company-name">Razón social <span aria-hidden="true">*</span></label>
                    <input id="company-name" type="text" name="nombre_comercial" maxlength="150" autocomplete="organization" placeholder="Nombre legal de la empresa" required>
                </div>

                <div class="company-registration__field">
                    <label for="company-nif">NIF (Persona Jurídica) <span aria-hidden="true">*</span></label>
                    <input id="company-nif" type="text" name="nif_persona_juridica" maxlength="20" autocomplete="off" placeholder="Ej. B12345674" aria-describedby="company-nif-help" required>
                    <small id="company-nif-help">Introduce un NIF o pasaporte válido.</small>
                </div>

                <div class="company-registration__field">
                    <label for="company-contact">Persona de contacto <span aria-hidden="true">*</span></label>
                    <input id="company-contact" type="text" name="persona_contacto" maxlength="150" autocomplete="name" placeholder="Nombre y apellidos" required>
                </div>

                <div class="company-registration__field">
                    <label for="company-phone">Teléfono <span aria-hidden="true">*</span></label>
                    <input id="company-phone" type="tel" name="telefono" maxlength="20" autocomplete="tel" placeholder="Ej. 600 123 456" required>
                </div>

                <div class="company-registration__field company-registration__field--full">
                    <label for="company-address">Dirección principal <span aria-hidden="true">*</span></label>
                    <input id="company-address" type="text" name="direccion" maxlength="255" autocomplete="street-address" placeholder="Calle, número, código postal y localidad" required>
                </div>
            </div>
        </fieldset>

        <div class="company-registration__actions">
            <p><span aria-hidden="true">*</span> Campos obligatorios</p>
            <button class="company-registration__submit" type="submit">Crear cuenta de empresa</button>
        </div>
    </form>

    <p class="company-registration__login">¿Ya tienes una cuenta? <a href="index.php?action=login">Inicia sesión</a></p>
</section>
