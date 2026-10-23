<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { requestApi } from '../api/client';

const props = defineProps<{ csrf: string }>();
const root = ref<HTMLElement | null>(null);
const dialog = ref<HTMLDialogElement | null>(null);
const form = ref<HTMLFormElement | null>(null);
const companyId = ref('');
const companyName = ref('');
const state = ref<'idle' | 'submitting' | 'error'>('idle');
const message = ref('');
let container: HTMLElement | null = null;

function onContainerClick(event: MouseEvent): void {
    if (!(event.target instanceof Element) || !dialog.value?.showModal) {
        return;
    }
    const link = event.target.closest<HTMLAnchorElement>('[data-company-approval-open]');
    if (!link || !container?.contains(link)) {
        return;
    }
    const id = link.dataset.companyId;
    if (!id || !/^[1-9]\d*$/.test(id)) {
        return;
    }

    event.preventDefault();
    companyId.value = id;
    companyName.value = link.dataset.companyName ?? '';
    message.value = '';
    state.value = 'idle';
    dialog.value.showModal();
    form.value?.querySelector<HTMLInputElement>('input[name="numero_convenio"]')?.focus();
}

function onDialogClose(): void {
    state.value = 'idle';
    message.value = '';
    form.value?.reset();
    companyId.value = '';
    companyName.value = '';
}

async function onSubmit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (!form.value || !/^[1-9]\d*$/.test(companyId.value) || state.value === 'submitting') {
        return;
    }

    const fileInput = form.value.querySelector<HTMLInputElement>('input[name="pdf_convenio"]');
    const file = fileInput?.files?.[0];
    if (file && file.size > 10 * 1024 * 1024) {
        state.value = 'error';
        message.value = 'El PDF no puede superar los 10 MB.';
        await nextTick();
        fileInput?.focus();
        return;
    }

    state.value = 'submitting';
    message.value = '';
    try {
        await requestApi<{ id: number; estado: string; message: string }>(
            `api/admin/empresas/${companyId.value}/aprobar`,
            {
                method: 'POST',
                headers: { 'X-CSRF-Token': props.csrf },
                body: new FormData(form.value),
            },
        );
        window.location.reload();
    } catch (cause) {
        state.value = 'error';
        message.value = cause instanceof Error
            ? cause.message
            : 'No se pudo aprobar la empresa. Inténtalo de nuevo.';
        await nextTick();
        dialog.value?.querySelector<HTMLElement>('[role="alert"]')?.focus();
    }
}

onMounted(() => {
    container = root.value?.closest<HTMLElement>('[data-company-requests]') ?? null;
    if (!container) {
        throw new Error('No se encontró el listado de solicitudes de empresa.');
    }
    container.addEventListener('click', onContainerClick);
});

onBeforeUnmount(() => {
    container?.removeEventListener('click', onContainerClick);
});
</script>

<template>
    <div ref="root" class="company-approval-island">
        <dialog ref="dialog" class="company-approval-dialog" aria-labelledby="company-approval-title" @close="onDialogClose">
            <form ref="form" class="company-approval-dialog__form" method="POST" enctype="multipart/form-data" @submit="onSubmit">
                <h3 id="company-approval-title">Aprobar {{ companyName }}</h3>
                <p>La aprobación habilitará el acceso privado de la empresa.</p>

                <p v-if="message" class="message message-error" role="alert" tabindex="-1">{{ message }}</p>
                <p v-if="state === 'submitting'" class="message" role="status">Validando el PDF y guardando la aprobación…</p>

                <input type="hidden" name="_csrf" :value="props.csrf">
                <label for="company-approval-number">Número de Convenio Marco
                    <input id="company-approval-number" type="text" name="numero_convenio" maxlength="100" required>
                </label>
                <label for="company-approval-pdf">Convenio firmado (PDF, máximo 10 MB)
                    <input id="company-approval-pdf" type="file" name="pdf_convenio" accept="application/pdf,.pdf" required>
                </label>

                <div class="company-approval-dialog__actions">
                    <button type="button" class="company-approval-dialog__cancel" @click="dialog?.close()">Cancelar</button>
                    <button type="submit" :disabled="state === 'submitting'">
                        {{ state === 'submitting' ? 'Aprobando…' : 'Aprobar empresa' }}
                    </button>
                </div>
            </form>
        </dialog>
    </div>
</template>
