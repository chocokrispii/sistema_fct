<section class="student-registration" aria-labelledby="student-profile-title">
    <div class="student-registration__intro">
        <span class="student-registration__eyebrow">Área de alumnado</span>
        <h2 id="student-profile-title">Editar mis datos personales</h2>
        <p>Actualiza tu contacto y datos personales. El ciclo formativo lo gestiona el centro para conservar la trazabilidad académica.</p>
    </div>

    <?php if ($guardado): ?><p class="message message-success" role="status">Tus datos se han actualizado.</p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="message message-error student-registration__error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>

    <form class="student-registration__form" method="POST" action="index.php?action=perfil-alumno" data-student-profile>
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
        <fieldset class="student-registration__section">
            <legend>Datos personales</legend>
            <div class="student-registration__grid">
                <div class="student-registration__field">
                    <label for="profile-name">Nombre</label>
                    <input id="profile-name" name="nombre" maxlength="50" autocomplete="given-name" value="<?= htmlspecialchars($values['nombre'], ENT_QUOTES, 'UTF-8') ?>" data-person-name aria-invalid="<?= isset($fieldErrors['nombre']) ? 'true' : 'false' ?>" required>
                    <small data-field-error><?= htmlspecialchars($fieldErrors['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                </div>
                <div class="student-registration__field">
                    <label for="profile-surname">Apellidos</label>
                    <input id="profile-surname" name="apellidos" maxlength="100" autocomplete="family-name" value="<?= htmlspecialchars($values['apellidos'], ENT_QUOTES, 'UTF-8') ?>" data-person-name aria-invalid="<?= isset($fieldErrors['apellidos']) ? 'true' : 'false' ?>" required>
                    <small data-field-error><?= htmlspecialchars($fieldErrors['apellidos'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                </div>
                <div class="student-registration__field">
                    <label for="profile-dni">DNI/NIE</label>
                    <input id="profile-dni" name="dni" maxlength="10" autocomplete="off" value="<?= htmlspecialchars($values['dni'], ENT_QUOTES, 'UTF-8') ?>" data-spanish-id aria-invalid="<?= isset($fieldErrors['dni']) ? 'true' : 'false' ?>" required>
                    <small>Se valida la letra de control del DNI/NIE.</small>
                    <small data-field-error><?= htmlspecialchars($fieldErrors['dni'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                </div>
                <div class="student-registration__field">
                    <label for="profile-nss">Número de Seguridad Social</label>
                    <input id="profile-nss" name="nss" maxlength="20" value="<?= htmlspecialchars($values['nss'], ENT_QUOTES, 'UTF-8') ?>" pattern="[A-Za-z0-9][A-Za-z0-9 .-]{4,19}" aria-invalid="<?= isset($fieldErrors['nss']) ? 'true' : 'false' ?>" required>
                    <small>De 5 a 20 caracteres alfanuméricos, espacios, puntos o guiones.</small>
                    <small data-field-error><?= htmlspecialchars($fieldErrors['nss'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                </div>
                <div class="student-registration__field">
                    <label for="profile-birth-date">Fecha de nacimiento</label>
                    <input id="profile-birth-date" type="date" name="fecha_nacimiento" autocomplete="bday" value="<?= htmlspecialchars($values['fecha_nacimiento'], ENT_QUOTES, 'UTF-8') ?>" aria-invalid="<?= isset($fieldErrors['fecha_nacimiento']) ? 'true' : 'false' ?>" required>
                    <small data-field-error><?= htmlspecialchars($fieldErrors['fecha_nacimiento'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                </div>
                <div class="student-registration__field">
                    <label for="profile-email">Correo electrónico</label>
                    <input id="profile-email" type="email" name="email" maxlength="100" autocomplete="email" value="<?= htmlspecialchars($values['email'], ENT_QUOTES, 'UTF-8') ?>" aria-invalid="<?= isset($fieldErrors['email']) ? 'true' : 'false' ?>" required>
                    <small data-field-error><?= htmlspecialchars($fieldErrors['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                </div>
                <div class="student-registration__field">
                    <label for="profile-phone">Teléfono</label>
                    <input id="profile-phone" type="tel" name="telefono" maxlength="20" autocomplete="tel" value="<?= htmlspecialchars($values['telefono'], ENT_QUOTES, 'UTF-8') ?>" data-spanish-phone aria-invalid="<?= isset($fieldErrors['telefono']) ? 'true' : 'false' ?>" required>
                    <small>Acepta 9 cifras españolas, con o sin prefijo +34.</small>
                    <small data-field-error><?= htmlspecialchars($fieldErrors['telefono'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                </div>
                <div class="student-registration__field">
                    <label for="profile-address">Dirección</label>
                    <input id="profile-address" name="direccion" maxlength="255" autocomplete="street-address" value="<?= htmlspecialchars($values['direccion'], ENT_QUOTES, 'UTF-8') ?>" aria-invalid="<?= isset($fieldErrors['direccion']) ? 'true' : 'false' ?>" required>
                    <small data-field-error><?= htmlspecialchars($fieldErrors['direccion'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                </div>
                <div class="student-registration__field">
                    <label for="profile-postcode">Código postal</label>
                    <input id="profile-postcode" name="codigo_postal" inputmode="numeric" maxlength="5" pattern="(?:0[1-9]|[1-4][0-9]|5[0-2])[0-9]{3}" autocomplete="postal-code" value="<?= htmlspecialchars($values['codigo_postal'], ENT_QUOTES, 'UTF-8') ?>" aria-invalid="<?= isset($fieldErrors['codigo_postal']) ? 'true' : 'false' ?>" required>
                    <small>Código postal español de cinco cifras (provincias 01–52).</small>
                    <small data-field-error><?= htmlspecialchars($fieldErrors['codigo_postal'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                </div>
            </div>
        </fieldset>
        <div class="student-registration__actions">
            <p>Los cambios se guardan en tu expediente privado.</p>
            <button class="student-registration__submit" type="submit">Guardar cambios</button>
            <a class="student-portal__text-action" href="index.php?action=portal-alumno">Cancelar</a>
        </div>
    </form>
</section>
<script src="assets/js/alumno-profile.js" defer></script>
