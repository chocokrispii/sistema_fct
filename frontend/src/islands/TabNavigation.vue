<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useNavigationStore } from '../stores/navigation';

interface NavigationItem {
    label: string;
    action: string;
}

interface NavigationTab {
    id: string;
    label: string;
    items: NavigationItem[];
}

const props = defineProps<{ role?: string }>();
const navigation = useNavigationStore();

const tabsByRole: Record<string, NavigationTab[]> = {
    Administrador: [
        { id: 'principal', label: 'Principal', items: [{ label: 'Inicio', action: 'inicio' }, { label: 'Alumnado', action: 'alumnos-centro' }, { label: 'Calificaciones', action: 'calificaciones' }] },
        { id: 'gestion', label: 'Gestión', items: [{ label: 'Emparejar alumnos', action: 'emparejar' }, { label: 'Asignaciones', action: 'asignaciones' }, { label: 'Consultas', action: 'consultas' }] },
        { id: 'ofertas', label: 'Ofertas', items: [{ label: 'Ofertas', action: 'ofertas' }, { label: 'Revisar ofertas', action: 'revisar-ofertas' }] },
        { id: 'empresas', label: 'Empresas', items: [{ label: 'Gestión de empresas', action: 'gestion-empresas' }] },
        { id: 'cuentas', label: 'Cuentas', items: [{ label: 'Cuentas y recuperación', action: 'gestion-cuentas' }, { label: 'Usuarios', action: 'usuarios' }] },
        { id: 'configuracion', label: 'Configuración', items: [{ label: 'Ciclos formativos', action: 'ciclos' }, { label: 'Diseño', action: 'diseno' }, { label: 'Integraciones', action: 'integraciones' }] },
    ],
    Coordinador: [
        { id: 'principal', label: 'Principal', items: [{ label: 'Inicio', action: 'inicio' }, { label: 'Alumnado', action: 'alumnos-centro' }, { label: 'Calificaciones', action: 'calificaciones' }] },
        { id: 'gestion', label: 'Gestión', items: [{ label: 'Emparejar alumnos', action: 'emparejar' }, { label: 'Asignaciones', action: 'asignaciones' }, { label: 'Consultas', action: 'consultas' }] },
        { id: 'ofertas', label: 'Ofertas y convenios', items: [{ label: 'Ofertas', action: 'ofertas' }, { label: 'Revisar ofertas', action: 'revisar-ofertas' }, { label: 'Revisar convenios', action: 'revisar-convenios' }] },
    ],
      Empresa: [
        { id: 'empresa', label: 'Mi empresa', items: [{ label: 'Panel de empresa', action: 'mi-empresa' }, { label: 'Evaluaciones finales', action: 'evaluaciones-empresa' }] },
      ],
    Alumno: [
        { id: 'expediente', label: 'Mi expediente', items: [{ label: 'Resumen', action: 'portal-alumno' }, { label: 'Editar perfil', action: 'perfil-alumno' }] },
        { id: 'practicas', label: 'Mis prácticas', items: [{ label: 'Disponibilidad', action: 'disponibilidad' }, { label: 'Asignaciones', action: 'mis-asignaciones' }, { label: 'Documentación de inicio', action: 'documentacion-inicio' }, { label: 'Documentación de cierre', action: 'documentacion-cierre' }, { label: 'Calificaciones', action: 'mis-calificaciones' }] },
    ],
    Observador: [
        { id: 'seguimiento', label: 'Seguimiento', items: [{ label: 'Asignaciones', action: 'asignaciones' }, { label: 'Convenios', action: 'auditar-convenios' }, { label: 'Consultas', action: 'consultas' }, { label: 'Calificaciones', action: 'calificaciones' }] },
    ],
};

const tabs = computed(() => tabsByRole[props.role || ''] || []);
const selectedTab = computed(() => tabs.value.find((tab) => tab.id === navigation.activeTab) || tabs.value[0]);

const currentAction = new URLSearchParams(window.location.search).get('action') || 'inicio';

function href(action: string, tabId = selectedTab.value?.id || 'principal'): string {
    return `index.php?action=${encodeURIComponent(action)}&tab=${encodeURIComponent(tabId)}`;
}

onMounted(() => {
    navigation.initialize(props.role || '', tabs.value.map((tab) => tab.id));
    document.documentElement.classList.add('vue-tabs-ready');
});
</script>

<template>
    <div v-if="tabs.length" class="tab-navigation" aria-label="Secciones principales">
        <div class="tab-navigation__tabs" aria-label="Categorías de navegación">
            <a
                v-for="tab in tabs"
                :key="tab.id"
                class="tab-navigation__tab"
                :class="{ 'tab-navigation__tab--active': selectedTab?.id === tab.id }"
                :href="href(tab.items[0].action, tab.id)"
                :aria-current="selectedTab?.id === tab.id ? 'true' : undefined"
            >{{ tab.label }}</a>
        </div>
        <div v-if="selectedTab" class="tab-navigation__items">
            <a v-for="item in selectedTab.items" :key="item.action" :href="href(item.action)" :aria-current="currentAction === item.action ? 'page' : undefined">{{ item.label }}</a>
        </div>
    </div>
</template>
