<section class="student-registration" aria-labelledby="student-registration-title">
    <div class="student-registration__intro">
        <span class="student-registration__eyebrow">Área de alumnado</span>
        <h2 id="student-registration-title">Registra tu expediente</h2>
        <p>Completa tus datos para crear la cuenta y el expediente académico.</p>
    </div>

    <?php if ($error !== ''): ?>
        <p class="message message-error student-registration__error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <form class="student-registration__form" method="POST" action="index.php?action=registro-alumno">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">

        <fieldset class="student-registration__section">
            <legend>Acceso a la cuenta</legend>
            <p class="student-registration__section-help">Usarás estos datos para iniciar sesión.</p>

            <div class="student-registration__grid">
                <div class="student-registration__field">
                    <label for="student-email">Correo electrónico <span aria-hidden="true">*</span></label>
                    <input id="student-email" type="email" name="email" maxlength="100" autocomplete="email" value="<?= htmlspecialchars($values['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <div class="student-registration__field">
                    <label for="student-password">Contraseña <span aria-hidden="true">*</span></label>
                    <input id="student-password" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="student-password-help" required>
                    <small id="student-password-help">Debe tener entre 12 y 72 caracteres. Los espacios cuentan.</small>
                </div>
            </div>
        </fieldset>

        <fieldset class="student-registration__section">
            <legend>Datos personales</legend>
            <p class="student-registration__section-help">La fecha de nacimiento permite comprobar la edad y los requisitos de tutoría.</p>

            <div class="student-registration__grid">
                <div class="student-registration__field">
                    <label for="student-name">Nombre <span aria-hidden="true">*</span></label>
                    <input id="student-name" type="text" name="nombre" maxlength="50" autocomplete="given-name" value="<?= htmlspecialchars($values['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <div class="student-registration__field">
                    <label for="student-surname">Apellidos <span aria-hidden="true">*</span></label>
                    <input id="student-surname" type="text" name="apellidos" maxlength="100" autocomplete="family-name" value="<?= htmlspecialchars($values['apellidos'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <div class="student-registration__field">
                    <label for="student-dni">DNI/NIE <span aria-hidden="true">*</span></label>
                    <input id="student-dni" type="text" name="dni" maxlength="10" autocomplete="off" value="<?= htmlspecialchars($values['dni'] ?? '', ENT_QUOTES, 'UTF-8') ?>" aria-describedby="student-dni-help" required>
                    <small id="student-dni-help">DNI o NIE español con letra de control.</small>
                </div>

                <div class="student-registration__field">
                    <label for="student-nss">Número de Seguridad Social <span aria-hidden="true">*</span></label>
                    <input id="student-nss" type="text" name="nss" maxlength="20" autocomplete="off" value="<?= htmlspecialchars($values['nss'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <div class="student-registration__field">
                    <label for="student-birth-date">Fecha de nacimiento <span aria-hidden="true">*</span></label>
                    <input id="student-birth-date" type="date" name="fecha_nacimiento" autocomplete="bday" value="<?= htmlspecialchars($values['fecha_nacimiento'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
            </div>
        </fieldset>

        <fieldset class="student-registration__section">
            <legend>Contacto y ciclo formativo</legend>
            <p class="student-registration__section-help">La disponibilidad para prácticas se configura después de iniciar sesión.</p>

            <div class="student-registration__grid">
                <div class="student-registration__field">
                    <label for="student-phone">Teléfono <span aria-hidden="true">*</span></label>
                    <input id="student-phone" type="tel" name="telefono" maxlength="20" autocomplete="tel" pattern="(?:\+34|0034)?[\s().-]*[6789](?:[\s().-]*\d){8}" value="<?= htmlspecialchars($values['telefono'] ?? '', ENT_QUOTES, 'UTF-8') ?>" aria-describedby="student-phone-help" required>
                    <small id="student-phone-help">Teléfono español de 9 cifras; se acepta el prefijo +34.</small>
                </div>

                <div class="student-registration__field">
                    <label for="student-address">Dirección <span aria-hidden="true">*</span></label>
                    <input id="student-address" type="text" name="direccion" maxlength="255" autocomplete="street-address" value="<?= htmlspecialchars($values['direccion'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <div class="student-registration__field">
                    <label for="student-postcode">Código postal <span aria-hidden="true">*</span></label>
                    <input id="student-postcode" type="text" name="codigo_postal" inputmode="numeric" maxlength="5" pattern="(?:0[1-9]|[1-4][0-9]|5[0-2])[0-9]{3}" autocomplete="postal-code" value="<?= htmlspecialchars($values['codigo_postal'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <div class="student-registration__field student-registration__field--full">
                    <label for="student-specialty">Ciclo formativo <span aria-hidden="true">*</span></label>
                    <?php $especialidadSelectId = 'student-specialty'; ?>
                    <?php $especialidadSeleccionada = $values['especialidad_id'] ?? ''; ?>
                    <?php require __DIR__ . '/../partials/select_especialidades.php'; ?>
                    <?php unset($especialidadSelectId, $especialidadSeleccionada); ?>
                </div>
            </div>
        </fieldset>

        <fieldset class="student-registration__section">
            <legend>Datos del tutor legal</legend>
            <p class="student-registration__section-help">Obligatorios si el alumno es menor de 18 años. La edad se calcula a partir de la fecha de nacimiento.</p>

            <div class="student-registration__grid">
                <div class="student-registration__field">
                    <label for="student-guardian-name">Nombre completo</label>
                    <input id="student-guardian-name" type="text" name="tutor_nombre" maxlength="100" value="<?= htmlspecialchars($values['tutor_nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="student-registration__field">
                    <label for="student-guardian-dni">DNI/NIE</label>
                    <input id="student-guardian-dni" type="text" name="tutor_dni" maxlength="10" autocomplete="off" value="<?= htmlspecialchars($values['tutor_dni'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="student-registration__field">
                    <label for="student-guardian-nss">Número de Seguridad Social</label>
                    <input id="student-guardian-nss" type="text" name="tutor_nss" maxlength="20" autocomplete="off" value="<?= htmlspecialchars($values['tutor_nss'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="student-registration__field">
                    <label for="student-guardian-email">Correo electrónico</label>
                    <input id="student-guardian-email" type="email" name="tutor_correo" maxlength="100" autocomplete="email" value="<?= htmlspecialchars($values['tutor_correo'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="student-registration__field">
                    <label for="student-guardian-phone">Teléfono</label>
                    <input id="student-guardian-phone" type="tel" name="tutor_telefono" maxlength="20" autocomplete="tel" value="<?= htmlspecialchars($values['tutor_telefono'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="student-registration__field">
                    <label for="student-guardian-address">Dirección</label>
                    <input id="student-guardian-address" type="text" name="tutor_direccion" maxlength="255" autocomplete="street-address" value="<?= htmlspecialchars($values['tutor_direccion'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>
        </fieldset>

        <div class="student-registration__actions">
            <p><span aria-hidden="true">*</span> Campos obligatorios</p>
            <button class="student-registration__submit" type="submit">Crear cuenta de alumno</button>
        </div>
    </form>
</section>
