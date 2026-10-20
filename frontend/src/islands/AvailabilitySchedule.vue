<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { requestApi } from '../api/client';
import type {
    AvailabilityDayPayload,
    AvailabilityIslandState,
    AvailabilityUpdateResult,
    AvailabilityUpdatePayload,
    SavedAvailabilitySchedule,
} from '../types/availability';

const props = defineProps<{ state: string; preview?: boolean }>();
const initial = JSON.parse(props.state) as AvailabilityIslandState;
const days = reactive(initial.days.map((day) => ({ ...day })));
const selectedCount = computed(() => days.filter((day) => day.active).length);
const status = ref<'idle' | 'saving' | 'saved' | 'error'>('idle');
const esIndiferente = ref(initial.esIndiferente);
const bulkSelectedDays = ref([1, 2, 3, 4, 5]);
const firstActiveDay = days.find((day) => day.active);
const bulkStart = ref(firstActiveDay?.start ?? '09:00');
const bulkEnd = ref(firstActiveDay?.end ?? '17:00');
const announcement = ref('');
const bulkError = ref('');
const error = ref('');
const root = ref<HTMLElement | null>(null);
let form: HTMLFormElement | null = null;

function fieldValue(data: FormData, name: string): string {
    const value = data.get(name);
    return typeof value === 'string' ? value : '';
}

function payloadFromForm(target: HTMLFormElement): AvailabilityUpdatePayload {
    const data = new FormData(target);
    const horarios: Record<number, AvailabilityDayPayload> = {};

    for (const day of days) {
        horarios[day.day] = {
            activo: day.active,
            hora_inicio: day.start,
            hora_fin: day.end,
        };
    }

    return {
        fecha_inicio: fieldValue(data, 'fecha_inicio'),
        fecha_fin: fieldValue(data, 'fecha_fin'),
        turno: esIndiferente.value ? 'Indiferente' : 'M',
        es_indiferente: esIndiferente.value,
        horarios,
    };
}

function applyBulkSchedule(): void {
    bulkError.value = '';
    if (bulkSelectedDays.value.length === 0) {
        bulkError.value = 'Selecciona al menos un día.';
        return;
    }
    const validTime = /^(?:[01]\d|2[0-3]):(?:[0-5][05])$/;
    if (!validTime.test(bulkStart.value) || !validTime.test(bulkEnd.value) || bulkEnd.value <= bulkStart.value) {
        bulkError.value = 'Indica una franja válida en intervalos de cinco minutos.';
        return;
    }

    for (const day of days) {
        if (bulkSelectedDays.value.includes(day.day)) {
            day.active = true;
            day.start = bulkStart.value;
            day.end = bulkEnd.value;
        } else {
            day.active = false;
            day.start = '';
            day.end = '';
        }
    }
    onScheduleChange();
    announcement.value = `Horario aplicado a ${bulkSelectedDays.value.length} ${bulkSelectedDays.value.length === 1 ? 'día' : 'días'}.`;
}

function onScheduleChange(): void {
    bulkError.value = '';
    if (status.value !== 'saving') {
        status.value = 'idle';
        error.value = '';
    }
}

async function save(target: HTMLFormElement): Promise<void> {
    if (status.value === 'saving') {
        return;
    }
    if (props.preview === true) {
        status.value = 'saved';
        announcement.value = 'Vista previa: los cambios son locales y no se guardaron.';
        return;
    }
    const payload = payloadFromForm(target);
    status.value = 'saving';
    error.value = '';
    const submitButton = target.querySelector<HTMLButtonElement>('button[type="submit"]');
    if (submitButton) {
        submitButton.disabled = true;
    }

    try {
        const result = await requestApi<AvailabilityUpdateResult>('index.php?action=disponibilidad', {
            method: 'POST',
            headers: { 'X-CSRF-Token': initial.csrf },
            body: JSON.stringify(payload),
        });
        window.dispatchEvent(new CustomEvent<SavedAvailabilitySchedule>('availability:saved', {
            detail: result.savedSchedule,
        }));
        status.value = 'saved';
    } catch (cause) {
        status.value = 'error';
        error.value = cause instanceof Error
            ? cause.message
            : 'No se pudo guardar la disponibilidad. Inténtalo de nuevo.';
    } finally {
        if (submitButton) {
            submitButton.disabled = false;
        }
    }
}

function onSubmit(event: SubmitEvent): void {
    event.preventDefault();
    if (form && status.value !== 'saving') {
        void save(form);
    }
}

onMounted(() => {
    form = root.value?.closest('form') ?? null;
    if (!form) {
        throw new Error('No se encontró el formulario de disponibilidad.');
    }
    form.addEventListener('submit', onSubmit);
});

onBeforeUnmount(() => {
    form?.removeEventListener('submit', onSubmit);
});
</script>

<template>
    <div ref="root">
        <fieldset class="availability-schedule__bulk" :disabled="esIndiferente || status === 'saving'">
            <legend>Horario general</legend>
            <div class="availability-schedule__bulk-grid">
                <label>Hora entrada
                    <input v-model="bulkStart" type="time" step="300">
                </label>
                <label>Hora salida
                    <input v-model="bulkEnd" type="time" step="300">
                </label>
                <fieldset class="availability-schedule__day-picker">
                    <legend>Días</legend>
                    <label v-for="day in days" :key="day.day" class="availability-schedule__day-pill">
                        <input v-model="bulkSelectedDays" type="checkbox" :value="day.day" :aria-label="day.name">
                        <span :aria-label="day.name">{{ day.name.slice(0, 1) }}</span>
                    </label>
                </fieldset>
                <button type="button" class="availability-schedule__apply" @click="applyBulkSchedule">
                    Aplicar a seleccionados
                </button>
            </div>
            <p class="availability-schedule__hint">El horario aplicado queda disponible para editarse por día.</p>
            <p v-if="bulkError" class="message message-error" role="alert">{{ bulkError }}</p>
        </fieldset>

        <fieldset class="availability-schedule__indifferent">
            <legend>Preferencia horaria</legend>
            <label class="availability-toggle-card" :class="{ 'is-enabled': esIndiferente }">
                <span class="availability-toggle-card__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <circle cx="12" cy="12" r="9"></circle>
                        <path d="M12 7v5l3 2"></path>
                    </svg>
                </span>
                <span class="availability-toggle-card__copy">
                    <strong>Disponibilidad indiferente</strong>
                    <small>Se aceptan turnos de mañana, tarde o partido.</small>
                </span>
                <input
                    v-model="esIndiferente"
                    class="availability-toggle-card__input"
                    type="checkbox"
                    :disabled="status === 'saving'"
                    @change="onScheduleChange"
                >
                <span class="availability-toggle-card__switch" aria-hidden="true"><span></span></span>
            </label>
        </fieldset>
        <p v-if="announcement" class="availability-schedule__announcement" role="status" aria-live="polite">
            {{ announcement }}
        </p>

        <fieldset class="availability-schedule" :disabled="esIndiferente || status === 'saving'">
            <legend>Horario por día</legend>
            <p v-if="esIndiferente" class="availability-schedule__hint">Activa la disponibilidad indiferente para cualquier horario o desmárcala para definir franjas exactas.</p>
            <p v-else class="availability-schedule__selection" role="status" aria-live="polite">
                {{ selectedCount === 1 ? '1 día seleccionado' : `${selectedCount} días seleccionados` }}
            </p>
            <div class="availability-schedule__table-wrap">
                <table class="availability-schedule__table">
                    <thead>
                        <tr><th scope="col">Día</th><th scope="col">Disponible</th><th scope="col">Desde</th><th scope="col">Hasta</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="day in days" :key="day.day" :class="{ 'is-unavailable': !day.active }">
                            <th scope="row">{{ day.name }}</th>
                            <td>
                                <input
                                    v-model="day.active"
                                    type="checkbox"
                                    :name="`horarios[${day.day}][activo]`"
                                    value="1"
                                    :aria-label="day.name"
                                    @change="onScheduleChange"
                                >
                            </td>
                            <td>
                                <input
                                    v-model="day.start"
                                    type="time"
                                    step="300"
                                    :name="`horarios[${day.day}][hora_inicio]`"
                                    :aria-label="`${day.name}, hora de inicio`"
                                    :disabled="!day.active"
                                    @input="onScheduleChange"
                                >
                            </td>
                            <td>
                                <input
                                    v-model="day.end"
                                    type="time"
                                    step="300"
                                    :name="`horarios[${day.day}][hora_fin]`"
                                    :aria-label="`${day.name}, hora de fin`"
                                    :disabled="!day.active"
                                    @input="onScheduleChange"
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </fieldset>
        <p v-if="status === 'saved' && props.preview !== true" class="message message-success" role="status">Disponibilidad guardada con éxito.</p>
        <p v-if="error" class="message message-error" role="alert">{{ error }}</p>
    </div>
</template>
