<?php
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$empresaNombre = (string)($empresa['nombre_empresa'] ?? 'tu empresa');
$sedesCount = count($sedes);
?>
<section class="company-hub" aria-labelledby="company-hub-title">
    <header class="company-hub__header">
        <span class="company-hub__eyebrow">Área de empresa</span>
        <h2 id="company-hub-title">Mi empresa</h2>
        <p>Todo lo que necesitas para preparar tu empresa, gestionar sus sedes y publicar prácticas está aquí.</p>
        <p class="company-hub__summary"><strong><?= $escape($empresaNombre) ?></strong> · <?= $sedesCount ?> <?= $sedesCount === 1 ? 'sede activa' : 'sedes activas' ?></p>
    </header>

    <div class="company-hub__grid">
        <a class="company-hub__card company-hub__card--primary" href="index.php?action=onboarding-empresa">
            <span class="company-hub__step">1</span>
            <span>
                <strong>Datos de empresa</strong>
                <small>Razón social, NIF, representante y datos de contacto.</small>
            </span>
        </a>
        <a class="company-hub__card" href="index.php?action=sedes">
            <span class="company-hub__step">2</span>
            <span>
                <strong>Sedes</strong>
                <small>Añade y administra los centros donde realizarás prácticas.</small>
            </span>
        </a>
        <a class="company-hub__card" href="index.php?action=convenios">
            <span class="company-hub__step">3</span>
            <span>
                <strong>Convenios</strong>
                <small>Registra la documentación necesaria con el centro.</small>
            </span>
        </a>
        <a class="company-hub__card" href="index.php?action=publicar-oferta">
            <span class="company-hub__step">4</span>
            <span>
                <strong>Ofertas de prácticas</strong>
                <small>Publica plazas y consulta el estado de tus ofertas.</small>
            </span>
        </a>
    </div>

    <p class="company-hub__help">¿Es tu primera vez? Empieza por <a href="index.php?action=onboarding-empresa">Datos de empresa</a> y sigue los pasos numerados.</p>
</section>
