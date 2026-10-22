<h2>Gestión de ciclos formativos</h2>
<p>Añade los ciclos que ofrece el centro. Los nuevos ciclos estarán disponibles al momento en convenios, ofertas de FCT, inscripciones y emparejamientos.</p>
<p>Retirar un ciclo lo oculta de nuevas selecciones, pero conserva los datos y registros anteriores. Puedes reactivarlo cuando vuelva a ofertarse.</p>

<?php if ($error !== ''): ?>
    <p class="message message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if ($creado): ?>
    <p class="message message-success" role="status">Ciclo formativo añadido correctamente.</p>
<?php endif; ?>
<?php if ($retirado): ?>
    <p class="message message-success" role="status">Ciclo retirado del catálogo. Los datos históricos se han conservado.</p>
<?php endif; ?>
<?php if ($reactivado): ?>
    <p class="message message-success" role="status">Ciclo reactivado y disponible de nuevo.</p>
<?php endif; ?>

<form class="page-form page-form--narrow" method="POST" action="index.php?action=ciclos">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
    <label for="cycle-name">Nombre del ciclo formativo</label>
    <input id="cycle-name" type="text" name="nombre_especialidad" maxlength="150" required>
    <small>Escribe el nombre oficial del ciclo. Si ya existe, no se duplicará.</small>
    <button type="submit">Añadir ciclo</button>
</form>

<h3>Ciclos del catálogo</h3>
<table>
    <thead><tr><th>Nombre del ciclo formativo</th><th>Estado</th><th>Acciones</th></tr></thead>
    <tbody>
        <?php foreach ($especialidades as $especialidad): ?>
            <tr>
                <td><?= htmlspecialchars($especialidad['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= (int)$especialidad['activo'] === 1 ? 'Disponible' : 'Retirado' ?></td>
                <td>
                    <form class="action-form" method="POST" action="index.php?action=<?= (int)$especialidad['activo'] === 1 ? 'retirar-ciclo' : 'reactivar-ciclo' ?>">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="especialidad_id" value="<?= (int)$especialidad['id'] ?>">
                        <button type="submit"><?= (int)$especialidad['activo'] === 1 ? 'Retirar del catálogo' : 'Reactivar' ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
