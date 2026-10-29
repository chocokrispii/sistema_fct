<script setup lang="ts">
import { reactive, ref } from 'vue';

interface Site {
    nombre: string;
    direccion: string;
    telefono: string;
}

interface InitialData {
    nombreEmpresa: string;
    cifEmpresa: string;
    personaContacto: string;
    telefono: string;
    direccion: string;
    sedes: Site[];
}

const props = defineProps<{ csrfToken: string; initialData: string }>();
const submitting = ref(false);

function parseInitialData(raw: string): InitialData {
    try {
        const parsed = JSON.parse(raw) as Partial<InitialData>;
        return {
            nombreEmpresa: parsed.nombreEmpresa ?? '',
            cifEmpresa: parsed.cifEmpresa ?? '',
            personaContacto: parsed.personaContacto ?? '',
            telefono: parsed.telefono ?? '',
            direccion: parsed.direccion ?? '',
            sedes: Array.isArray(parsed.sedes) && parsed.sedes.length > 0
                ? parsed.sedes.map((site) => ({
                    nombre: site.nombre ?? '',
                    direccion: site.direccion ?? '',
                    telefono: site.telefono ?? '',
                }))
                : [{ nombre: '', direccion: '', telefono: '' }],
        };
    } catch {
        return {
            nombreEmpresa: '', cifEmpresa: '', personaContacto: '', telefono: '', direccion: '',
            sedes: [{ nombre: '', direccion: '', telefono: '' }],
        };
    }
}

const initial = parseInitialData(props.initialData);
const form = reactive({
    nombreEmpresa: initial.nombreEmpresa,
    cifEmpresa: initial.cifEmpresa,
    personaContacto: initial.personaContacto,
    telefono: initial.telefono,
    direccion: initial.direccion,
    sedes: initial.sedes,
});

function addSite(): void {
    form.sedes.push({ nombre: '', direccion: '', telefono: '' });
}

function removeSite(index: number): void {
    if (form.sedes.length > 1) {
        form.sedes.splice(index, 1);
    }
}

function onSubmit(): void {
    submitting.value = true;
}
</script>

<template>
    <form class="page-form company-onboarding__form" method="POST" action="index.php?action=onboarding-empresa" @submit="onSubmit">
        <input type="hidden" name="_csrf" :value="props.csrfToken">

        <fieldset>
            <legend>Datos fiscales y representante</legend>
            <label>Razón social
                <input v-model.trim="form.nombreEmpresa" type="text" name="nombre_empresa" maxlength="150" autocomplete="organization" required>
            </label>
            <label>NIF (Persona Jurídica)
                <input v-model.trim="form.cifEmpresa" type="text" name="cif_empresa" maxlength="20" autocomplete="off" required>
                <small>Debe ser un NIF jurídico válido, por ejemplo B12345674.</small>
            </label>
            <label>Representante legal
                <input v-model.trim="form.personaContacto" type="text" name="persona_contacto" maxlength="150" autocomplete="name" required>
            </label>
            <label>Teléfono de contacto
                <input v-model.trim="form.telefono" type="tel" name="telefono" maxlength="20" autocomplete="tel" required>
            </label>
            <label>Dirección fiscal
                <input v-model.trim="form.direccion" type="text" name="direccion" maxlength="255" autocomplete="street-address" required>
            </label>
        </fieldset>

        <fieldset>
            <legend>Sedes</legend>
            <p>Registra al menos una sede. Podrás añadir o modificar las sedes más adelante.</p>
            <article v-for="(site, index) in form.sedes" :key="index" class="company-onboarding__site">
                <h3>Sede {{ index + 1 }}</h3>
                <label>Nombre
                    <input v-model.trim="site.nombre" :name="`sedes[${index}][nombre]`" type="text" maxlength="150" required>
                </label>
                <label>Dirección
                    <input v-model.trim="site.direccion" :name="`sedes[${index}][direccion]`" type="text" maxlength="255" required>
                </label>
                <label>Teléfono <span>(opcional)</span>
                    <input v-model.trim="site.telefono" :name="`sedes[${index}][telefono]`" type="tel" maxlength="20">
                </label>
                <button v-if="form.sedes.length > 1" type="button" class="company-onboarding__remove" @click="removeSite(index)">Eliminar sede</button>
            </article>
            <button type="button" class="company-onboarding__add" @click="addSite">Añadir otra sede</button>
        </fieldset>

        <button type="submit" :disabled="submitting" :aria-busy="submitting">
            {{ submitting ? 'Guardando…' : 'Guardar y continuar' }}
        </button>
    </form>
</template>
