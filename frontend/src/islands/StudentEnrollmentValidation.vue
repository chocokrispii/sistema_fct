<script setup lang="ts">
import { onMounted, ref } from 'vue';

const root = ref<HTMLElement | null>(null);
const message = ref('');

function field(form: HTMLFormElement, name: string): HTMLInputElement | HTMLSelectElement | null {
    const control = form.elements.namedItem(name);
    return control instanceof HTMLInputElement || control instanceof HTMLSelectElement ? control : null;
}

function normalizeDocument(value: string): string {
    return value.trim().toUpperCase().replace(/[\s.-]+/g, '');
}

function isDniNie(value: string): boolean {
    const document = normalizeDocument(value);
    const match = document.match(/^(\d{8}|[XYZ]\d{7})([A-Z])$/);
    if (!match) return false;
    const prefix = match[1].startsWith('X') ? '0' : match[1].startsWith('Y') ? '1' : match[1].startsWith('Z') ? '2' : '';
    const number = Number(prefix + (prefix ? match[1].slice(1) : match[1]));
    return match[2] === 'TRWAGMYFPDXBNJZSQVHLCKE'[number % 23];
}

function validate(form: HTMLFormElement): string[] {
    const errors: string[] = [];
    const type = field(form, 'tipo_documento');
    const document = field(form, 'dni');
    const name = field(form, 'nombre');
    const surnames = field(form, 'apellidos');
    const email = field(form, 'email');
    const cycle = field(form, 'especialidad_id');

    if (!type || !document || !name || !surnames || !email || !cycle) return ['No se pudo validar el formulario completo.'];
    const documentValue = normalizeDocument(document.value);
    const validDocument = type.value === 'PASAPORTE'
        ? /^[A-Z0-9]{6,15}$/.test(documentValue)
        : isDniNie(documentValue);

    if (!validDocument) {
        errors.push(type.value === 'PASAPORTE'
            ? 'El pasaporte debe tener entre 6 y 15 caracteres alfanuméricos.'
            : 'Introduce un NIF/NIE/DNI válido con su letra de control.');
    }
    if (name.value.trim() === '' || surnames.value.trim() === '') errors.push('Completa el nombre y los apellidos.');
    if (!email.checkValidity()) errors.push('Introduce un correo electrónico válido.');
    if (cycle.value === '') errors.push('Selecciona un ciclo formativo.');

    [type, document, name, surnames, email, cycle].forEach((control) => control.setAttribute('aria-invalid', errors.length > 0 ? 'true' : 'false'));
    return errors;
}

onMounted(() => {
    const form = root.value?.closest('form');
    if (!form) return;
    const type = field(form, 'tipo_documento');
    const document = field(form, 'dni');
    const updateDocumentLimit = (): void => {
        if (document instanceof HTMLInputElement) document.maxLength = type?.value === 'PASAPORTE' ? 15 : 20;
    };
    updateDocumentLimit();
    type?.addEventListener('change', updateDocumentLimit);
    form.addEventListener('submit', (event) => {
        const errors = validate(form);
        if (errors.length > 0) {
            event.preventDefault();
            message.value = errors.join(' ');
        }
    });
});
</script>

<template>
    <p ref="root" v-if="message" class="message message-error" role="alert">{{ message }}</p>
    <span v-else ref="root" class="student-enrollment-validation" aria-hidden="true"></span>
</template>
