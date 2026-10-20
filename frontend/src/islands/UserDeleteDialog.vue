<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{ csrf: string }>();
const dialog = ref<HTMLDialogElement | null>(null);
const userId = ref('');
const userEmail = ref('');

function openForUser(event: MouseEvent): void {
    if (!(event.target instanceof Element)) {
        return;
    }

    const trigger = event.target.closest<HTMLButtonElement>('[data-delete-user]');
    if (!trigger) {
        return;
    }

    const id = trigger.dataset.deleteUser;
    const email = trigger.dataset.deleteEmail;
    if (!id || !/^\d+$/.test(id) || !email || !dialog.value) {
        return;
    }

    userId.value = id;
    userEmail.value = email;
    dialog.value.showModal();
}

onMounted(() => {
    document.addEventListener('click', openForUser);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', openForUser);
});
</script>

<template>
    <dialog
        ref="dialog"
        class="user-delete-dialog"
        aria-labelledby="user-delete-title"
        aria-describedby="user-delete-description"
    >
        <form method="POST" action="index.php?action=eliminar-usuario">
            <input type="hidden" name="_csrf" :value="props.csrf">
            <input type="hidden" name="usuario_id" :value="userId">
            <h3 id="user-delete-title">¿Estás seguro?</h3>
            <p id="user-delete-description">
                Se eliminará permanentemente <strong>{{ userEmail }}</strong>. Esta acción no se puede deshacer.
            </p>
            <div class="user-delete-dialog__actions">
                <button type="button" class="user-delete-dialog__cancel" @click="dialog?.close()">
                    No, volver.
                </button>
                <button type="submit" class="user-delete-dialog__confirm">Sí, eliminar</button>
            </div>
        </form>
    </dialog>
</template>
