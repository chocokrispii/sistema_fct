<section class="matching-page" aria-labelledby="matching-title">
    <div class="matching-page__intro">
        <span class="student-registration__eyebrow">Gestión del centro</span>
        <h2 id="matching-title">Emparejamiento y asignación</h2>
        <p>El sistema muestra alumnos cuyo ciclo, fechas, turno y horario semanal coinciden con ofertas aprobadas. La asignación se confirma de forma transaccional y vuelve a validar el cupo antes de guardar.</p>
        <a class="student-portal__text-action" href="index.php?action=asignaciones">Consultar asignaciones activas</a>
    </div>

    <?php if ($error !== ''): ?><p class="message message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <?php if ($ofertas === []): ?>
        <div class="matching-page__empty">
            <h3>No hay ofertas aprobadas vigentes</h3>
            <p>Cuando el centro apruebe ofertas con fechas futuras, aparecerán aquí los candidatos compatibles.</p>
            <a href="index.php?action=revisar-ofertas">Ir a la revisión de ofertas</a>
        </div>
    <?php else: ?>
        <div class="matching-page__offers">
            <?php foreach ($ofertas as $oferta): ?>
                <section class="matching-card" aria-labelledby="matching-offer-<?= (int)$oferta['id'] ?>">
                    <div class="matching-card__heading">
                        <div>
                            <span class="site-home__eyebrow">Oferta aprobada</span>
                            <h3 id="matching-offer-<?= (int)$oferta['id'] ?>"><?= htmlspecialchars($oferta['nombre_empresa'] . ' — ' . $oferta['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></h3>
                        </div>
                        <span class="matching-card__capacity"><?= (int)$oferta['plazas_disponibles'] ?> plazas disponibles</span>
                    </div>
                    <dl class="matching-card__details">
                        <div><dt>Periodo</dt><dd><?= htmlspecialchars($oferta['fecha_inicio'] . ' – ' . $oferta['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                        <div><dt>Turno</dt><dd><?= htmlspecialchars($oferta['turno'] === 'M' ? 'Mañana' : ($oferta['turno'] === 'T' ? 'Tarde' : 'Indiferente'), ENT_QUOTES, 'UTF-8') ?></dd></div>
                        <div><dt>Plazas cubiertas</dt><dd><?= (int)$oferta['asignadas'] ?> de <?= (int)$oferta['plazas_cuantas'] ?></dd></div>
                    </dl>

                    <?php if ($oferta['plazas_disponibles'] === 0): ?>
                        <p class="matching-card__empty">La oferta ya no tiene plazas disponibles.</p>
                    <?php elseif ($oferta['candidatos'] === []): ?>
                        <p class="matching-card__empty">No hay alumnos disponibles que cumplan el ciclo, las fechas y el turno de esta oferta.</p>
                    <?php else: ?>
                        <h4>Candidatos compatibles</h4>
                        <div class="matching-card__candidates">
                            <?php foreach ($oferta['candidatos'] as $candidato): ?>
                                <form class="matching-card__candidate" method="POST" action="index.php?action=asignar-alumno">
                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="oferta_id" value="<?= (int)$oferta['id'] ?>">
                                    <input type="hidden" name="alumno_id" value="<?= (int)$candidato['alumno_id'] ?>">
                                    <div class="matching-card__student">
                                        <strong><?= htmlspecialchars($candidato['nombre'] . ' ' . $candidato['apellidos'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span><?= htmlspecialchars($candidato['correo'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span>Turno disponible: <?= htmlspecialchars($candidato['turno'] === 'M' ? 'Mañana' : ($candidato['turno'] === 'T' ? 'Tarde' : 'Indiferente'), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <label>Tutor de empresa
                                        <input type="text" name="tutor_empresa" maxlength="150" autocomplete="name" required>
                                    </label>
                                    <button type="submit">Asignar a esta oferta</button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
