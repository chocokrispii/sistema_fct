<?php $pendientesReenvio = (int)($pendientesReenvio ?? 0); ?>
<section class="admin-hub" aria-labelledby="gestion-cuentas-title">
    <header class="admin-hub__header">
        <p class="admin-hub__eyebrow">Administración</p>
        <h1 id="gestion-cuentas-title">Cuentas y recuperación</h1>
        <p class="admin-hub__summary">Gestiona usuarios, restablecimientos de contraseña y reenvíos de activación desde una sola pestaña.</p>
    </header>
    <div class="admin-hub__grid">
        <a class="admin-hub__card admin-hub__card--primary" href="index.php?action=usuarios"><span class="admin-hub__step">1</span><span><strong>Usuarios</strong><small>Consultar y administrar las cuentas del sistema.</small></span></a>
        <a class="admin-hub__card" href="index.php?action=recuperaciones"><span class="admin-hub__step">2</span><span><strong>Recuperaciones</strong><small>Revisar solicitudes y enviar enlaces para recuperar el acceso.</small></span></a>
        <a class="admin-hub__card" href="index.php?action=solicitudes-activacion"><span class="admin-hub__step">3</span><span><strong>Reenvíos de activación</strong><small>Gestionar solicitudes de activación pendientes<?= $pendientesReenvio > 0 ? ' (' . $pendientesReenvio . ')' : '' ?>.</small></span></a>
    </div>
</section>
