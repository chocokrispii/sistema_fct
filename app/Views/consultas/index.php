<h2>Consultas y seguimiento</h2>
<?php if ($error !== ''): ?><p class="message message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form class="page-form" method="GET" action="index.php">
    <input type="hidden" name="action" value="consultas">
    <label>Ciclo
        <select name="especialidad_id">
            <option value="">Todos</option>
            <?php foreach ($especialidades as $especialidad): ?>
                <option value="<?= (int)$especialidad['id'] ?>" <?= (string)$filters['especialidad'] === (string)$especialidad['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($especialidad['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Estado de oferta
        <select name="estado">
            <option value="">Todos</option>
            <?php foreach (['Pendiente', 'Aprobada', 'Rechazada'] as $status): ?>
                <option value="<?= $status ?>" <?= $filters['estado'] === $status ? 'selected' : '' ?>><?= $status ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Inicio desde <input type="date" name="desde" value="<?= htmlspecialchars($filters['desde'], ENT_QUOTES, 'UTF-8') ?>"></label>
    <label>Fin hasta <input type="date" name="hasta" value="<?= htmlspecialchars($filters['hasta'], ENT_QUOTES, 'UTF-8') ?>"></label>
    <button type="submit">Filtrar</button>
</form>
<?php if ($totalFilas > 0): ?>
    <p role="status">Mostrando <?= (int)$primerResultado ?>-<?= (int)$ultimoResultado ?> de <?= (int)$totalFilas ?> resultados</p>
<?php else: ?>
    <p role="status">No se encontraron resultados.</p>
<?php endif; ?>
<table>
    <caption>Resultados de consultas y seguimiento</caption>
    <thead><tr><th scope="col">Empresa</th><th scope="col">Ciclo</th><th scope="col">Periodo</th><th scope="col">Turno</th><th scope="col">Plazas</th><th scope="col">Oferta</th><th scope="col">Alumno asignado</th><th scope="col">Prácticas</th></tr></thead>
    <tbody><?php foreach ($rows as $row): ?>
        <tr>
            <td><?= htmlspecialchars($row['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($row['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($row['fecha_inicio'] . ' – ' . $row['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($row['turno'] === 'M' ? 'Mañana' : ($row['turno'] === 'T' ? 'Tarde' : 'Indiferente'), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= (int)$row['plazas_cuantas'] ?></td>
            <td><?= htmlspecialchars($row['estado'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars(trim(($row['alumno_nombre'] ?? '') . ' ' . ($row['apellidos'] ?? '')), ENT_QUOTES, 'UTF-8') ?: '—' ?></td>
            <td><?= htmlspecialchars($row['estado_asignacion'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
    <?php endforeach; ?></tbody>
</table>
<?php if ($totalPaginas > 1): ?>
    <?php
    $paginationParameters = [
        'action' => 'consultas',
        'especialidad_id' => $filters['especialidad'] === '' ? null : $filters['especialidad'],
        'estado' => $filters['estado'] === '' ? null : $filters['estado'],
        'desde' => $filters['desde'] === '' ? null : $filters['desde'],
        'hasta' => $filters['hasta'] === '' ? null : $filters['hasta'],
    ];
    ?>
    <nav aria-label="Paginación de consultas">
        <?php if ($pagina > 1): ?>
            <a rel="prev" href="<?= htmlspecialchars('index.php?' . http_build_query($paginationParameters + ['pagina' => $pagina - 1]), ENT_QUOTES, 'UTF-8') ?>">Anterior</a>
        <?php endif; ?>
        <span>Página <?= (int)$pagina ?> de <?= (int)$totalPaginas ?></span>
        <?php if ($pagina < $totalPaginas): ?>
            <a rel="next" href="<?= htmlspecialchars('index.php?' . http_build_query($paginationParameters + ['pagina' => $pagina + 1]), ENT_QUOTES, 'UTF-8') ?>">Siguiente</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
