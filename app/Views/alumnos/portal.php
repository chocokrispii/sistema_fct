<section class="student-portal" aria-labelledby="student-portal-title">
    <div class="student-portal__intro">
        <span class="student-registration__eyebrow">Área de alumnado</span>
        <h2 id="student-portal-title">Mi expediente</h2>
        <p>Consulta tus datos, disponibilidad y prácticas registradas en el sistema.</p>
    </div>

    <?php if ($alumno === null): ?>
        <p class="message message-error" role="alert">No se ha encontrado un expediente asociado a esta cuenta. Contacta con el centro para revisarlo.</p>
    <?php else: ?>
        <section class="student-portal__card" aria-labelledby="student-portal-profile">
            <div class="student-portal__card-heading">
                <div>
                    <h3 id="student-portal-profile"><?= htmlspecialchars($alumno['nombre'] . ' ' . $alumno['apellidos'], ENT_QUOTES, 'UTF-8') ?></h3>
                    <p>Expediente académico</p>
                </div>
                <div class="student-portal__profile-actions">
                    <a class="student-portal__action" href="index.php?action=perfil-alumno">Editar datos personales</a>
                    <a class="student-portal__action" href="index.php?action=disponibilidad">Gestionar disponibilidad</a>
                </div>
            </div>
            <dl class="student-portal__details">
                <div><dt>Correo electrónico</dt><dd><?= htmlspecialchars($alumno['correo'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                <div><dt>DNI/NIE</dt><dd><?= htmlspecialchars($alumno['dni'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                <div><dt>Fecha de nacimiento</dt><dd><?= htmlspecialchars($alumno['fecha_nacimiento'] ?? 'No registrada', ENT_QUOTES, 'UTF-8') ?></dd></div>
                <div><dt>Teléfono</dt><dd><?= htmlspecialchars($alumno['telefono'] ?? 'No registrado', ENT_QUOTES, 'UTF-8') ?></dd></div>
                <div><dt>Dirección</dt><dd><?= htmlspecialchars($alumno['direccion'] ?? 'No registrada', ENT_QUOTES, 'UTF-8') ?></dd></div>
                <div><dt>Ciclo formativo</dt><dd><?= htmlspecialchars($alumno['nombre_especialidad'] ?? 'Sin ciclo asignado', ENT_QUOTES, 'UTF-8') ?></dd></div>
            </dl>
        </section>

        <div class="student-portal__columns">
            <section class="student-portal__card" aria-labelledby="student-portal-availability">
                <div class="student-portal__card-heading">
                    <div>
                        <h3 id="student-portal-availability">Disponibilidad</h3>
                        <p>Periodos registrados para realizar prácticas.</p>
                    </div>
                </div>
                <?php if ($disponibilidades === []): ?>
                    <p>Aún no has registrado tu disponibilidad.</p>
                    <a class="student-portal__text-action" href="index.php?action=disponibilidad">Indicar disponibilidad</a>
                <?php else: ?>
                    <ul class="student-portal__list">
                        <?php foreach ($disponibilidades as $item): ?>
                            <li>
                                <strong><?= htmlspecialchars($item['fecha_inicio'] . ' – ' . $item['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <a class="student-portal__text-action" href="index.php?action=disponibilidad">Actualizar disponibilidad</a>
                <?php endif; ?>
                <?php if ($horarioSemanal !== []): ?>
                    <h4>Horario semanal</h4>
                    <ul class="student-portal__list">
                        <?php foreach ($horarioSemanal as $item): ?>
                            <?php $dias = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo']; ?>
                            <li>
                                <strong><?= htmlspecialchars($dias[(int)$item['dia_semana']], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span><?= htmlspecialchars(substr($item['hora_inicio'], 0, 5) . '–' . substr($item['hora_fin'], 0, 5), ENT_QUOTES, 'UTF-8') ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="student-portal__card" aria-labelledby="student-portal-placements">
                <div class="student-portal__card-heading">
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
                                <strong><?= htmlspecialchars($item['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span><?= htmlspecialchars($item['nombre_especialidad'] . ' · ' . $item['fecha_inicio'] . ' – ' . $item['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="status-badge status-badge--<?= $estadoModifier ?>"><?= htmlspecialchars($item['estado'], ENT_QUOTES, 'UTF-8') ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <a class="student-portal__text-action" href="index.php?action=mis-asignaciones">Ver todas mis prácticas</a>
                <?php endif; ?>
            </section>

            <section class="student-portal__card" aria-labelledby="student-portal-grades">
                <div class="student-portal__card-heading">
                    <div>
                        <h3 id="student-portal-grades">Calificaciones</h3>
                        <p>Notas finales de las prácticas cerradas.</p>
                    </div>
                </div>
                <?php $notasPublicadas = array_filter($calificaciones, static fn(array $item): bool => $item['nota'] !== null); ?>
                <?php if ($notasPublicadas === []): ?>
                    <p>Aún no hay calificaciones registradas para tus prácticas.</p>
                <?php else: ?>
                    <ul class="student-portal__list">
                        <?php foreach ($notasPublicadas as $item): ?>
                            <li>
                                <strong><?= htmlspecialchars($item['nombre_empresa'] . ' · ' . $item['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span>Nota: <?= htmlspecialchars(number_format((float)$item['nota'], 2, ',', '') . ' / 10', ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if ($item['observaciones'] !== null && $item['observaciones'] !== ''): ?><span><?= nl2br(htmlspecialchars($item['observaciones'], ENT_QUOTES, 'UTF-8')) ?></span><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <a class="student-portal__text-action" href="index.php?action=mis-calificaciones">Ver mis calificaciones</a>
            </section>
        </div>
    <?php endif; ?>
</section>
