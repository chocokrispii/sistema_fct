<h2>Empresas registradas</h2>
<p>Consulta las empresas y el estado de sus cuentas en el sistema.</p>
<?php if ($empresas === []): ?>
    <p>No hay empresas registradas.</p>
<?php else: ?>
    <div class="table-responsive" role="region" aria-label="Empresas registradas" tabindex="0">
    <table>
        <thead>
            <tr><th>Empresa</th><th>CIF</th><th>Correo de la cuenta</th><th>Aprobación</th><th>Cuenta</th></tr>
        </thead>
        <tbody>
            <?php foreach ($empresas as $empresa): ?>
                <tr>
                    <td><?= htmlspecialchars($empresa['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($empresa['cif_empresa'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($empresa['email'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($empresa['estado'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int)$empresa['activo'] === 1 ? 'Activa' : 'Desactivada' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
