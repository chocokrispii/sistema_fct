<?php $rolActual = $_SESSION['rol'] ?? null; ?>
<section class="site-home" aria-labelledby="site-home-title">
    <div class="site-home__hero">
        <span class="site-home__eyebrow">Sistema de Formación en Centros de Trabajo</span>
        <h2 id="site-home-title">Conecta tu formación con el mundo laboral</h2>
        <p>Consulta oportunidades de prácticas, encuentra talento para tu empresa y gestiona el proceso de FCT desde un mismo lugar.</p>
        <div class="site-home__actions">
            <?php if ($rolActual === null): ?>
                <a class="site-home__button" href="index.php?action=ofertas">Ver ofertas</a>
                <a class="site-home__button site-home__button--secondary" href="index.php?action=registro-alumno">Registro de alumnado</a>
            <?php elseif ($rolActual === 'Alumno'): ?>
                <a class="site-home__button" href="index.php?action=portal-alumno">Ir a mi expediente</a>
                <a class="site-home__button site-home__button--secondary" href="index.php?action=ofertas">Consultar ofertas</a>
            <?php elseif ($rolActual === 'Empresa'): ?>
                <a class="site-home__button" href="index.php?action=publicar-oferta">Gestionar ofertas</a>
                <a class="site-home__button site-home__button--secondary" href="index.php?action=convenios">Gestionar convenios</a>
            <?php elseif (in_array($rolActual, ['Administrador', 'Coordinador'], true)): ?>
                <a class="site-home__button" href="index.php?action=revisar-ofertas">Revisar ofertas</a>
                <a class="site-home__button site-home__button--secondary" href="index.php?action=emparejar">Emparejar alumnos</a>
            <?php else: ?>
                <a class="site-home__button" href="index.php?action=asignaciones">Consultar asignaciones</a>
                <a class="site-home__button site-home__button--secondary" href="index.php?action=consultas">Consultas</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($rolActual === null): ?>
    <div class="site-home__features">
        <article class="site-home__feature">
            <h3>Alumnado</h3>
            <p>Crea tu expediente y consulta las oportunidades de prácticas disponibles para tu ciclo formativo.</p>
            <a href="index.php?action=registro-alumno">Crear cuenta de alumno</a>
        </article>
        <article class="site-home__feature">
            <h3>Empresas</h3>
            <p>Registra tu empresa para tramitar convenios y publicar plazas de prácticas.</p>
            <a href="index.php?action=registro-empresa">Registrar empresa</a>
        </article>
        <article class="site-home__feature">
            <h3>Centros formativos</h3>
            <p>El equipo autorizado puede revisar convenios y ofertas, y coordinar las asignaciones.</p>
            <a href="index.php?action=login">Acceso del centro</a>
        </article>
    </div>
    <?php elseif ($rolActual === 'Alumno'): ?>
        <div class="site-home__features">
            <article class="site-home__feature">
                <h3>Mi expediente</h3>
                <p>Consulta tus datos, periodos de disponibilidad y prácticas asignadas.</p>
                <a href="index.php?action=portal-alumno">Abrir mi expediente</a>
            </article>
            <article class="site-home__feature">
                <h3>Disponibilidad</h3>
                <p>Actualiza el periodo, turno y horario semanal que el centro utilizará para buscar coincidencias.</p>
                <a href="index.php?action=disponibilidad">Gestionar disponibilidad</a>
            </article>
            <article class="site-home__feature">
                <h3>Ofertas</h3>
                <p>Consulta las oportunidades de prácticas aprobadas y vigentes.</p>
                <a href="index.php?action=ofertas">Ver ofertas</a>
            </article>
        </div>
    <?php elseif ($rolActual === 'Empresa'): ?>
        <div class="site-home__features">
            <article class="site-home__feature">
                <h3>Ofertas de prácticas</h3>
                <p>Publica plazas y consulta su estado de revisión.</p>
                <a href="index.php?action=publicar-oferta">Gestionar ofertas</a>
            </article>
            <article class="site-home__feature">
                <h3>Convenios</h3>
                <p>Registra documentos vinculados a ciclos formativos y revisa su estado.</p>
                <a href="index.php?action=convenios">Gestionar convenios</a>
            </article>
            <article class="site-home__feature">
                <h3>Sedes</h3>
                <p>Administra las sedes asociadas a tu empresa.</p>
                <a href="index.php?action=sedes">Gestionar sedes</a>
            </article>
        </div>
    <?php elseif (in_array($rolActual, ['Administrador', 'Coordinador'], true)): ?>
        <div class="site-home__features">
            <article class="site-home__feature">
                <h3>Revisiones</h3>
                <p>Gestiona las ofertas y convenios pendientes antes de abrir plazas.</p>
                <a href="index.php?action=revisar-ofertas">Revisar ofertas</a>
            </article>
            <article class="site-home__feature">
                <h3>Asignaciones</h3>
                <p>Busca candidatos compatibles y asigna las plazas disponibles.</p>
                <a href="index.php?action=emparejar">Emparejar alumnos</a>
            </article>
            <article class="site-home__feature">
                <h3>Seguimiento</h3>
                <p>Consulta prácticas y operaciones de gestión autorizadas.</p>
                <a href="index.php?action=consultas">Abrir consultas</a>
                <a href="index.php?action=calificaciones">Gestionar calificaciones</a>
            </article>
        </div>
    <?php else: ?>
        <div class="site-home__features">
            <article class="site-home__feature">
                <h3>Asignaciones</h3>
                <p>Consulta las asignaciones de FCT en modo de solo lectura.</p>
                <a href="index.php?action=asignaciones">Consultar asignaciones</a>
            </article>
            <article class="site-home__feature">
                <h3>Seguimiento</h3>
                <p>Revisa los datos de seguimiento disponibles para tu rol.</p>
                <a href="index.php?action=consultas">Abrir consultas</a>
                <a href="index.php?action=calificaciones">Consultar calificaciones</a>
            </article>
        </div>
    <?php endif; ?>
</section>
