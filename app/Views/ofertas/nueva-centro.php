<h2>Crear una oferta de prácticas</h2>
<p>La oferta se enviará a revisión y requiere un convenio aprobado para la empresa, el ciclo y el periodo seleccionados.</p>
<?php if (isset($_GET['creada'])): ?>
    <p class="message message-success" role="status">La oferta se ha registrado y está pendiente de revisión.</p>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <p class="message message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if ($empresas === []): ?>
    <p role="status">Todavía no hay empresas registradas para asociar a una oferta.</p>
<?php elseif ($especialidades === []): ?>
    <p role="status">No hay ciclos formativos disponibles para crear una oferta.</p>
<?php else: ?>
    <form class="page-form page-form--narrow" method="POST" action="index.php?action=nueva-oferta">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
        <label for="oferta-titulo">Nombre de la oferta</label>
        <input id="oferta-titulo" type="text" name="titulo" maxlength="150" value="<?= htmlspecialchars(is_string($_POST['titulo'] ?? null) ? $_POST['titulo'] : '', ENT_QUOTES, 'UTF-8') ?>" required>

        <label for="oferta-empresa">Empresa</label>
        <select id="oferta-empresa" name="empresa_id" required>
            <option value="">Selecciona una empresa</option>
            <?php foreach ($empresas as $empresa): ?>
                <option value="<?= (int)$empresa['id'] ?>" <?= (string)($empresa['id']) === (string)($_POST['empresa_id'] ?? '') ? 'selected' : '' ?>>
                    <?= htmlspecialchars($empresa['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="oferta-lugar">Lugar de la oferta</label>
        <input id="oferta-lugar" type="text" name="lugar" maxlength="255" value="<?= htmlspecialchars(is_string($_POST['lugar'] ?? null) ? $_POST['lugar'] : '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Ciudad, dirección o sede" required>

        <label for="oferta-ciclo">Ciclo formativo</label>
        <select id="oferta-ciclo" name="especialidad_id" required>
            <option value="">Selecciona un ciclo formativo</option>
            <?php foreach ($especialidades as $especialidad): ?>
                <option value="<?= (int)$especialidad['id'] ?>" <?= (string)($especialidad['id']) === (string)($_POST['especialidad_id'] ?? '') ? 'selected' : '' ?>>
                    <?= htmlspecialchars($especialidad['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="oferta-plazas">Número de vacantes</label>
        <input id="oferta-plazas" type="number" name="plazas" min="1" max="100" value="<?= htmlspecialchars(is_string($_POST['plazas'] ?? null) ? $_POST['plazas'] : '1', ENT_QUOTES, 'UTF-8') ?>" required>

        <label for="oferta-turno">Turno</label>
        <select id="oferta-turno" name="turno" required>
            <?php $turnoActual = is_string($_POST['turno'] ?? null) ? $_POST['turno'] : 'Indiferente'; ?>
            <option value="Indiferente" <?= $turnoActual === 'Indiferente' ? 'selected' : '' ?>>Indiferente</option>
            <option value="M" <?= $turnoActual === 'M' ? 'selected' : '' ?>>Mañana</option>
            <option value="T" <?= $turnoActual === 'T' ? 'selected' : '' ?>>Tarde</option>
        </select>

        <label for="oferta-inicio">Fecha de inicio</label>
        <input id="oferta-inicio" type="date" name="fecha_inicio" value="<?= htmlspecialchars(is_string($_POST['fecha_inicio'] ?? null) ? $_POST['fecha_inicio'] : '', ENT_QUOTES, 'UTF-8') ?>" required>

        <label for="oferta-fin">Fecha de fin</label>
        <input id="oferta-fin" type="date" name="fecha_fin" value="<?= htmlspecialchars(is_string($_POST['fecha_fin'] ?? null) ? $_POST['fecha_fin'] : '', ENT_QUOTES, 'UTF-8') ?>" required>

        <button type="submit">Guardar oferta para revisión</button>
    </form>
<?php endif; ?>
