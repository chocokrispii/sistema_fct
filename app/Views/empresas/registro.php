<?php
$fieldErrors = $fieldErrors ?? [];
$firstInvalidField = null;
foreach ([
    'email',
    'password',
    'razon_social',
    'nombre_comercial',
    'cif',
    'representante_legal',
    'dni_representante',
    'telefono',
    'direccion',
] as $field) {
    if (isset($fieldErrors[$field])) {
        $firstInvalidField = $field;
        break;
    }
}
?>
<section class="company-registration" aria-labelledby="company-registration-title">
    <div class="company-registration__intro">
        <span class="company-registration__eyebrow">Área de empresas</span>
        <h2 id="company-registration-title">Contacte con nosotros / Alta de empresa</h2>
        <p>Envía tus datos para solicitar el alta y gestionar ofertas de prácticas.</p>
    </div>

    <?php if ($success): ?>
        <p class="message message-success company-registration__success" role="status" tabindex="-1">
            Solicitud enviada con éxito. Su cuenta se encuentra en estado Pendiente de Aprobación. Se le notificará una vez el equipo administrativo valide la información.
        </p>
        <p class="company-registration__login">Cuando el equipo valide la solicitud, podrás <a href="index.php?action=login">iniciar sesión</a>.</p>
    <?php else: ?>
        <?php if ($error !== ''): ?>
            <p id="company-registration-error" class="message message-error company-registration__error" role="alert" tabindex="-1"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <form class="company-registration__form" method="POST" action="index.php?action=registro-empresa"<?= $error !== '' ? ' aria-describedby="company-registration-error"' : '' ?>>
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

            <fieldset class="company-registration__section">
                <legend>Acceso a la cuenta</legend>
                <p class="company-registration__section-help">Usarás estos datos para iniciar sesión cuando la solicitud sea aprobada.</p>

                <div class="company-registration__grid">
                    <div class="company-registration__field">
                        <label for="company-email">Correo electrónico <span aria-hidden="true">*</span></label>
                        <input id="company-email" type="email" name="email" maxlength="100" autocomplete="email" value="<?= htmlspecialchars($values['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="nombre@empresa.es" aria-invalid="<?= isset($fieldErrors['email']) ? 'true' : 'false' ?>" aria-describedby="company-email-error"<?= $firstInvalidField === 'email' ? ' autofocus' : '' ?> required>
                        <small id="company-email-error" data-field-error<?= isset($fieldErrors['email']) ? ' role="alert"' : '' ?>><?= htmlspecialchars($fieldErrors['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                    </div>

                    <div class="company-registration__field">
                        <label for="company-password">Contraseña <span aria-hidden="true">*</span></label>
                        <input id="company-password" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" aria-invalid="<?= isset($fieldErrors['password']) ? 'true' : 'false' ?>" aria-describedby="company-password-help company-password-error" placeholder="Mínimo 12 caracteres"<?= $firstInvalidField === 'password' ? ' autofocus' : '' ?> required>
                        <small id="company-password-help">Debe tener entre 12 y 72 bytes. Los espacios cuentan.</small>
                        <small id="company-password-error" data-field-error<?= isset($fieldErrors['password']) ? ' role="alert"' : '' ?>><?= htmlspecialchars($fieldErrors['password'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                    </div>
                </div>
            </fieldset>

            <fieldset class="company-registration__section">
                <legend>Datos de la empresa</legend>
                <p class="company-registration__section-help">La información se revisará antes de habilitar el acceso a los módulos privados.</p>

                <div class="company-registration__grid">
                    <div class="company-registration__field">
                        <label for="company-legal-name">Razón social <span aria-hidden="true">*</span></label>
                        <input id="company-legal-name" type="text" name="razon_social" maxlength="150" autocomplete="organization" value="<?= htmlspecialchars($values['razon_social'] ?? '', ENT_QUOTES, 'UTF-8') ?>" aria-invalid="<?= isset($fieldErrors['razon_social']) ? 'true' : 'false' ?>" aria-describedby="company-razon_social-error"<?= $firstInvalidField === 'razon_social' ? ' autofocus' : '' ?> required>
                        <small id="company-razon_social-error" data-field-error<?= isset($fieldErrors['razon_social']) ? ' role="alert"' : '' ?>><?= htmlspecialchars($fieldErrors['razon_social'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                    </div>

                    <div class="company-registration__field">
                        <label for="company-commercial-name">Nombre comercial <span class="company-registration__optional">Opcional</span></label>
                        <input id="company-commercial-name" type="text" name="nombre_comercial" maxlength="150" autocomplete="organization" value="<?= htmlspecialchars($values['nombre_comercial'] ?? '', ENT_QUOTES, 'UTF-8') ?>" aria-invalid="<?= isset($fieldErrors['nombre_comercial']) ? 'true' : 'false' ?>" aria-describedby="company-nombre_comercial-error"<?= $firstInvalidField === 'nombre_comercial' ? ' autofocus' : '' ?>>
                        <small id="company-nombre_comercial-error" data-field-error<?= isset($fieldErrors['nombre_comercial']) ? ' role="alert"' : '' ?>><?= htmlspecialchars($fieldErrors['nombre_comercial'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                    </div>

                    <div class="company-registration__field">
                        <label for="company-cif">CIF / NIF <span aria-hidden="true">*</span></label>
                        <input id="company-cif" type="text" name="cif" maxlength="20" autocomplete="off" value="<?= htmlspecialchars($values['cif'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Ej. A28017895" aria-invalid="<?= isset($fieldErrors['cif']) ? 'true' : 'false' ?>" aria-describedby="company-cif-error"<?= $firstInvalidField === 'cif' ? ' autofocus' : '' ?> required>
                        <small id="company-cif-error" data-field-error<?= isset($fieldErrors['cif']) ? ' role="alert"' : '' ?>><?= htmlspecialchars($fieldErrors['cif'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                    </div>

                    <div class="company-registration__field">
                        <label for="company-representative">Representante legal <span aria-hidden="true">*</span></label>
                        <input id="company-representative" type="text" name="representante_legal" maxlength="150" autocomplete="name" value="<?= htmlspecialchars($values['representante_legal'] ?? '', ENT_QUOTES, 'UTF-8') ?>" aria-invalid="<?= isset($fieldErrors['representante_legal']) ? 'true' : 'false' ?>" aria-describedby="company-representante_legal-error"<?= $firstInvalidField === 'representante_legal' ? ' autofocus' : '' ?> required>
                        <small id="company-representante_legal-error" data-field-error<?= isset($fieldErrors['representante_legal']) ? ' role="alert"' : '' ?>><?= htmlspecialchars($fieldErrors['representante_legal'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                    </div>

                    <div class="company-registration__field">
                        <label for="company-representative-dni">DNI / NIE del representante <span class="company-registration__optional">Opcional</span></label>
                        <input id="company-representative-dni" type="text" name="dni_representante" maxlength="20" autocomplete="off" aria-invalid="<?= isset($fieldErrors['dni_representante']) ? 'true' : 'false' ?>" aria-describedby="company-dni_representante-error"<?= $firstInvalidField === 'dni_representante' ? ' autofocus' : '' ?>>
                        <small id="company-dni_representante-error" data-field-error<?= isset($fieldErrors['dni_representante']) ? ' role="alert"' : '' ?>><?= htmlspecialchars($fieldErrors['dni_representante'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                    </div>

                    <div class="company-registration__field">
                        <label for="company-phone">Teléfono <span class="company-registration__optional">Opcional</span></label>
                        <input id="company-phone" type="tel" name="telefono" maxlength="20" autocomplete="tel" value="<?= htmlspecialchars($values['telefono'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Ej. 600 123 456" aria-invalid="<?= isset($fieldErrors['telefono']) ? 'true' : 'false' ?>" aria-describedby="company-telefono-error"<?= $firstInvalidField === 'telefono' ? ' autofocus' : '' ?>>
                        <small id="company-telefono-error" data-field-error<?= isset($fieldErrors['telefono']) ? ' role="alert"' : '' ?>><?= htmlspecialchars($fieldErrors['telefono'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                    </div>

                    <div class="company-registration__field company-registration__field--full">
                        <label for="company-address">Dirección completa <span aria-hidden="true">*</span></label>
                        <input id="company-address" type="text" name="direccion" maxlength="255" autocomplete="street-address" value="<?= htmlspecialchars($values['direccion'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Calle, número, código postal y localidad" aria-invalid="<?= isset($fieldErrors['direccion']) ? 'true' : 'false' ?>" aria-describedby="company-direccion-error"<?= $firstInvalidField === 'direccion' ? ' autofocus' : '' ?> required>
                        <small id="company-direccion-error" data-field-error<?= isset($fieldErrors['direccion']) ? ' role="alert"' : '' ?>><?= htmlspecialchars($fieldErrors['direccion'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                    </div>
                </div>
            </fieldset>

            <div class="company-registration__actions">
                <p><span aria-hidden="true">*</span> Campos obligatorios</p>
                <button class="company-registration__submit" type="submit">Enviar solicitud</button>
            </div>
        </form>
        <div data-vue-island="AltaEmpresa" data-prop-csrf="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"></div>
        <p class="company-registration__login">¿Ya tienes una cuenta aprobada? <a href="index.php?action=login">Inicia sesión</a></p>
    <?php endif; ?>
</section>
