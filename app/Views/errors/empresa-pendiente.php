<section class="fct-card company-access-blocked" aria-labelledby="company-access-blocked-title">
    <?php
    $estadoSolicitud = $estadoSolicitud ?? 'Pendiente_Aprobacion';
    $mensajeSolicitud = match ($estadoSolicitud) {
        'Pendiente_Aprobacion' => 'Su solicitud de convenio se encuentra en proceso de validación administrativa.',
        'Rechazada' => 'La solicitud de su empresa ha sido rechazada. Contacte con el centro educativo para obtener más información.',
        default => 'No se ha encontrado una solicitud de empresa válida.',
    };
    ?>
    <h2 id="company-access-blocked-title"><?= $estadoSolicitud === 'Pendiente_Aprobacion' ? 'Solicitud pendiente de aprobación' : 'Estado de la solicitud de empresa' ?></h2>
    <p><?= htmlspecialchars($mensajeSolicitud, ENT_QUOTES, 'UTF-8') ?></p>
    <p>El acceso a los módulos privados estará disponible cuando la cuenta sea aprobada.</p>
    <a href="index.php?action=login">Volver al inicio de sesión</a>
</section>
