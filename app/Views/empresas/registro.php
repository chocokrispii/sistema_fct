<section class="company-registration" aria-labelledby="company-registration-title">
    <div class="company-registration__intro">
        <span class="company-registration__eyebrow">Área de empresas</span>
        <h2 id="company-registration-title">Registra tu empresa</h2>
        <p>Crea tu cuenta para gestionar ofertas de prácticas y conectar con estudiantes.</p>
    </div>

    <?php if ($error !== ''): ?>
        <p class="message message-error company-registration__error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
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

                <div class="company-registration__field">
                    <label for="company-password">Contraseña <span aria-hidden="true">*</span></label>
                    <input id="company-password" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="company-password-help" placeholder="Mínimo 12 caracteres" required>
                    <small id="company-password-help">Debe tener entre 12 y 72 caracteres. Los espacios cuentan.</small>
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
                    <label for="company-cif">CIF <span aria-hidden="true">*</span></label>
                    <input id="company-cif" type="text" name="cif" maxlength="20" autocomplete="off" placeholder="Ej. B12345678" required>
                </div>

                <div class="company-registration__field">
                    <label for="company-contact">Persona de contacto <span class="company-registration__optional">Opcional</span></label>
                    <input id="company-contact" type="text" name="persona_contacto" maxlength="150" autocomplete="name" placeholder="Nombre y apellidos">
                </div>

                <div class="company-registration__field">
                    <label for="company-phone">Teléfono <span class="company-registration__optional">Opcional</span></label>
                    <input id="company-phone" type="tel" name="telefono" maxlength="20" autocomplete="tel" placeholder="Ej. 600 123 456">
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
