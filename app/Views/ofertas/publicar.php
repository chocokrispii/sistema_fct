<?php if ($error !== ''): ?>
    <p class="message message-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if (isset($_GET['publicada'])): ?>
    <p class="message message-success">Oferta enviada a revisión.</p>
<?php endif; ?>

<h2>Publicar plazas — <?= htmlspecialchars($empresa['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></h2>
<p>La oferta solo se enviará si un convenio aprobado cubre todo el periodo indicado.</p>

<form method="POST" action="../../../public/index.php?action=publicar-oferta">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
    
    <label>Ciclo formativo</label>
    <?php require __DIR__ . '/../partials/select_especialidades.php'; ?>
    
    <label>Número de plazas 
        <input type="number" name="plazas" min="1" max="100" value="1" required>
    </label>
    
    <label>Turno
        <select name="turno" required>
            <option value="Indiferente">Indiferente</option>
            <option value="M">Mañana</option>
            <option value="T">Tarde</option>
        </select>
    </label>
    
    <label>Fecha de inicio 
        <input type="date" name="fecha_inicio" required>
    </label>
    
    <label>Fecha de fin 
        <input type="date" name="fecha_fin" required>
    </label>
    
    <button type="submit">Enviar oferta a revisión</button>
</form>

<h3>Mis ofertas</h3>
<?php if ($ofertas === []): ?>
    <p>Aún no has publicado ofertas.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Ciclo</th>
                <th>Plazas</th>
                <th>Turno</th>
                <th>Periodo</th>
                <th>Estado</th>
                <th>Observación</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($ofertas as $oferta): ?>
            <tr>
                <td><?= htmlspecialchars($oferta['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= (int)$oferta['plazas_cuantas'] ?></td>
                <td><?= htmlspecialchars($oferta['turno'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($oferta['fecha_inicio'] . ' – ' . $oferta['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($oferta['estado'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($oferta['motivo_revision'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>