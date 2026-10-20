<script setup lang="ts">
import { onMounted } from 'vue';
import { useSessionStore } from '../stores/session';

const props = defineProps<{ csrfToken: string }>();

const session = useSessionStore();

const roleCards = {
    Alumno: [
        { title: 'Mi expediente', description: 'Consulta los datos y el estado de tu proceso de prácticas.', action: 'portal-alumno', label: 'Abrir expediente' },
        { title: 'Disponibilidad', description: 'Actualiza el periodo y horario disponibles para realizar prácticas.', action: 'disponibilidad', label: 'Gestionar disponibilidad' },
    ],
    Empresa: [
        { title: 'Ofertas de prácticas', description: 'Publica plazas y consulta su estado de revisión.', action: 'publicar-oferta', label: 'Gestionar ofertas' },
        { title: 'Convenios', description: 'Registra documentos y consulta su estado.', action: 'convenios', label: 'Gestionar convenios' },
        { title: 'Sedes', description: 'Administra las sedes asociadas a tu empresa.', action: 'sedes', label: 'Gestionar sedes' },
    ],
    Coordinador: [
        { title: 'Revisiones', description: 'Consulta las ofertas y convenios pendientes; las decisiones corresponden a administración.', action: 'revisar-ofertas', label: 'Revisar ofertas' },
        { title: 'Asignaciones', description: 'Busca candidatos compatibles para las plazas disponibles.', action: 'emparejar', label: 'Emparejar alumnos' },
        { title: 'Seguimiento', description: 'Consulta prácticas y operaciones de gestión.', action: 'consultas', label: 'Abrir consultas' },
    ],
    Administrador: [
        { title: 'Revisiones', description: 'Resuelve ofertas y convenios pendientes.', action: 'revisar-ofertas', label: 'Revisar ofertas' },
        { title: 'Asignaciones', description: 'Busca candidatos compatibles para las plazas disponibles.', action: 'emparejar', label: 'Emparejar alumnos' },
        { title: 'Usuarios', description: 'Gestiona las cuentas del centro.', action: 'usuarios', label: 'Gestionar usuarios' },
    ],
    Observador: [
        { title: 'Asignaciones', description: 'Consulta las asignaciones de FCT en modo de solo lectura.', action: 'asignaciones', label: 'Consultar asignaciones' },
        { title: 'Seguimiento', description: 'Revisa los datos disponibles para tu rol.', action: 'consultas', label: 'Abrir consultas' },
        { title: 'Calificaciones', description: 'Consulta las calificaciones registradas.', action: 'calificaciones', label: 'Consultar calificaciones' },
        { title: 'Convenios', description: 'Consulta metadatos de acuerdos, sin acceso a documentos privados.', action: 'auditar-convenios', label: 'Consultar convenios' },
    ],
} as const;

onMounted(() => {
    void session.initialize(props.csrfToken);
});
</script>

<template>
    <section class="site-home" aria-labelledby="dashboard-title">
        <p v-if="session.status === 'loading'" role="status">Cargando tu panel…</p>
        <div v-else-if="session.status === 'error'" class="message message-error" role="alert">
            <p>{{ session.error }}</p>
            <button type="button" @click="session.initialize()">Reintentar</button>
        </div>
        <div v-else-if="session.user" class="site-home__hero">
            <p v-if="session.error" class="message message-error" role="alert">
                {{ session.error }}
            </p>
            <span class="site-home__eyebrow">{{ session.user.rol }}</span>
            <h2 id="dashboard-title">Bienvenido/a</h2>
            <p>{{ session.user.email }}</p>
            <div class="site-home__actions">
                <a class="site-home__button" :href="`index.php?action=${session.dashboardAction(session.user.rol)}`">
                    Ir a mi panel
                </a>
            </div>
            <div class="site-home__features">
                <article v-for="card in roleCards[session.user.rol]" :key="card.action" class="site-home__feature">
                    <h3>{{ card.title }}</h3>
                    <p>{{ card.description }}</p>
                    <a :href="`index.php?action=${card.action}`">{{ card.label }}</a>
                </article>
            </div>
        </div>
        <p v-else role="status">
            La sesión ya no está activa. <a href="index.php?action=login">Inicia sesión de nuevo</a>.
        </p>
    </section>
</template>
