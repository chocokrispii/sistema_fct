<section class="assignments-page" aria-labelledby="agreements-archive-title">
    <div class="assignments-page__intro">
        <span class="student-registration__eyebrow">Solo administración</span>
        <h2 id="agreements-archive-title">Archivo de convenios</h2>
        <p>Todos los convenios permanecen registrados aquí, incluidos los aprobados y rechazados. Los PDF se conservan en almacenamiento privado y se descargan mediante el acceso autorizado.</p>
    </div>

    <?php if ($convenios === []): ?>
        <p>No hay convenios registrados.</p>
    <?php else: ?>
        <div class="assignments-table-wrap">
            <table class="assignments-table">
                <thead>
                    <tr>
                        <th>Convenio</th>
                        <th>Empresa y ciclo</th>
                        <th>Vigencia</th>
                        <th>Estado y revisión</th>
                        <th>Documento</th>
                        <th>Registro de actividad</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($convenios as $item): ?>
                        <?php $convenioId = (int)$item['id']; ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($item['numero_convenio'], ENT_QUOTES, 'UTF-8') ?>
                                <small>Firmado <?= htmlspecialchars($item['fecha_firma'], ENT_QUOTES, 'UTF-8') ?></small>
                            </td>
                            <td>
                                <?= htmlspecialchars($item['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?>
                                <small><?= htmlspecialchars($item['nombre_especialidad'] ?? 'Sin clasificar', ENT_QUOTES, 'UTF-8') ?><?= $item['nombre_sede'] ? ' · ' . htmlspecialchars($item['nombre_sede'], ENT_QUOTES, 'UTF-8') : '' ?></small>
                            </td>
                            <td><?= htmlspecialchars($item['fecha_inicio'] . ' – ' . $item['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <strong><?= htmlspecialchars($item['estado'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if ($item['revisado_en'] !== null): ?>
                                    <small>Revisado <?= htmlspecialchars($item['revisado_en'], ENT_QUOTES, 'UTF-8') ?> por <?= htmlspecialchars($item['nombre_revisor'], ENT_QUOTES, 'UTF-8') ?></small>
                                <?php endif; ?>
                                <?php if ($item['motivo_revision'] !== null && $item['motivo_revision'] !== ''): ?>
                                    <small><?= htmlspecialchars($item['motivo_revision'], ENT_QUOTES, 'UTF-8') ?></small>
                                <?php endif; ?>
                            </td>
                            <td><a href="index.php?action=descargar-convenio&id=<?= $convenioId ?>">Descargar PDF</a></td>
                            <td>
                                <?php $eventos = $eventosPorConvenio[$convenioId] ?? []; ?>
                                <?php if ($eventos === []): ?>
                                    <small>Sin eventos de auditoría registrados.</small>
                                <?php else: ?>
                                    <details>
                                        <summary><?= count($eventos) ?> <?= count($eventos) === 1 ? 'evento' : 'eventos' ?></summary>
                                        <ul>
                                            <?php foreach ($eventos as $evento): ?>
                                                <li>
                                                    <strong><?= htmlspecialchars($evento['evento'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                    <small><?= htmlspecialchars($evento['creado_en'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($evento['actor'], ENT_QUOTES, 'UTF-8') ?></small>
                                                    <?php if ($evento['detalle'] !== null && $evento['detalle'] !== ''): ?>
                                                        <small><?= htmlspecialchars($evento['detalle'], ENT_QUOTES, 'UTF-8') ?></small>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </details>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
