<?php
$previewMode = ($previewMode ?? false) === true;
$partidaEnabled = ($formValues['jornada'] ?? 'normal') === 'partida';
?>
<section class="offer-create-page" aria-labelledby="offer-create-title">
    <nav class="offer-create__breadcrumbs" aria-label="Ruta de navegación">
        <a href="index.php?action=ofertas">Ofertas</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">Publicar oferta</span>
    </nav>

    <header class="offer-create__hero">
        <?php if ($previewMode): ?><span class="offer-create__eyebrow">Vista previa de desarrollo — datos ficticios</span><?php else: ?><span class="offer-create__eyebrow">Portal de empresa</span><?php endif; ?>
        <h2 id="offer-create-title">Publicar nueva oferta de FCT</h2>
        <p>Define el ciclo, las plazas, el periodo y las franjas semanales para que el centro pueda revisar la propuesta.</p>
    </header>

    <?php if ($error !== ''): ?>
        <p id="offer-publication-error" class="message message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <?php if (isset($_GET['publicada'])): ?>
        <p class="message message-success" role="status">Oferta enviada a revisión.</p>
    <?php endif; ?>

    <form class="page-form offer-create__form" method="POST" action="index.php?action=<?= $previewMode ? 'preview-publicar-oferta' : 'publicar-oferta' ?>"<?= $error !== '' ? ' aria-describedby="offer-publication-error"' : '' ?>>
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">

        <section class="offer-create__section" aria-labelledby="offer-general-title">
            <div class="offer-create__section-heading">
                <span class="offer-create__section-index" aria-hidden="true">01</span>
                <div>
                    <h3 id="offer-general-title">Información general</h3>
                    <p>Indica el ciclo formativo, el número de plazas y el periodo de prácticas.</p>
                </div>
            </div>
            <div class="offer-create__grid offer-create__grid--general">
                <div class="offer-create__field offer-create__field--full">
                    <label for="offer-title">Título de la oferta</label>
                    <input id="offer-title" type="text" name="titulo" maxlength="150" value="<?= htmlspecialchars($formValues['titulo'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" required>
                </div>
                <div class="offer-create__field offer-create__field--full">
                    <label for="offer-description">Descripción</label>
                    <textarea id="offer-description" name="descripcion" maxlength="2000" rows="4" required><?= htmlspecialchars($formValues['descripcion'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    <small>Describe las tareas y el contexto de las prácticas (máximo 2.000 caracteres).</small>
                </div>
                <div class="offer-create__field offer-create__field--cycle">
                    <span class="offer-create__field-label">Ciclo formativo</span>
                    <div class="offer-create__cycle-options" role="group" aria-label="Selecciona un ciclo formativo">
                        <?php foreach ($especialidades as $especialidad): ?>
                            <?php $specialtyId = (string)$especialidad['id']; ?>
                            <label class="offer-create__cycle-option">
                                <input type="radio" name="especialidad_id" value="<?= htmlspecialchars($specialtyId, ENT_QUOTES, 'UTF-8') ?>" <?= ($formValues['especialidad_id'] ?? '') === $specialtyId ? 'checked' : '' ?> required>
                                <span><?= htmlspecialchars($especialidad['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <small>Selecciona el ciclo formativo al que corresponde esta oferta.</small>
                </div>
                <div class="offer-create__field">
                    <label for="offer-vacancies">Número de plazas</label>
                    <input id="offer-vacancies" type="number" name="plazas" min="1" max="100" value="<?= htmlspecialchars($formValues['plazas'] ?: '1', ENT_QUOTES, 'UTF-8') ?>" required>
                    <small>Entre 1 y 100 plazas.</small>
                </div>
                <div class="offer-create__field">
                    <label for="offer-start-date">Fecha de inicio</label>
                    <input id="offer-start-date" type="date" name="fecha_inicio" value="<?= htmlspecialchars($formValues['fecha_inicio'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="offer-create__field">
                    <label for="offer-end-date">Fecha de fin</label>
                    <input id="offer-end-date" type="date" name="fecha_fin" value="<?= htmlspecialchars($formValues['fecha_fin'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
            </div>
        </section>

        <section class="offer-create__section" aria-labelledby="offer-schedule-title">
            <div class="offer-create__section-heading">
                <span class="offer-create__section-index" aria-hidden="true">02</span>
                <div>
                    <h3 id="offer-schedule-title">Horario semanal</h3>
                    <p>Selecciona los días de actividad e indica la franja exacta para cada uno.</p>
                </div>
            </div>
            <div class="offer-create__grid offer-create__grid--details">
                <div class="offer-create__field">
                    <label for="offer-jornada">Tipo de jornada</label>
                    <select id="offer-jornada" name="jornada" required>
                        <option value="normal" <?= ($formValues['jornada'] ?? 'normal') === 'normal' ? 'selected' : '' ?>>Jornada normal</option>
                        <option value="partida" <?= ($formValues['jornada'] ?? 'normal') === 'partida' ? 'selected' : '' ?>>Jornada partida</option>
                    </select>
                    <small>La jornada partida permite definir dos franjas por cada día activo.</small>
                </div>
            </div>
            <p class="offer-create__schedule-hint">Indica los días activos y sus franjas. Los horarios usan intervalos de cinco minutos.</p>
            <div class="offer-create__schedule-table-wrap">
                <table class="offer-create__schedule-table">
                    <thead>
                        <tr>
                            <th scope="col">Día</th>
                            <th scope="col">Activo</th>
                            <th scope="col">Inicio (1.ª franja)</th>
                            <th scope="col">Fin (1.ª franja)</th>
                            <th scope="col" data-partida-column <?= $partidaEnabled ? '' : 'hidden' ?>>Inicio (2.ª franja)</th>
                            <th scope="col" data-partida-column <?= $partidaEnabled ? '' : 'hidden' ?>>Fin (2.ª franja)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ([1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'] as $day => $name): ?>
                            <?php
                            $daySchedule = $formValues['horarios'][$day] ?? [];
                            $hasPostedSchedule = array_key_exists('horarios', $formValues);
                            $dayActive = $hasPostedSchedule
                                ? (bool)($daySchedule['activo'] ?? false)
                                : $day <= 5;
                            $dayStart = $daySchedule['hora_inicio'] ?? ($hasPostedSchedule ? '' : '09:00');
                            $dayEnd = $daySchedule['hora_fin'] ?? ($hasPostedSchedule
                                ? ''
                                : (($formValues['jornada'] ?? 'normal') === 'partida' ? '14:00' : '17:00'));
                            $daySecondStart = $daySchedule['hora_inicio_2'] ?? ($hasPostedSchedule
                                ? ''
                                : (($formValues['jornada'] ?? 'normal') === 'partida' ? '16:00' : ''));
                            $daySecondEnd = $daySchedule['hora_fin_2'] ?? ($hasPostedSchedule
                                ? ''
                                : (($formValues['jornada'] ?? 'normal') === 'partida' ? '21:00' : ''));
                            ?>
                            <tr class="offer-weekday">
                                <th scope="row"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></th>
                                <td>
                                    <label class="offer-weekday__active">
                                        <input type="checkbox" name="horarios[<?= $day ?>][activo]" value="1" aria-label="<?= htmlspecialchars($name . ', activo', ENT_QUOTES, 'UTF-8') ?>" <?= $dayActive ? 'checked' : '' ?>>
                                        <span data-day-active-label><?= $dayActive ? 'Sí' : 'No' ?></span>
                                    </label>
                                </td>
                                <td>
                                    <label class="visually-hidden" for="offer-start-<?= $day ?>"><?= htmlspecialchars($name . ', hora de inicio', ENT_QUOTES, 'UTF-8') ?></label>
                                    <input id="offer-start-<?= $day ?>" type="time" name="horarios[<?= $day ?>][hora_inicio]" value="<?= htmlspecialchars($dayStart, ENT_QUOTES, 'UTF-8') ?>" step="300">
                                </td>
                                <td>
                                    <label class="visually-hidden" for="offer-end-<?= $day ?>"><?= htmlspecialchars($name . ', hora de fin', ENT_QUOTES, 'UTF-8') ?></label>
                                    <input id="offer-end-<?= $day ?>" type="time" name="horarios[<?= $day ?>][hora_fin]" value="<?= htmlspecialchars($dayEnd, ENT_QUOTES, 'UTF-8') ?>" step="300">
                                </td>
                                <td data-partida-column <?= $partidaEnabled ? '' : 'hidden' ?>>
                                    <label class="visually-hidden" for="offer-start-2-<?= $day ?>"><?= htmlspecialchars($name . ', hora de inicio de la segunda franja', ENT_QUOTES, 'UTF-8') ?></label>
                                    <input id="offer-start-2-<?= $day ?>" type="time" name="horarios[<?= $day ?>][hora_inicio_2]" value="<?= htmlspecialchars($daySecondStart, ENT_QUOTES, 'UTF-8') ?>" step="300" data-partida-field>
                                </td>
                                <td data-partida-column <?= $partidaEnabled ? '' : 'hidden' ?>>
                                    <label class="visually-hidden" for="offer-end-2-<?= $day ?>"><?= htmlspecialchars($name . ', hora de fin de la segunda franja', ENT_QUOTES, 'UTF-8') ?></label>
                                    <input id="offer-end-2-<?= $day ?>" type="time" name="horarios[<?= $day ?>][hora_fin_2]" value="<?= htmlspecialchars($daySecondEnd, ENT_QUOTES, 'UTF-8') ?>" step="300" data-partida-field>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <script src="assets/js/offer-schedule.js" defer></script>
        </section>

        <section class="offer-create__section" aria-labelledby="offer-location-title">
            <div class="offer-create__section-heading">
                <span class="offer-create__section-index" aria-hidden="true">03</span>
                <div>
                    <h3 id="offer-location-title">Ubicación y modalidad</h3>
                    <p>Indica cómo se realizarán las prácticas y dónde se encuentra el puesto.</p>
                </div>
            </div>
            <div class="offer-create__grid">
                <div class="offer-create__field">
                    <label for="offer-modality">Modalidad</label>
                    <select id="offer-modality" name="modalidad" required>
                        <?php foreach (['Presencial', 'Remoto', 'Híbrido'] as $modality): ?>
                            <option value="<?= htmlspecialchars($modality, ENT_QUOTES, 'UTF-8') ?>" <?= ($formValues['modalidad'] ?? 'Presencial') === $modality ? 'selected' : '' ?>><?= htmlspecialchars($modality, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="offer-create__field offer-create__field--full">
                    <label for="offer-location">Ubicación o dirección</label>
                    <input id="offer-location" type="text" name="ubicacion" maxlength="255" value="<?= htmlspecialchars($formValues['ubicacion'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="street-address">
                    <small>Obligatoria para modalidad presencial o híbrida; opcional en remoto.</small>
                </div>
            </div>
        </section>

        <section class="offer-create__section" aria-labelledby="offer-requirements-title">
            <div class="offer-create__section-heading">
                <span class="offer-create__section-index" aria-hidden="true">04</span>
                <div>
                    <h3 id="offer-requirements-title">Requisitos y tecnologías</h3>
                    <p>Ayuda al alumnado a comprender el perfil que buscas.</p>
                </div>
            </div>
            <div class="offer-create__grid">
                <div class="offer-create__field offer-create__field--full">
                    <label for="offer-requirements">Requisitos</label>
                    <textarea id="offer-requirements" name="requisitos" maxlength="2000" rows="3"><?= htmlspecialchars($formValues['requisitos'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                <div class="offer-create__field offer-create__field--full">
                    <label for="offer-technologies">Tecnologías y herramientas</label>
                    <?php $technologyValue = (string)($formValues['tecnologias'] ?? ''); ?>
                    <div
                        data-vue-island="TechnologyTags"
                        data-prop-value="<?= htmlspecialchars($technologyValue, ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <input id="offer-technologies" type="text" name="tecnologias" maxlength="500" value="<?= htmlspecialchars($technologyValue, ENT_QUOTES, 'UTF-8') ?>" placeholder="PHP, Vue, MySQL" aria-describedby="offer-technologies-help">
                    </div>
                    <small id="offer-technologies-help">Separa hasta 10 etiquetas con comas (máximo 50 caracteres por etiqueta).</small>
                </div>
            </div>
        </section>

        <footer class="offer-create__actions">
            <a class="offer-create__cancel" href="index.php?action=ofertas">Cancelar</a>
            <?php if ($previewMode): ?>
                <button type="button" disabled aria-disabled="true">Publicación desactivada en la vista previa</button>
            <?php else: ?>
                <div data-vue-island="CreateOfferIsland">
                    <button type="submit">Publicar oferta</button>
                </div>
            <?php endif; ?>
        </footer>
    </form>
</section>

<section class="offer-list-section" aria-labelledby="company-offers-title">
    <div class="offer-list-section__heading">
        <div>
            <span class="offer-create__eyebrow">Seguimiento</span>
            <h2 id="company-offers-title">Mis ofertas</h2>
        </div>
        <p>Consulta el horario, el periodo y el estado de las ofertas que has enviado.</p>
    </div>
    <?php if ($ofertas === []): ?>
        <div class="offer-list-section__empty">
            <h3>Aún no has publicado ofertas</h3>
            <p>Completa el formulario para enviar la primera propuesta al centro.</p>
        </div>
    <?php else: ?>
        <div class="assignments-table-wrap">
            <table class="assignments-table">
                <thead>
                    <tr><th scope="col">Oferta</th><th scope="col">Ciclo</th><th scope="col">Plazas</th><th scope="col">Periodo</th><th scope="col">Jornada y horario</th><th scope="col">Modalidad y ubicación</th><th scope="col">Estado</th><th scope="col">Observación</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($ofertas as $oferta): ?>
                        <?php
                        $jornadaLabel = ($oferta['jornada'] ?? '') === 'partida' ? 'Jornada partida' : 'Jornada normal';
                        $statusClass = match ($oferta['estado']) {
                            'Aprobada' => 'status-badge--approved',
                            'Rechazada' => 'status-badge--rejected',
                            default => 'status-badge--pending',
                        };
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($oferta['titulo'] ?? 'Oferta FCT', ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars((string)($oferta['descripcion'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small><?php if (!empty($oferta['tecnologias'])): ?><small>Tecnologías: <?= htmlspecialchars($oferta['tecnologias'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?></td>
                            <td><?= htmlspecialchars($oferta['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= (int)$oferta['plazas_cuantas'] ?></td>
                            <td><?= htmlspecialchars($oferta['fecha_inicio'] . ' – ' . $oferta['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <strong><?= htmlspecialchars($jornadaLabel, ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if ($oferta['horarios'] === []): ?>
                                    <small>Pendiente de actualizar el horario</small>
                                <?php else: ?>
                                    <?php foreach ($oferta['horarios'] as $shift): ?>
                                        <?php
                                        $dayLabel = ['', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'][(int)$shift['dia_semana']] ?? 'Día';
                                        $startSecond = (string)($shift['hora_inicio_2'] ?? '');
                                        $endSecond = (string)($shift['hora_fin_2'] ?? '');
                                        ?>
                                        <small><?= htmlspecialchars($dayLabel . ' · 1.ª franja: ' . substr((string)$shift['hora_inicio'], 0, 5) . '–' . substr((string)$shift['hora_fin'], 0, 5), ENT_QUOTES, 'UTF-8') ?></small>
                                        <?php if ($startSecond !== '' && $endSecond !== ''): ?>
                                            <small><?= htmlspecialchars($dayLabel . ' · 2.ª franja: ' . substr($startSecond, 0, 5) . '–' . substr($endSecond, 0, 5), ENT_QUOTES, 'UTF-8') ?></small>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($oferta['modalidad'] . (!empty($oferta['ubicacion']) ? ' · ' . $oferta['ubicacion'] : ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($oferta['estado'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><?= htmlspecialchars($oferta['motivo_revision'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if (isset($paginacion)): ?>
        <?php require __DIR__ . '/../partials/paginacion.php'; ?>
    <?php endif; ?>
</section>
