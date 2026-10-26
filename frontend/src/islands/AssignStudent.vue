<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref } from 'vue';
import { useAssignmentStore, type AssignmentPayload } from '../stores/assignment';

const props = defineProps<{ csrf: string; ofertaId: string; alumnoId: string }>();
const store = useAssignmentStore();
const root = ref<HTMLElement | null>(null);
const errorId = `assignment-${props.ofertaId}-${props.alumnoId}-error`;
let form: HTMLFormElement | null = null;
let tutorField: HTMLInputElement | null = null;

function setSubmitDisabled(disabled: boolean): void {
    form?.querySelectorAll<HTMLButtonElement>('button[type="submit"]').forEach((button) => {
        button.disabled = disabled;
    });
}

function setTutorInvalid(invalid: boolean): void {
    if (!tutorField) {
        return;
    }

    const describedBy = new Set((tutorField.getAttribute('aria-describedby') ?? '')
        .split(/\s+/)
        .filter(Boolean));
    if (invalid) {
        tutorField.setAttribute('aria-invalid', 'true');
        describedBy.add(errorId);
    } else {
        tutorField.removeAttribute('aria-invalid');
        describedBy.delete(errorId);
    }
    if (describedBy.size > 0) {
        tutorField.setAttribute('aria-describedby', [...describedBy].join(' '));
    } else {
        tutorField.removeAttribute('aria-describedby');
    }
}

async function focusError(): Promise<void> {
    await nextTick();
    root.value?.querySelector<HTMLElement>('[role="alert"]')?.focus();
}

function onTutorInput(): void {
    setTutorInvalid(false);
}

async function onSubmit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (store.status === 'loading' || store.status === 'success' || !form) {
        return;
    }

    setTutorInvalid(false);
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
        await focusError();
        return;
    }
    if (!tutor || tutor.length > 150) {
        store.status = 'error';
        store.message = 'Indica un tutor de empresa de hasta 150 caracteres.';
        setTutorInvalid(true);
        await nextTick();
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
    if (!assigned) {
        await focusError();
    }
}

onMounted(() => {
    form = root.value?.closest('form') ?? null;
    if (!form) {
        throw new Error('No se encontró el formulario de asignación.');
    }
    tutorField = form.querySelector<HTMLInputElement>('input[name="tutor_empresa"]');
    form.addEventListener('submit', onSubmit);
    tutorField?.addEventListener('input', onTutorInput);
});

onUnmounted(() => {
    form?.removeEventListener('submit', onSubmit);
    tutorField?.removeEventListener('input', onTutorInput);
    store.reset();
});
</script>

<template>
    <div ref="root" aria-live="polite" :aria-busy="store.status === 'loading'">
        <p v-if="store.status === 'loading'" role="status">Confirmando asignación…</p>
        <p v-else-if="store.status === 'success'" role="status">{{ store.message }}</p>
        <p v-else-if="store.status === 'error'" :id="errorId" role="alert" tabindex="-1">{{ store.message }}</p>
    </div>
</template>
