<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { useAssignmentStore, type AssignmentPayload } from '../stores/assignment';

const props = defineProps<{ csrf: string; ofertaId: string; alumnoId: string }>();
const store = useAssignmentStore();
const root = ref<HTMLElement | null>(null);
let form: HTMLFormElement | null = null;

function setSubmitDisabled(disabled: boolean): void {
    form?.querySelectorAll<HTMLButtonElement>('button[type="submit"]').forEach((button) => {
        button.disabled = disabled;
    });
}

async function onSubmit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (store.status === 'loading' || store.status === 'success' || !form) {
        return;
    }

    const submitter = event.submitter instanceof HTMLButtonElement
        ? event.submitter
        : form.querySelector<HTMLButtonElement>('button[type="submit"]');
    const offerId = Number(props.ofertaId);
    const studentId = Number(props.alumnoId);
    const tutorField = form.querySelector<HTMLInputElement>('input[name="tutor_empresa"]');
    const tutor = tutorField?.value.trim() ?? '';
    if (!submitter || !/^[1-9]\d*$/.test(props.ofertaId) || !/^[1-9]\d*$/.test(props.alumnoId)) {
        store.status = 'error';
        store.message = 'No se pudo identificar la oferta o el alumno.';
        return;
    }
    if (!tutor || tutor.length > 150) {
        store.status = 'error';
        store.message = 'Indica un tutor de empresa de hasta 150 caracteres.';
        tutorField?.focus();
        return;
    }

    const payload: AssignmentPayload = {
        oferta_id: offerId,
        alumno_id: studentId,
        tutor_empresa: tutor,
    };
    setSubmitDisabled(true);
    const assigned = await store.assign(payload, props.csrf);
    setSubmitDisabled(assigned);
}

onMounted(() => {
    form = root.value?.closest('form') ?? null;
    if (!form) {
        throw new Error('No se encontró el formulario de asignación.');
    }
    form.addEventListener('submit', onSubmit);
});

onUnmounted(() => {
    form?.removeEventListener('submit', onSubmit);
    store.reset();
});
</script>

<template>
    <div ref="root" aria-live="polite" :aria-busy="store.status === 'loading'">
        <p v-if="store.status === 'loading'" role="status">Confirmando asignación…</p>
        <p v-else-if="store.status === 'success'" role="status">{{ store.message }}</p>
        <p v-else-if="store.status === 'error'" role="alert">{{ store.message }}</p>
    </div>
</template>
