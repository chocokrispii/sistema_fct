<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { SavedAvailabilitySchedule } from '../types/availability';

const props = defineProps<{ state: string }>();
const savedSchedule = ref(JSON.parse(props.state) as SavedAvailabilitySchedule);

function isSavedAvailabilitySchedule(value: unknown): value is SavedAvailabilitySchedule {
    if (typeof value !== 'object' || value === null) {
        return false;
    }

    const schedule = value as Partial<SavedAvailabilitySchedule>;
    return typeof schedule.fecha_inicio === 'string'
        && typeof schedule.fecha_fin === 'string'
        && typeof schedule.es_indiferente === 'boolean'
        && Array.isArray(schedule.dias)
        && schedule.dias.every((day) => typeof day === 'object'
            && day !== null
            && typeof day.dia_semana === 'number'
            && typeof day.dia === 'string'
            && typeof day.hora_inicio === 'string'
            && typeof day.hora_fin === 'string');
}

function updateSavedSchedule(event: Event): void {
    if (event instanceof CustomEvent && isSavedAvailabilitySchedule(event.detail)) {
        savedSchedule.value = event.detail;
    }
}

onMounted(() => {
    window.addEventListener('availability:saved', updateSavedSchedule);
});

onBeforeUnmount(() => {
    window.removeEventListener('availability:saved', updateSavedSchedule);
});
</script>

<template>
    <section class="availability-page__current" aria-labelledby="availability-current-title">
        <h3 id="availability-current-title">Horario semanal guardado</h3>
        <p v-if="savedSchedule.fecha_inicio && savedSchedule.fecha_fin">
            Registrado de {{ savedSchedule.fecha_inicio }} a {{ savedSchedule.fecha_fin }}
        </p>
        <p v-else>Aún no has registrado disponibilidad.</p>
        <p v-if="savedSchedule.es_indiferente">Disponible para cualquier horario.</p>
        <ul v-else-if="savedSchedule.dias.length > 0" class="student-portal__list">
            <li v-for="day in savedSchedule.dias" :key="day.dia_semana">
                <strong>{{ day.dia }}</strong>
                <span>{{ day.hora_inicio.slice(0, 5) }}–{{ day.hora_fin.slice(0, 5) }}</span>
            </li>
        </ul>
        <p v-else-if="savedSchedule.fecha_inicio">No hay franjas semanales guardadas.</p>
    </section>
</template>
