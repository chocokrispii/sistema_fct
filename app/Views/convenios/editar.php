<h2>Modificar convenio</h2>
<p>Al guardar, el convenio volverá a revisión.<?php if ($esPersonalCentro && $convenio['estado'] === 'Aprobado'): ?> El cambio de un convenio aprobado puede afectar a ofertas que dependan de su vigencia.<?php endif; ?></p>
<?php if ($convenio['estado'] === 'Rechazado' && is_string($convenio['motivo_revision']) && $convenio['motivo_revision'] !== ''): ?>
    <p class="message message-error">Motivo del rechazo: <?= htmlspecialchars($convenio['motivo_revision'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if ($error !== ''): ?><p class="message message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form class="page-form" method="POST" enctype="multipart/form-data" action="index.php?action=editar-convenio">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="convenio_id" value="<?= (int)$convenio['id'] ?>">
    <?php
    $numero = is_string($_POST['numero_convenio'] ?? null) ? $_POST['numero_convenio'] : $convenio['numero_convenio'];
    $especialidadActual = (string)($_POST['especialidad_id'] ?? $convenio['especialidad_id']);
    $sedeActual = (string)($_POST['sede_id'] ?? $convenio['sede_id'] ?? '');
    $fechaFirma = is_string($_POST['fecha_firma'] ?? null) ? $_POST['fecha_firma'] : $convenio['fecha_firma'];
    $fechaInicio = is_string($_POST['fecha_inicio'] ?? null) ? $_POST['fecha_inicio'] : $convenio['fecha_inicio'];
    $fechaFin = is_string($_POST['fecha_fin'] ?? null) ? $_POST['fecha_fin'] : $convenio['fecha_fin'];
    ?>
    <label for="convenio-numero">Número de convenio</label>
    <input id="convenio-numero" type="text" name="numero_convenio" maxlength="100" value="<?= htmlspecialchars($numero, ENT_QUOTES, 'UTF-8') ?>" required>
    <label for="convenio-especialidad">Ciclo formativo</label>
    <select id="convenio-especialidad" name="especialidad_id" required>
        <?php foreach ($especialidades as $especialidad): ?>
            <option value="<?= (int)$especialidad['id'] ?>" <?= (string)$especialidad['id'] === $especialidadActual ? 'selected' : '' ?>>
                <?= htmlspecialchars($especialidad['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?>
            </option>
        <?php endforeach; ?>
    </select>
    <label for="convenio-sede">Sede (opcional)</label>
    <select id="convenio-sede" name="sede_id">
        <option value="" <?= $sedeActual === '' ? 'selected' : '' ?>>Empresa completa</option>
        <?php foreach ($sedes as $sede): ?>
            <option value="<?= (int)$sede['id'] ?>" <?= (string)$sede['id'] === $sedeActual ? 'selected' : '' ?>>
                <?= htmlspecialchars($sede['nombre'], ENT_QUOTES, 'UTF-8') ?>
            </option>
        <?php endforeach; ?>
    </select>
    <label for="convenio-firma">Fecha de firma</label>
    <input id="convenio-firma" type="date" name="fecha_firma" value="<?= htmlspecialchars($fechaFirma, ENT_QUOTES, 'UTF-8') ?>" required>
    <label for="convenio-inicio">Válido desde</label>
    <input id="convenio-inicio" type="date" name="fecha_inicio" value="<?= htmlspecialchars($fechaInicio, ENT_QUOTES, 'UTF-8') ?>" required>
    <label for="convenio-fin">Válido hasta</label>
    <input id="convenio-fin" type="date" name="fecha_fin" value="<?= htmlspecialchars($fechaFin, ENT_QUOTES, 'UTF-8') ?>" required>
    <p>PDF actual: <a href="index.php?action=descargar-convenio&id=<?= (int)$convenio['id'] ?>">Descargar convenio</a></p>
    <label for="convenio-archivo">Sustituir PDF (opcional, máximo 10 MB)</label>
    <input id="convenio-archivo" type="file" name="archivo" accept="application/pdf,.pdf">
    <div class="form-actions">
        <button type="submit">Guardar cambios y enviar a revisión</button>
        <a class="button secondary" href="index.php?action=<?= htmlspecialchars($volverA, ENT_QUOTES, 'UTF-8') ?>">Cancelar</a>
    </div>
</form>
