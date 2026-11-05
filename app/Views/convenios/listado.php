<?php

declare(strict_types=1);

$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$tabAction = 'index.php?action=gestionar-convenios';
$tabs = [
    'pendiente_revision' => 'En Revisión',
    'aprobado' => 'Aprobadas',
    'rechazado' => 'Rechazadas',
];
if ($esAdministrador) {
    $tabs['archivado'] = 'Archivados';
}
?>
<section class="agreement-management" aria-labelledby="agreement-management-title">
    <header class="agreement-management__intro">
        <span class="student-registration__eyebrow">Gestión de convenios</span>
        <h1 id="agreement-management-title">Revisión e histórico de convenios</h1>
        <p>Consulta, filtra y gestiona en una única vista los documentos enviados por las empresas.</p>
    </header>

    <nav class="agreement-tabs" aria-label="Estados de convenios">
        <?php foreach ($tabs as $tabState => $tabLabel): ?>
            <?php
            $tabQuery = [
                'action' => 'gestionar-convenios',
                'estado' => $tabState,
                'nif' => $filtros['nif'],
                'fecha_desde' => $filtros['fecha_desde'],
                'fecha_hasta' => $filtros['fecha_hasta'],
                'limit' => $filtros['limit'],
            ];
            ?>
            <a href="index.php?<?= $escape(http_build_query($tabQuery)) ?>"
               <?= $filtros['estado'] === $tabState ? 'aria-current="page"' : '' ?>>
                <?= $escape($tabLabel) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <form class="agreement-filters" method="GET" action="index.php">
        <input type="hidden" name="action" value="gestionar-convenios">
        <input type="hidden" name="estado" value="<?= $escape($filtros['estado']) ?>">
        <label>NIF de empresa
            <input type="search" name="nif" maxlength="20" value="<?= $escape($filtros['nif']) ?>" placeholder="Ej. B12345678">
        </label>
        <label>Desde
            <input type="date" name="fecha_desde" value="<?= $escape($filtros['fecha_desde']) ?>">
        </label>
        <label>Hasta
            <input type="date" name="fecha_hasta" value="<?= $escape($filtros['fecha_hasta']) ?>">
        </label>
        <label>Filas por página
            <select name="limit">
                <?php foreach ([10, 20, 50, 100] as $limitOption): ?>
                    <option value="<?= $limitOption ?>" <?= (int)$filtros['limit'] === $limitOption ? 'selected' : '' ?>><?= $limitOption ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">Aplicar filtros</button>
        <a class="agreement-filters__reset" href="<?= $escape($tabAction . '&estado=' . rawurlencode($filtros['estado'])) ?>">Limpiar</a>
    </form>

    <?php if (($firmaResultado ?? '') !== ''): ?>
        <p class="message message-<?= $escape($firmaResultadoTipo ?? 'success') ?>" role="<?= ($firmaResultadoTipo ?? '') === 'error' ? 'alert' : 'status' ?>">
            <?= $escape($firmaResultado) ?>
        </p>
    <?php endif; ?>
    <?php if ($signatureMode === 'test'): ?>
        <p>La firma real está desactivada en este entorno. <a href="index.php?action=probar-autofirma">Probar AutoFirma sin aprobar convenios</a>.</p>
    <?php elseif ($signatureMode === 'disabled'): ?>
        <p>La firma de convenios está desactivada en este entorno.</p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p class="message message-error" role="alert"><?= $escape($error) ?></p>
    <?php elseif ($convenios === []): ?>
        <p class="agreement-results" role="status">0 convenios encontrados</p>
        <p class="agreement-empty">No hay convenios en este estado</p>
    <?php else: ?>
        <p class="agreement-results" role="status">
            <?= (int)$paginacion['total'] ?> <?= (int)$paginacion['total'] === 1 ? 'convenio encontrado' : 'convenios encontrados' ?>
        </p>
        <div class="assignments-table-wrap">
            <table class="assignments-table agreement-table">
                <thead>
                    <tr>
                        <th>Convenio</th>
                        <th>Empresa</th>
                        <th>Vigencia</th>
                        <th>Estado y revisión</th>
                        <th>Documento y acciones</th>
                        <th>Actividad</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($convenios as $item): ?>
                        <?php
                        $convenioId = (int)$item['id'];
                        $stateClass = match ($item['estado']) {
                            'Pendiente' => 'pending',
                            'Aprobado' => 'approved',
                            'Rechazado' => 'rejected',
                            'Archivado' => 'archived',
                            default => 'unknown',
                        };
                        ?>
                        <tr>
                            <td>
                                <strong><?= $escape($item['numero_convenio']) ?></strong>
                                <small>Firmado <?= $escape($item['fecha_firma']) ?></small>
                            </td>
                            <td>
                                <?= $escape($item['nombre_empresa']) ?>
                                <small>NIF <?= $escape($item['cif_empresa']) ?></small>
                                <small><?= $escape($item['nombre_especialidad'] ?? 'Sin clasificar') ?><?= !empty($item['nombre_sede']) ? ' · ' . $escape($item['nombre_sede']) : '' ?></small>
                            </td>
                            <td><?= $escape($item['fecha_inicio'] . ' – ' . $item['fecha_fin']) ?></td>
                            <td>
                                <span class="agreement-status agreement-status--<?= $stateClass ?>"><?= $escape($item['estado']) ?></span>
                                <?php if ($item['revisado_en'] !== null): ?>
                                    <small>Revisado <?= $escape($item['revisado_en']) ?> por <?= $escape($item['nombre_revisor']) ?></small>
                                <?php endif; ?>
                                <?php if ($item['motivo_revision'] !== null && $item['motivo_revision'] !== ''): ?>
                                    <small><?= $escape($item['motivo_revision']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="agreement-actions">
                                <?php if ($esAdministrador): ?>
                                    <a href="index.php?action=descargar-convenio&amp;id=<?= $convenioId ?>">Descargar PDF original</a>
                                    <?php if (
                                        $item['estado'] === 'Aprobado'
                                        && ($item['firma_estado'] ?? '') === 'FIRMADO_Y_APROBADO'
                                        && !empty($item['documento_firmado_path'])
                                    ): ?>
                                        <a href="index.php?action=descargar-convenio-firmado&amp;id=<?= $convenioId ?>">Descargar PDF firmado</a>
                                    <?php endif; ?>
                                <?php elseif (!$puedeFirmar): ?>
                                    <span>Descarga reservada a administración</span>
                                <?php endif; ?>
                                <?php if ($puedeFirmar && $item['estado'] === 'Pendiente' && in_array($signatureMode, ['autofirma', 'mock'], true)): ?>
                                    <form method="POST" enctype="multipart/form-data" action="index.php?action=<?= $signatureMode === 'mock' ? 'firmar-convenio-mock' : 'firmar-convenio' ?>">
                                        <input type="hidden" name="_csrf" value="<?= $escape(CsrfMiddleware::token()) ?>">
                                        <input type="hidden" name="convenio_id" value="<?= $convenioId ?>">
                                        <?php if ($signatureMode === 'autofirma'): ?>
                                            <label>Si la firma automática no está disponible, adjunta el PDF firmado con AutoFirma
                                                <input name="documento_firmado" type="file" accept="application/pdf,.pdf">
                                            </label>
                                        <?php else: ?>
                                            <p>Firma local de desarrollo con certificado de prueba. No tiene validez contractual.</p>
                                        <?php endif; ?>
                                        <div data-autofirma-island
                                             data-vue-island="AutoFirmaAgreementSignature"
                                             data-prop-csrf="<?= $escape(CsrfMiddleware::token()) ?>"
                                             data-prop-convenio-id="<?= $convenioId ?>"
                                             data-prop-mode="<?= $escape($signatureMode) ?>"></div>
                                        <button type="submit" value="autofirma"><?= $signatureMode === 'mock' ? 'Firmar con certificado local de prueba' : 'Revisar y firmar con AutoFirma' ?></button>
                                        <?php if ($signatureMode === 'autofirma'): ?>
                                            <button class="button-secondary" type="submit" name="modo_firma" value="manual">Enviar PDF firmado manualmente</button>
                                        <?php endif; ?>
                                    </form>
                                <?php elseif ($puedeFirmar && $item['estado'] === 'Pendiente' && $signatureMode === 'test'): ?>
                                    <p>Usa la prueba de AutoFirma; no aprobará este convenio.</p>
                                <?php endif; ?>
                                <?php if ($puedeResolver && $item['estado'] === 'Pendiente'): ?>
                                    <form method="POST" action="index.php?action=rechazar-convenio">
                                        <input type="hidden" name="_csrf" value="<?= $escape(CsrfMiddleware::token()) ?>">
                                        <input type="hidden" name="convenio_id" value="<?= $convenioId ?>">
                                        <label>Motivo del rechazo
                                            <input name="motivo" maxlength="500" required>
                                        </label>
                                        <button class="button-secondary" type="submit">Rechazar</button>
                                    </form>
                                <?php elseif ($puedeResolver && in_array($item['estado'], ['Aprobado', 'Rechazado'], true)): ?>
                                    <form method="POST" action="index.php?action=archivar-convenio">
                                        <input type="hidden" name="_csrf" value="<?= $escape(CsrfMiddleware::token()) ?>">
                                        <input type="hidden" name="convenio_id" value="<?= $convenioId ?>">
                                        <button class="button-secondary" type="submit">Archivar</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $eventos = $eventosPorConvenio[$convenioId] ?? []; ?>
                                <?php if ($eventos === []): ?>
                                    <small>Sin eventos registrados.</small>
                                <?php else: ?>
                                    <details>
                                        <summary><?= count($eventos) ?> <?= count($eventos) === 1 ? 'evento' : 'eventos' ?></summary>
                                        <ul>
                                            <?php foreach ($eventos as $evento): ?>
                                                <li>
                                                    <strong><?= $escape($evento['evento']) ?></strong>
                                                    <small><?= $escape($evento['creado_en']) ?> · <?= $escape($evento['actor']) ?></small>
                                                    <?php if (!empty($evento['detalle'])): ?><small><?= $escape($evento['detalle']) ?></small><?php endif; ?>
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
        <?php if ($paginacion['pages'] > 1): ?>
            <nav class="agreement-pagination" aria-label="Paginación de convenios">
                <?php if ($paginacion['page'] > 1): ?>
                    <?php $previous = array_merge($_GET, ['page' => $paginacion['page'] - 1]); ?>
                    <a href="index.php?<?= $escape(http_build_query($previous)) ?>">Anterior</a>
                <?php endif; ?>
                <span>Página <?= (int)$paginacion['page'] ?> de <?= (int)$paginacion['pages'] ?></span>
                <?php if ($paginacion['page'] < $paginacion['pages']): ?>
                    <?php $next = array_merge($_GET, ['page' => $paginacion['page'] + 1]); ?>
                    <a href="index.php?<?= $escape(http_build_query($next)) ?>">Siguiente</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
