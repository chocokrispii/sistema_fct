<h2>Consultas y seguimiento</h2>
<?php if ($error !== ''): ?><p class="message message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="GET" action="index.php">
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
    <label>Inicio desde <input type="date" name="desde" value="<?= htmlspecialchars($filters['desde']?->format('Y-m-d') ?? '', ENT_QUOTES, 'UTF-8') ?>"></label>
    <label>Fin hasta <input type="date" name="hasta" value="<?= htmlspecialchars($filters['hasta']?->format('Y-m-d') ?? '', ENT_QUOTES, 'UTF-8') ?>"></label>
    <button type="submit">Filtrar</button>
</form>
<table>
    <thead><tr><th>Empresa</th><th>Ciclo</th><th>Periodo</th><th>Oferta</th><th>Alumno asignado</th><th>Prácticas</th></tr></thead>
    <tbody><?php foreach ($rows as $row): ?>
        <tr>
            <td><?= htmlspecialchars($row['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($row['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($row['fecha_inicio'] . ' – ' . $row['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($row['estado'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars(trim(($row['alumno_nombre'] ?? '') . ' ' . ($row['apellidos'] ?? '')), ENT_QUOTES, 'UTF-8') ?: '—' ?></td>
            <td><?= htmlspecialchars($row['estado_asignacion'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
    <?php endforeach; ?></tbody>
</table>
