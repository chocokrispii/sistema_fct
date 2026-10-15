<?php
/** @var int $totalOfertas */
/** @var int $totalEmpresas */
/** @var int $asignacionesActivas */
/** @var float|null $notaMedia */
/** @var array $ofertasRecientes */
/** @var array $eventos */
/** @var bool $tieneDisponibilidad */

$nombreCompleto = trim((string)($_SESSION['nombre'] ?? ''));
if ($nombreCompleto === '') {
    $nombreCompleto = explode('@', (string)($_SESSION['email'] ?? 'alumno'))[0];
}
$primerNombre = explode(' ', $nombreCompleto)[0];

$meses = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$ico = static function (string $d, string $extra = ''): string {
    return '<svg class="fct-ico ' . $extra . '" viewBox="0 0 24 24" aria-hidden="true"><path d="' . $d . '"/></svg>';
};
$arrow = '<svg class="fct-ico fct-ico--stroke" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
$chevron = '<svg class="fct-ico fct-ico--stroke" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>';

$icoMaletin = 'M9 3h6a1 1 0 0 1 1 1v2h4a2 2 0 0 1 2 2v3H2V8a2 2 0 0 1 2-2h4V4a1 1 0 0 1 1-1zm1 2v1h4V5zM2 13h8v1.5h4V13h8v5a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2z';
$icoArchivo = 'M6 2h8l6 6v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zM8 12h8v1.6H8zM8 15.5h8v1.6H8z';
$icoCalendario = 'M7 2h2v2h6V2h2v2h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2zM5 9v11h14V9zm2 3h3v3H7z';
$icoUsuarios = 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm8 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM9 13c-3.3 0-7 1.6-7 4.5V20h14v-2.5C16 14.6 12.3 13 9 13zm8.5.2c2.5.4 4.5 1.7 4.5 3.8V20h-4v-2.5c0-1.5-.6-2.8-1.6-3.8.3-.1.7-.4 1.1-.5z';
$icoEdificio = 'M4 21V3h11v6h5v12h-6v-4h-3v4zM7 6v2h2V6zm4 0v2h2V6zM7 11v2h2v-2zm4 0v2h2v-2zM7 15v2h2v-2zm4 0v2h2v-2zm7-4v2h-2v-2z';
$icoEstrella = 'M12 2l3 6.3 6.9.9-5 4.8 1.2 6.9L12 17.6 5.9 20.9 7.1 14 2.1 9.2 9 8.3z';

$etiquetaClase = static function (string $texto): string {
    $t = mb_strtolower($texto, 'UTF-8');
    if (str_contains($t, 'inform') || str_contains($t, 'sistemas') || str_contains($t, 'web')) return 'blue';
    if (str_contains($t, 'admin') || str_contains($t, 'gesti') || str_contains($t, 'market')) return 'green';
    if (str_contains($t, 'transp') || str_contains($t, 'log')) return 'orange';
    return ['blue', 'green', 'orange', 'purple'][abs(crc32($t)) % 4];
};
?>
<div class="fct-dashboard">
    <div class="fct-dashboard__main">
        <section class="fct-hero" aria-labelledby="fct-hero-title">
            <div class="fct-hero__text">
                <h2 id="fct-hero-title">¡Hola, <?= $e($primerNombre) ?>!</h2>
                <p class="fct-hero__lead">Tu futuro profesional empieza aquí</p>
                <p>Consulta tus oportunidades de prácticas, gestiona tu expediente y da el siguiente paso en tu formación.</p>
            </div>
            <img class="fct-hero__art" src="assets/img/hero-ilustracion.svg" alt="" width="360" height="190">
        </section>

        <section class="fct-stats" aria-label="Resumen">
            <a class="fct-stat fct-stat--blue" href="index.php?action=ofertas">
                <span class="fct-stat__icon"><?= $ico($icoMaletin) ?></span>
                <strong><?= (int)$totalOfertas ?></strong>
                <span class="fct-stat__label">Ofertas disponibles</span>
                <span class="fct-stat__go"><?= $arrow ?></span>
            </a>
            <a class="fct-stat fct-stat--green" href="index.php?action=ofertas">
                <span class="fct-stat__icon"><?= $ico($icoEdificio) ?></span>
                <strong><?= (int)$totalEmpresas ?></strong>
                <span class="fct-stat__label">Empresas con ofertas vigentes</span>
                <span class="fct-stat__go"><?= $arrow ?></span>
            </a>
            <a class="fct-stat fct-stat--purple" href="index.php?action=mis-asignaciones">
                <span class="fct-stat__icon"><?= $ico($icoArchivo) ?></span>
                <strong><?= (int)$asignacionesActivas ?></strong>
                <span class="fct-stat__label">Asignaciones activas</span>
                <span class="fct-stat__go"><?= $arrow ?></span>
            </a>
            <a class="fct-stat fct-stat--orange" href="index.php?action=mis-calificaciones">
                <span class="fct-stat__icon"><?= $ico($icoEstrella) ?></span>
                <strong><?= $notaMedia === null ? '—' : $e(number_format($notaMedia, 1, '.', '')) ?></strong>
                <span class="fct-stat__label">Tu calificación media</span>
                <span class="fct-stat__go"><?= $arrow ?></span>
            </a>
        </section>

        <section class="fct-card fct-offers" aria-labelledby="fct-offers-title">
            <div class="fct-card__head">
                <h3 id="fct-offers-title">Ofertas de prácticas vigentes</h3>
                <a class="fct-link" href="index.php?action=ofertas">Ver todas <?= $arrow ?></a>
            </div>
            <?php if ($ofertasRecientes === []): ?>
                <p class="fct-empty">No hay ofertas vigentes en este momento.</p>
            <?php else: ?>
                <ul class="fct-offers__list">
                    <?php foreach ($ofertasRecientes as $i => $oferta): ?>
                        <li>
                            <a class="fct-offer" href="index.php?action=ofertas">
                                <span class="fct-logo fct-logo--<?= ($i % 4) + 1 ?>"><?= $e(mb_strtoupper(mb_substr($oferta['nombre_empresa'], 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
                                <span class="fct-offer__info">
                                    <strong><?= $e($oferta['nombre_empresa']) ?></strong>
                                    <small><?= $e($oferta['nombre_especialidad']) ?></small>
                                </span>
                                <span class="fct-tag fct-tag--<?= $etiquetaClase($oferta['nombre_especialidad']) ?>"><?= $e($oferta['nombre_especialidad']) ?></span>
                                <span class="fct-offer__chev"><?= $chevron ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <aside class="fct-tip" data-dismissible role="note">
            <svg class="fct-ico fct-tip__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm-1 5h2v2h-2zm0 4h2v6h-2z"/></svg>
            <p><strong>Recuerda:</strong> mantén actualizados tus datos de contacto y tu disponibilidad para que podamos asignarte las mejores oportunidades.</p>
            <button type="button" class="fct-tip__close" data-dismiss aria-label="Cerrar aviso">
                <svg class="fct-ico fct-ico--stroke" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </aside>
    </div>

    <div class="fct-dashboard__side">
        <section class="fct-card fct-quick" aria-labelledby="fct-quick-title">
            <h3 id="fct-quick-title">Accesos rápidos</h3>
            <div class="fct-quick__grid">
                <a class="fct-quick__item" href="index.php?action=ofertas"><span class="fct-square fct-square--blue"><?= $ico($icoMaletin) ?></span><span>Ver ofertas</span><?= $arrow ?></a>
                <a class="fct-quick__item" href="index.php?action=portal-alumno"><span class="fct-square fct-square--green"><?= $ico($icoArchivo) ?></span><span>Mi expediente</span><?= $arrow ?></a>
                <a class="fct-quick__item" href="index.php?action=disponibilidad"><span class="fct-square fct-square--purple"><?= $ico($icoCalendario) ?></span><span>Gestionar disponibilidad</span><?= $arrow ?></a>
                <a class="fct-quick__item" href="index.php?action=mis-asignaciones"><span class="fct-square fct-square--orange"><?= $ico($icoUsuarios) ?></span><span>Mis asignaciones</span><?= $arrow ?></a>
            </div>
        </section>

        <section class="fct-card fct-events" aria-labelledby="fct-events-title">
            <div class="fct-card__head">
                <h3 id="fct-events-title">Próximos eventos</h3>
                <a class="fct-link" href="index.php?action=mis-asignaciones">Ver calendario <?= $arrow ?></a>
            </div>
            <?php if ($eventos === []): ?>
                <p class="fct-empty">No tienes eventos próximos.</p>
            <?php else: ?>
                <ol class="fct-events__list">
                    <?php foreach ($eventos as $ev): $ts = strtotime($ev['fecha']); ?>
                        <li>
                            <span class="fct-date"><strong><?= (int)date('j', $ts) ?></strong><small><?= $e($meses[(int)date('n', $ts)]) ?></small></span>
                            <span class="fct-events__body"><strong><?= $e($ev['titulo']) ?></strong><small><?= $e($ev['detalle']) ?></small></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>

        <section class="fct-goal" aria-labelledby="fct-goal-title">
            <span class="fct-goal__icon"><svg class="fct-ico fct-ico--stroke" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.5"/></svg></span>
            <div class="fct-goal__body">
                <h3 id="fct-goal-title">Tu próximo objetivo</h3>
                <?php if ($tieneDisponibilidad): ?>
                    <p>Revisa las ofertas vigentes y mantén tu disponibilidad al día.</p>
                    <a class="fct-goal__btn" href="index.php?action=ofertas">Ver ofertas <?= $arrow ?></a>
                <?php else: ?>
                    <p>Completa tu disponibilidad para recibir mejores propuestas de prácticas.</p>
                    <a class="fct-goal__btn" href="index.php?action=disponibilidad">Gestionar disponibilidad <?= $arrow ?></a>
                <?php endif; ?>
            </div>
            <svg class="fct-goal__art" viewBox="0 0 120 80" aria-hidden="true"><path class="m1" d="M0 80 40 24l26 36 14-16 40 36z"/><path class="m2" d="M52 80 84 38l36 42z"/><path class="flag" d="M86 38V12l14 5-14 6"/></svg>
        </section>
    </div>
</div>
<script src="assets/js/dashboard.js" defer></script>
