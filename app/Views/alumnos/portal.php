<?php
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$st = static fn(string $d): string => '<svg class="fct-ico fct-ico--stroke" viewBox="0 0 24 24" aria-hidden="true"><path d="' . $d . '"/></svg>';
$fl = static fn(string $d): string => '<svg class="fct-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="' . $d . '"/></svg>';
$icoMail = 'M3 6h18v12H3zM3 7l9 7 9-7';
$icoId = 'M3 5h18v14H3zM9 11.5a2 2 0 1 0 0-.01M5.8 16c.5-1.2 1.8-1.8 3.2-1.8s2.7.6 3.2 1.8M14 10h4M14 14h4';
$icoCal = 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4';
$icoTel = 'M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z';
$icoPin = 'M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21zM12 12a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z';
$icoCap = 'M2 9l10-5 10 5-10 5zM6 11.5V16c0 1.5 3 3 6 3s6-1.5 6-3v-4.5M22 9v6';
$icoCalF = 'M7 2h2v2h6V2h2v2h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2zM5 9v11h14V9zm2 3h3v3H7z';
$icoMalF = 'M9 3h6a1 1 0 0 1 1 1v2h4a2 2 0 0 1 2 2v3H2V8a2 2 0 0 1 2-2h4V4a1 1 0 0 1 1-1zm1 2v1h4V5zM2 13h8v1.5h4V13h8v5a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2z';
$icoStarF = 'M12 2l3 6.3 6.9.9-5 4.8 1.2 6.9L12 17.6 5.9 20.9 7.1 14 2.1 9.2 9 8.3z';
$turnoTxt = static fn(string $t): string => $t === 'M' ? 'Mañana' : ($t === 'T' ? 'Tarde' : 'Indiferente');
?>
<section class="student-portal sp" aria-labelledby="student-portal-title">
    <div class="student-portal__intro">
        <span class="student-registration__eyebrow">Área de alumnado</span>
        <h2 id="student-portal-title">Mi expediente</h2>
        <p>Consulta tus datos, disponibilidad y prácticas registradas en el sistema.</p>
    </div>

    <?php if ($alumno === null): ?>
        <p class="message message-error" role="alert">No se ha encontrado un expediente asociado a esta cuenta. Contacta con el centro para revisarlo.</p>
    <?php else: ?>
        <?php
        $notasPublicadas = array_filter($calificaciones, static fn(array $item): bool => $item['nota'] !== null);
        $iniciales = mb_strtoupper(mb_substr((string)$alumno['nombre'], 0, 1, 'UTF-8') . mb_substr((string)$alumno['apellidos'], 0, 1, 'UTF-8'), 'UTF-8');
        $ciclo = $alumno['nombre_especialidad'] ?? 'Sin ciclo asignado';
        ?>
        <section class="sp-profile" aria-labelledby="student-portal-profile">
            <div class="sp-profile__banner">
                <span class="sp-avatar" aria-hidden="true"><?= $h($iniciales) ?></span>
                <div class="sp-profile__id">
                    <h3 id="student-portal-profile"><?= $h($alumno['nombre'] . ' ' . $alumno['apellidos']) ?></h3>
                    <p>Expediente académico</p>
                    <div class="sp-chips">
                        <span class="sp-chip"><?= $st($icoCap) ?><?= $h($ciclo) ?></span>
                        <span class="sp-chip"><?= $st($icoMail) ?><?= $h($alumno['correo']) ?></span>
                    </div>
                </div>
                <div class="sp-profile__actions">
                    <a class="sp-btn" href="index.php?action=perfil-alumno">Editar datos personales</a>
                    <a class="sp-btn sp-btn--ghost" href="index.php?action=disponibilidad">Gestionar disponibilidad</a>
                </div>
            </div>
            <dl class="sp-details">
                <div><span class="sp-details__icon sp-tone--blue"><?= $st($icoMail) ?></span><div><dt>Correo electrónico</dt><dd><?= $h($alumno['correo']) ?></dd></div></div>
                <div><span class="sp-details__icon sp-tone--purple"><?= $st($icoId) ?></span><div><dt><?= $h($alumno['tipo_documento'] === 'PASAPORTE' ? 'Pasaporte' : 'NIF / NIE / DNI') ?></dt><dd><?= $h($alumno['dni']) ?></dd></div></div>
                <div><span class="sp-details__icon sp-tone--orange"><?= $st($icoCal) ?></span><div><dt>Fecha de nacimiento</dt><dd><?= $h($alumno['fecha_nacimiento'] ?? 'No registrada') ?></dd></div></div>
                <div><span class="sp-details__icon sp-tone--green"><?= $st($icoTel) ?></span><div><dt>Teléfono</dt><dd><?= $h($alumno['telefono'] ?? 'No registrado') ?></dd></div></div>
                <div><span class="sp-details__icon sp-tone--blue"><?= $st($icoPin) ?></span><div><dt>Dirección</dt><dd><?= $h($alumno['direccion'] ?? 'No registrada') ?></dd></div></div>
                <div><span class="sp-details__icon sp-tone--purple"><?= $st($icoCap) ?></span><div><dt>Ciclo formativo</dt><dd><?= $h($ciclo) ?></dd></div></div>
            </dl>
        </section>

        <div class="sp-stats" aria-label="Resumen del expediente">
            <div class="sp-stat sp-tone--blue"><span class="sp-stat__icon"><?= $fl($icoCalF) ?></span><div><strong><?= count($disponibilidades) ?></strong><span>Periodos de disponibilidad</span></div></div>
            <div class="sp-stat sp-tone--green"><span class="sp-stat__icon"><?= $fl($icoMalF) ?></span><div><strong><?= (int)$totalAsignaciones ?></strong><span>Prácticas asignadas</span></div></div>
            <div class="sp-stat sp-tone--orange"><span class="sp-stat__icon"><?= $fl($icoStarF) ?></span><div><strong><?= count($notasPublicadas) ?></strong><span>Calificaciones publicadas</span></div></div>
        </div>

        <div class="sp-grid">
            <section class="sp-card" aria-labelledby="student-portal-availability">
                <div class="sp-card__head">
                    <span class="sp-card__icon sp-tone--blue"><?= $fl($icoCalF) ?></span>
                    <div>
                        <h3 id="student-portal-availability">Disponibilidad</h3>
                        <p>Periodos registrados para realizar prácticas.</p>
                    </div>
                </div>
                <?php if ($disponibilidades === []): ?>
                    <p class="sp-empty">Aún no has registrado tu disponibilidad.</p>
                    <a class="sp-card__cta" href="index.php?action=disponibilidad">Indicar disponibilidad</a>
                <?php else: ?>
                    <ul class="student-portal__list">
                        <?php foreach ($disponibilidades as $item): ?>
                            <li>
                                <strong><?= htmlspecialchars($item['fecha_inicio'] . ' – ' . $item['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <a class="sp-card__cta" href="index.php?action=disponibilidad">Actualizar disponibilidad</a>
                <?php endif; ?>
                <?php if ($horarioSemanal !== []): ?>
                    <h4 class="sp-card__sub">Horario semanal</h4>
                    <?php $dias = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo']; ?>
                    <ul class="student-portal__list">
                        <?php foreach ($horarioSemanal as $item): ?>
                            <li>
                                <strong><?= $h($dias[(int)$item['dia_semana']]) ?></strong>
                                <span><?= $h(substr($item['hora_inicio'], 0, 5) . '–' . substr($item['hora_fin'], 0, 5)) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="sp-card" aria-labelledby="student-portal-placements">
                <div class="sp-card__head">
                    <span class="sp-card__icon sp-tone--green"><?= $fl($icoMalF) ?></span>
                    <div>
                        <h3 id="student-portal-placements">Mis prácticas</h3>
                        <p>Asignaciones realizadas por el centro.</p>
                    </div>
                </div>
                <?php if (array_filter($asignaciones, static fn(array $item): bool => $item['estado'] === 'Asignada') !== []): ?>
                    <div data-vue-island="AssignedCompany"></div>
                <?php endif; ?>
                <?php if ($asignaciones === []): ?>
                    <p>No tienes prácticas asignadas actualmente.</p>
                    <p>Solo se muestran datos de empresa cuando el centro confirma una asignación.</p>
                <?php else: ?>
                    <ul class="student-portal__list">
                        <?php foreach ($asignaciones as $item): ?>
                            <?php $estadoModifier = $item['estado'] === 'Asignada' ? 'active' : ($item['estado'] === 'Cerrada' ? 'closed' : 'cancelled'); ?>
                            <li>
                                <strong><?= $h($item['nombre_empresa']) ?></strong>
                                <span class="status-badge status-badge--<?= $estadoModifier ?>"><?= $h($item['estado']) ?></span>
                                <span class="sp-list__meta"><?= $h($item['nombre_especialidad'] . ' · ' . $item['fecha_inicio'] . ' – ' . $item['fecha_fin']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php require __DIR__ . '/../partials/paginacion.php'; ?>
                    <a class="sp-card__cta" href="index.php?action=mis-asignaciones">Ver todas mis prácticas</a>
                <?php endif; ?>
            </section>

            <section class="sp-card" aria-labelledby="student-portal-grades">
                <div class="sp-card__head">
                    <span class="sp-card__icon sp-tone--orange"><?= $fl($icoStarF) ?></span>
                    <div>
                        <h3 id="student-portal-grades">Calificaciones</h3>
                        <p>Notas finales de las prácticas cerradas.</p>
                    </div>
                </div>
                <?php if ($notasPublicadas === []): ?>
                    <p class="sp-empty">Aún no hay calificaciones registradas para tus prácticas.</p>
                <?php else: ?>
                    <ul class="student-portal__list">
                        <?php foreach ($notasPublicadas as $item): ?>
                            <li>
                                <strong><?= $h($item['nombre_empresa'] . ' · ' . $item['nombre_especialidad']) ?></strong>
                                <span class="status-badge status-badge--active"><?= $h(number_format((float)$item['nota'], 2, ',', '') . ' / 10') ?></span>
                                <?php if ($item['observaciones'] !== null && $item['observaciones'] !== ''): ?><span class="sp-list__meta"><?= nl2br($h($item['observaciones'])) ?></span><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <a class="sp-card__cta" href="index.php?action=mis-calificaciones">Ver mis calificaciones</a>
            </section>
        </div>
    <?php endif; ?>
</section>
