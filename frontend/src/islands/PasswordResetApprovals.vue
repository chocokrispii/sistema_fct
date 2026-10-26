<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { requestApi } from '../api/client';

const props = defineProps<{ csrfToken: string }>();
const root = ref<HTMLElement | null>(null);
const message = ref('');
const messageType = ref<'status' | 'error'>('status');
let container: HTMLElement | null = null;

async function onSubmit(event: SubmitEvent): Promise<void> {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }
    const action = form.dataset.resetPasswordAction;
    if (action !== 'approve' && action !== 'reject') {
        return;
    }

    event.preventDefault();
    const id = new FormData(form).get('id');
    if (typeof id !== 'string' || !/^[1-9]\d*$/.test(id)) {
        return;
    }

    const row = form.closest<HTMLTableRowElement>('tr');
    const buttons = Array.from(form.closest('td')?.querySelectorAll<HTMLButtonElement>('button') ?? []);
    form.setAttribute('aria-busy', 'true');
    buttons.forEach((button) => {
        button.disabled = true;
    });
    message.value = '';

    try {
        await requestApi<{ id: number; estado: string; message: string }>(
            `api/admin/solicitudes-password/${id}/${action === 'approve' ? 'aprobar' : 'rechazar'}`,
            {
                method: 'POST',
                headers: { 'X-CSRF-Token': props.csrfToken },
                body: JSON.stringify({}),
            },
        );
        row?.remove();
        messageType.value = 'status';
        message.value = action === 'approve'
            ? 'Solicitud aprobada; se envió el enlace de restablecimiento.'
            : 'Solicitud rechazada.';
        if (container?.querySelectorAll('tbody tr').length === 0) {
            const table = container.querySelector('.table-responsive');
            const empty = document.createElement('p');
            empty.className = 'password-reset-requests__empty';
            empty.textContent = 'No hay solicitudes pendientes.';
            table?.replaceWith(empty);
        }
    } catch (cause) {
        messageType.value = 'error';
        message.value = cause instanceof Error
            ? cause.message
            : 'No se pudo actualizar la solicitud.';
        await nextTick();
        root.value?.querySelector<HTMLElement>('[role="alert"]')?.focus();
    } finally {
        form.removeAttribute('aria-busy');
        buttons.forEach((button) => {
            button.disabled = false;
        });
    }
}

onMounted(() => {
    container = root.value?.closest<HTMLElement>('[data-password-reset-requests]') ?? null;
    if (!container) {
        throw new Error('No se encontró el panel de solicitudes de restablecimiento.');
    }
    container.addEventListener('submit', onSubmit);
});

onBeforeUnmount(() => {
    container?.removeEventListener('submit', onSubmit);
});
</script>

<template>
    <div ref="root" class="password-reset-approvals__feedback" aria-live="polite">
        <p
            v-if="message"
            class="message"
            :class="messageType === 'error' ? 'message-error' : 'message-success'"
            :role="messageType === 'error' ? 'alert' : 'status'"
            tabindex="-1"
        >
            {{ message }}
        </p>
    </div>
</template>
