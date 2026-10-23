<section class="company-requests" data-company-requests aria-labelledby="company-requests-title">
    <div class="company-requests__intro">
        <h2 id="company-requests-title">Solicitudes de empresa</h2>
        <p>Revisa la información y aprueba las empresas con el convenio marco firmado.</p>
    </div>

    <?php if ($aprobada): ?>
        <p class="message message-success" role="status">La empresa se ha aprobado correctamente.</p>
    <?php endif; ?>

    <form class="company-requests__filter" method="GET" action="index.php">
        <input type="hidden" name="action" value="solicitudes-empresa">
        <label for="company-request-state">Estado
            <select id="company-request-state" name="estado">
                <?php foreach ([
                    'Pendiente_Aprobacion' => 'Pendientes de aprobación',
                    'Aprobada' => 'Aprobadas',
                    'Rechazada' => 'Rechazadas',
                ] as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $estado === $value ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">Filtrar</button>
    </form>

    <?php if ($empresas === []): ?>
        <p class="empty-state">No hay solicitudes con este estado.</p>
    <?php else: ?>
        <div class="table-responsive" role="region" aria-label="Solicitudes de empresa" tabindex="0">
            <table>
                <thead>
                    <tr>
                        <th>Razón social</th>
                        <th>Nombre comercial</th>
                        <th>CIF / NIF</th>
                        <th>Representante</th>
                        <th>DNI / NIE</th>
                        <th>Correo</th>
                        <th>Teléfono</th>
                        <th>Dirección</th>
                        <th>Estado</th>
                        <th>Convenio marco</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($empresas as $empresa): ?>
                        <tr>
                            <td><?= htmlspecialchars($empresa['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($empresa['nombre_comercial'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($empresa['cif_empresa'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($empresa['representante_legal'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($empresa['dni_representante'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($empresa['email'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($empresa['telefono'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($empresa['direccion_completa'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="status-badge <?= $empresa['estado'] === 'Aprobada' ? 'status-badge--approved' : ($empresa['estado'] === 'Rechazada' ? 'status-badge--rejected' : 'status-badge--pending') ?>"><?= htmlspecialchars($empresa['estado'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td>
                                <?= htmlspecialchars($empresa['numero_convenio'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                <?php if (!empty($empresa['pdf_convenio_path'])): ?><span class="visually-hidden">PDF firmado almacenado de forma privada.</span><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($empresa['estado'] === 'Pendiente_Aprobacion'): ?>
                                    <a
                                        href="index.php?action=formulario-aprobar-empresa&amp;id=<?= (int)$empresa['id'] ?>"
                                        data-company-approval-open
                                        data-company-id="<?= (int)$empresa['id'] ?>"
                                        data-company-name="<?= htmlspecialchars($empresa['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?>"
                                    >Revisar y aprobar</a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div data-vue-island="CompanyApproval" data-prop-csrf="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"></div>
</section>
