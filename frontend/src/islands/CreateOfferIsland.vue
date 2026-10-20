<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

const root = ref<HTMLElement | null>(null);
const submitting = ref(false);
let form: HTMLFormElement | null = null;

function updateWeekdayTimes(row: HTMLElement, active: boolean): void {
    row.querySelectorAll<HTMLInputElement>('input[type="time"]').forEach((input) => {
        input.disabled = !active;
    });
}

function onChange(event: Event): void {
    const target = event.target;
    if (!(target instanceof HTMLInputElement) || target.type !== 'checkbox') {
        return;
    }

    const row = target.closest<HTMLElement>('.offer-weekday');
    if (row) {
        updateWeekdayTimes(row, target.checked);
    }
}

function onSubmit(): void {
    submitting.value = true;
}

onMounted(() => {
    const island = root.value;
    form = island?.closest<HTMLFormElement>('form') ?? null;
    if (!island || !form) {
        return;
    }

    form.querySelectorAll<HTMLElement>('.offer-weekday').forEach((row) => {
        const checkbox = row.querySelector<HTMLInputElement>('input[type="checkbox"]');
        if (checkbox) {
            updateWeekdayTimes(row, checkbox.checked);
        }
    });

    form.addEventListener('change', onChange);
    form.addEventListener('submit', onSubmit);
});

onBeforeUnmount(() => {
    form?.removeEventListener('change', onChange);
    form?.removeEventListener('submit', onSubmit);
});
</script>

<template>
    <div ref="root">
        <button type="submit" :aria-busy="submitting">
            <svg
                v-if="submitting"
                class="offer-submit-spinner"
                viewBox="0 0 24 24"
                width="20"
                height="20"
                aria-hidden="true"
                focusable="false"
            >
                <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="3" stroke-dasharray="42 56" stroke-linecap="round">
                    <animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="0.8s" repeatCount="indefinite" />
                </circle>
            </svg>
            <span>{{ submitting ? 'Enviando oferta…' : 'Publicar oferta' }}</span>
        </button>
        <span v-if="submitting" class="visually-hidden" role="status" aria-live="polite">
            Enviando oferta…
        </span>
    </div>
</template>
