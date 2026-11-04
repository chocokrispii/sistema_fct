<?php
$previewMode = ($previewMode ?? false) === true;
$sedes = $sedes ?? [];
$sedeError = $sedeError ?? '';
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
        <p class="message message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <?php if (isset($_GET['publicada'])): ?>
        <p class="message message-success" role="status">Oferta enviada a revisión.</p>
    <?php endif; ?>

    <form class="page-form offer-create__form" method="POST" action="index.php?action=<?= $previewMode ? 'preview-publicar-oferta' : 'publicar-oferta' ?>">
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
                    <label for="offer-shift">Tipo de turno</label>
                    <select id="offer-shift" name="turno" required>
                        <?php foreach (['M' => 'Mañana', 'T' => 'Tarde', 'P' => 'Partido', 'Indiferente' => 'Indiferente'] as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= ($formValues['turno'] ?? 'M') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>La compatibilidad horaria se calcula con las franjas definidas por día.</small>
                </div>
            </div>
            <p class="offer-create__schedule-hint">Las franjas exactas permiten comprobar la compatibilidad horaria con el alumnado. El tipo de turno clasifica la oferta y los horarios usan intervalos de cinco minutos.</p>
            <div class="offer-create__schedule-table-wrap">
                <table class="offer-create__schedule-table">
                    <thead>
                        <tr>
                            <th scope="col">Día</th>
                            <th scope="col">Activo</th>
                            <th scope="col">Hora de inicio</th>
                            <th scope="col">Hora de fin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ([1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'] as $day => $name): ?>
                            <tr class="offer-weekday">
                                <th scope="row"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></th>
                                <td>
                                    <label class="offer-weekday__active">
                                        <input type="checkbox" name="horarios[<?= $day ?>][activo]" value="1" aria-label="<?= htmlspecialchars($name . ', activo', ENT_QUOTES, 'UTF-8') ?>" <?= $day <= 5 ? 'checked' : '' ?>>
                                        <span><?= $day <= 5 ? 'Sí' : 'No' ?></span>
                                    </label>
                                </td>
                                <td>
                                    <label class="visually-hidden" for="offer-start-<?= $day ?>"><?= htmlspecialchars($name . ', hora de inicio', ENT_QUOTES, 'UTF-8') ?></label>
                                    <input id="offer-start-<?= $day ?>" type="time" name="horarios[<?= $day ?>][hora_inicio]" value="09:00" step="300" <?= $day > 5 ? 'disabled' : '' ?>>
                                </td>
                                <td>
                                    <label class="visually-hidden" for="offer-end-<?= $day ?>"><?= htmlspecialchars($name . ', hora de fin', ENT_QUOTES, 'UTF-8') ?></label>
                                    <input id="offer-end-<?= $day ?>" type="time" name="horarios[<?= $day ?>][hora_fin]" value="17:00" step="300" <?= $day > 5 ? 'disabled' : '' ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
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
                    <label for="offer-site">Sede de trabajo <span aria-hidden="true">*</span></label>
                    <?php $newSiteError = $sedeError !== '' && !str_contains($sedeError, 'Selecciona una sede'); ?>
                    <select id="offer-site" name="sede_id" data-offer-site-select required <?= $sedeError !== '' && !$newSiteError ? 'aria-invalid="true" aria-describedby="offer-site-error"' : '' ?>>
                        <option value="">Selecciona la sede donde se realizarán las prácticas</option>
                        <?php foreach ($sedes as $sede): ?>
                            <option value="<?= (int)$sede['id'] ?>" <?= (string)($formValues['sede_id'] ?? '') === (string)$sede['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sede['nombre'] . ' — ' . $sede['direccion'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="nueva" <?= ($formValues['sede_id'] ?? '') === 'nueva' ? 'selected' : '' ?>>Añadir nueva dirección de trabajo…</option>
                    </select>
                    <?php if ($sedeError !== '' && !$newSiteError): ?><small id="offer-site-error" class="message message-error" role="alert"><?= htmlspecialchars($sedeError, ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
                    <small>La sede seleccionada y su dirección completa se mostrarán al alumnado y al centro.</small>
                </div>
                <div class="offer-create__field offer-create__field--full">
                    <details data-offer-new-site <?= ($formValues['sede_id'] ?? '') === 'nueva' ? 'open' : '' ?>>
                        <summary>Añadir nueva dirección de trabajo</summary>
                        <fieldset>
                            <legend>Datos de la nueva sede</legend>
                            <label for="offer-new-site-name">Nombre de la sede</label>
                            <input id="offer-new-site-name" type="text" name="nueva_sede_nombre" maxlength="150" value="<?= htmlspecialchars($formValues['nueva_sede_nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="organization" <?= $newSiteError ? 'aria-invalid="true" aria-describedby="offer-new-site-error"' : '' ?>>
                            <label for="offer-new-site-address">Dirección completa</label>
                            <input id="offer-new-site-address" type="text" name="nueva_sede_direccion" maxlength="255" value="<?= htmlspecialchars($formValues['nueva_sede_direccion'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="street-address" <?= $newSiteError ? 'aria-invalid="true" aria-describedby="offer-new-site-error"' : '' ?>>
                            <?php if ($newSiteError): ?><small id="offer-new-site-error" class="message message-error" role="alert"><?= htmlspecialchars($sedeError, ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
                            <label for="offer-new-site-phone">Teléfono de la sede (opcional)</label>
                            <input id="offer-new-site-phone" type="tel" name="nueva_sede_telefono" maxlength="20" value="<?= htmlspecialchars($formValues['nueva_sede_telefono'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="tel">
                        </fieldset>
                    </details>
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
<?php if (!$previewMode): ?><script type="module" src="assets/js/offer-site-selector.js"></script><?php endif; ?>

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
                    <tr><th scope="col">Oferta</th><th scope="col">Ciclo</th><th scope="col">Plazas</th><th scope="col">Periodo</th><th scope="col">Turno y modalidad</th><th scope="col">Estado</th><th scope="col">Observación</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($ofertas as $oferta): ?>
                        <?php
                        $turnLabel = ['M' => 'Mañana', 'T' => 'Tarde', 'P' => 'Partido', 'Indiferente' => 'Indiferente'][$oferta['turno']] ?? 'Sin especificar';
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
                            <td><?= EuropeanDate::periodBlock($oferta['fecha_inicio'], $oferta['fecha_fin']) ?></td>
                            <td>
                                <?php if ($oferta['horarios'] === []): ?>
                                    Pendiente de actualizar
                                <?php else: ?>
                                    <?php foreach ($oferta['horarios'] as $shift): ?>
                                        <?= htmlspecialchars(['', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'][(int)$shift['dia_semana']] . ' ' . substr($shift['hora_inicio'], 0, 5) . '–' . substr($shift['hora_fin'], 0, 5), ENT_QUOTES, 'UTF-8') ?><br>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($turnLabel . ' · ' . $oferta['modalidad'] . ' · ' . $oferta['sede_nombre'] . ' — ' . $oferta['sede_direccion'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($oferta['estado'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><?= htmlspecialchars($oferta['motivo_revision'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
