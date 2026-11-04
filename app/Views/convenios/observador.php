<section class="assignments-page" aria-labelledby="agreements-audit-title">
    <div class="assignments-page__intro">
        <span class="student-registration__eyebrow">Auditoría de solo lectura</span>
        <h2 id="agreements-audit-title">Convenios registrados</h2>
        <p>Consulta empresas, ciclos, vigencias y estado. Los documentos PDF no están disponibles para este perfil.</p>
    </div>
    <?php if ($convenios === []): ?>
        <p>No hay convenios registrados.</p>
    <?php else: ?>
        <div class="assignments-table-wrap">
            <table class="assignments-table">
                <thead><tr><th>Empresa</th><th>Ciclo</th><th>Fecha de firma</th><th>Vigencia</th><th>Estado</th></tr></thead>
                <tbody><?php foreach ($convenios as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($item['nombre_especialidad'] ?? 'Sin clasificar', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(EuropeanDate::format($item['fecha_firma']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= EuropeanDate::periodBlock($item['fecha_inicio'], $item['fecha_fin']) ?></td>
                        <td><?= htmlspecialchars($item['estado'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
