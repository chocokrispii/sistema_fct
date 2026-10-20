<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { useStudentProfileStore } from '../stores/studentProfile';
import type { ProfileUpdatePayload } from '../types/profile';

interface ProfileValues {
    email: string;
    nombre: string;
    apellidos: string;
    fecha_nacimiento: string;
    dni: string;
    nss: string;
    telefono: string;
    direccion: string;
    codigo_postal: string;
    tutor_nombre: string;
    tutor_dni: string;
    tutor_nss: string;
    tutor_direccion: string;
    tutor_telefono: string;
    tutor_correo: string;
}

interface ProfileState {
    values: ProfileValues;
    fieldErrors: Record<string, string>;
    error: string;
    guardado: boolean;
    csrf: string;
}

const props = defineProps<{ state: string }>();
const initial = JSON.parse(props.state) as ProfileState;
const values = reactive<ProfileValues>({ ...initial.values });
const fieldErrors = reactive<Record<string, string>>({ ...initial.fieldErrors });
const touched = reactive<Record<string, boolean>>({});
const form = ref<HTMLFormElement | null>(null);
const serverError = ref(initial.error);
const profileStore = useStudentProfileStore();

const studentFields: Array<keyof ProfileValues> = [
    'nombre',
    'apellidos',
    'dni',
    'nss',
    'fecha_nacimiento',
    'email',
    'telefono',
    'direccion',
    'codigo_postal',
];
const guardianFields: Array<keyof ProfileValues> = [
    'tutor_nombre',
    'tutor_dni',
    'tutor_nss',
    'tutor_correo',
    'tutor_direccion',
    'tutor_telefono',
];

function fieldErrorId(field: keyof ProfileValues): string {
    return `profile-${field}-error`;
}

const today = new Date();
const todayIso = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
const isMinor = computed(() => {
    const age = ageOnDate(values.fecha_nacimiento);
    return age !== null && age < 18;
});

function ageOnDate(value: string): number | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
    if (!match) {
        return null;
    }

    const [, yearValue, monthValue, dayValue] = match;
    const year = Number(yearValue);
    const month = Number(monthValue);
    const day = Number(dayValue);
    const birth = new Date(year, month - 1, day);
    if (birth.getFullYear() !== year || birth.getMonth() !== month - 1 || birth.getDate() !== day) {
        return null;
    }

    const now = new Date();
    let age = now.getFullYear() - year;
    if (now.getMonth() + 1 < month || (now.getMonth() + 1 === month && now.getDate() < day)) {
        age -= 1;
    }
    return age;
}

function validDocument(value: string): boolean {
    const document = value.toUpperCase().replace(/[\s-]/g, '');
    const dni = /^(\d{8})([A-Z])$/.exec(document);
    const nie = /^([XYZ])(\d{7})([A-Z])$/.exec(document);
    let digits: number;
    let control: string;
    if (dni) {
        digits = Number(dni[1]);
        control = dni[2];
    } else if (nie) {
        digits = Number(({ X: '0', Y: '1', Z: '2' })[nie[1]] + nie[2]);
        control = nie[3];
    } else {
        return false;
    }
    return 'TRWAGMYFPDXBNJZSQVHLCKE'[digits % 23] === control;
}

function validPhone(value: string): boolean {
    const digits = value.trim()
        .replace(/^(?:\+34|0034)\s*/i, '')
        .replace(/[\s().-]/g, '');
    return /^[6789]\d{8}$/.test(digits);
}

const personNamePattern = /^[\p{L}\p{M}][\p{L}\p{M} \t.'’\-]*$/u;
const nssPattern = /^[A-Za-z0-9][A-Za-z0-9 .-]{4,19}$/;
const postcodePattern = /^(?:0[1-9]|[1-4]\d|5[0-2])\d{3}$/;

function validateField(field: keyof ProfileValues): boolean {
    const value = values[field].trim();
    let message = '';

    switch (field) {
        case 'nombre':
            if (!personNamePattern.test(value) || value.length > 50) {
                message = 'Introduce un nombre válido de hasta 50 caracteres.';
            }
            break;
        case 'apellidos':
            if (!personNamePattern.test(value) || value.length > 100) {
                message = 'Introduce apellidos válidos de hasta 100 caracteres.';
            }
            break;
        case 'dni':
        case 'tutor_dni':
            if ((field === 'dni' || (isMinor.value && value !== '')) && !validDocument(value)) {
                message = 'Introduce un DNI/NIE con una letra de control válida.';
            }
            break;
        case 'nss':
        case 'tutor_nss':
            if ((field === 'nss' || isMinor.value) && !nssPattern.test(value)) {
                message = 'Introduce entre 5 y 20 caracteres alfanuméricos, espacios, puntos o guiones.';
            }
            break;
        case 'fecha_nacimiento': {
            const age = ageOnDate(value);
            if (age === null) {
                message = 'Introduce una fecha de nacimiento válida.';
            } else if (value > todayIso) {
                message = 'La fecha no puede ser futura.';
            } else if (age < 16) {
                message = 'El alumno debe tener al menos 16 años.';
            }
            break;
        }
        case 'email':
        case 'tutor_correo':
            if (
                (field === 'email' || isMinor.value)
                && (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) || value.length > 100)
            ) {
                message = 'Introduce un correo electrónico válido (máximo 100 caracteres).';
            }
            break;
        case 'telefono':
        case 'tutor_telefono':
            if ((field === 'telefono' || isMinor.value) && !validPhone(value)) {
                message = 'Introduce un teléfono español válido de nueve cifras, con o sin prefijo +34.';
            }
            break;
        case 'direccion':
        case 'tutor_direccion':
            if (
                (field === 'direccion' || isMinor.value)
                && (value === '' || value.length > 255 || /[\p{Cc}]/u.test(value))
            ) {
                message = 'La dirección es obligatoria, no puede superar 255 caracteres ni contener controles.';
            }
            break;
        case 'codigo_postal':
            if (!postcodePattern.test(value)) {
                message = 'Introduce un código postal español válido de cinco cifras (provincias 01–52).';
            }
            break;
        case 'tutor_nombre':
            if (isMinor.value && (!personNamePattern.test(value) || value.length > 100)) {
                message = 'Introduce un nombre completo válido para el tutor.';
            }
            break;
    }

    if (message) {
        fieldErrors[field] = message;
    } else {
        delete fieldErrors[field];
    }
    return message === '';
}

function onInput(field: keyof ProfileValues): void {
    if (touched[field] || fieldErrors[field]) {
        touched[field] = true;
        validateField(field);
    }
}

async function saveProfile(): Promise<void> {
    const fieldsToValidate = isMinor.value ? [...studentFields, ...guardianFields] : studentFields;
    const invalidFields = fieldsToValidate.filter((field) => !validateField(field));
    if (invalidFields.length > 0) {
        const input = form.value?.elements.namedItem(invalidFields[0]);
        if (input instanceof HTMLElement) {
            input.focus();
        }
        return;
    }

    await profileStore.save({ ...values } satisfies ProfileUpdatePayload, initial.csrf);
    if (profileStore.status === 'saved') {
        serverError.value = '';
    }
}
</script>

<template>
    <section class="student-registration" aria-labelledby="student-profile-title">
        <div class="student-registration__intro">
            <span class="student-registration__eyebrow">Área de alumnado</span>
            <h2 id="student-profile-title">Editar mis datos personales</h2>
            <p>Actualiza tu contacto y datos personales. El ciclo formativo lo gestiona el centro para conservar la trazabilidad académica.</p>
        </div>

        <p v-if="initial.guardado || profileStore.status === 'saved'" class="message message-success" role="status">Tus datos se han actualizado.</p>
        <p v-if="serverError || profileStore.error" class="message message-error student-registration__error" role="alert">{{ serverError || profileStore.error }}</p>

        <form
            ref="form"
            class="student-registration__form"
            method="POST"
            action="index.php?action=perfil-alumno"
            data-student-profile
            novalidate
            :aria-busy="profileStore.status === 'saving'"
            @submit.prevent="saveProfile"
        >
            <input type="hidden" name="_csrf" :value="initial.csrf">
            <fieldset class="student-registration__section">
                <legend>Datos personales</legend>
                <div class="student-registration__grid">
                    <div class="student-registration__field">
                        <label for="profile-name">Nombre</label>
                        <input id="profile-name" v-model="values.nombre" name="nombre" maxlength="50" autocomplete="given-name" :aria-invalid="fieldErrors.nombre ? 'true' : 'false'" :aria-describedby="fieldErrors.nombre ? fieldErrorId('nombre') : undefined" required @input="onInput('nombre')" @blur="validateField('nombre')">
                        <small v-if="fieldErrors.nombre" :id="fieldErrorId('nombre')" data-field-error role="alert">{{ fieldErrors.nombre }}</small>
                    </div>
                    <div class="student-registration__field">
                        <label for="profile-surname">Apellidos</label>
                        <input id="profile-surname" v-model="values.apellidos" name="apellidos" maxlength="100" autocomplete="family-name" :aria-invalid="fieldErrors.apellidos ? 'true' : 'false'" :aria-describedby="fieldErrors.apellidos ? fieldErrorId('apellidos') : undefined" required @input="onInput('apellidos')" @blur="validateField('apellidos')">
                        <small v-if="fieldErrors.apellidos" :id="fieldErrorId('apellidos')" data-field-error role="alert">{{ fieldErrors.apellidos }}</small>
                    </div>
                    <div class="student-registration__field">
                        <label for="profile-dni">DNI/NIE</label>
                        <input id="profile-dni" v-model="values.dni" name="dni" maxlength="10" autocomplete="off" :aria-invalid="fieldErrors.dni ? 'true' : 'false'" :aria-describedby="fieldErrors.dni ? fieldErrorId('dni') : undefined" required @input="onInput('dni')" @blur="validateField('dni')">
                        <small>Se valida la letra de control del DNI/NIE.</small>
                        <small v-if="fieldErrors.dni" :id="fieldErrorId('dni')" data-field-error role="alert">{{ fieldErrors.dni }}</small>
                    </div>
                    <div class="student-registration__field">
                        <label for="profile-nss">Número de Seguridad Social</label>
                        <input id="profile-nss" v-model="values.nss" name="nss" maxlength="20" pattern="[A-Za-z0-9][A-Za-z0-9 .-]{4,19}" :aria-invalid="fieldErrors.nss ? 'true' : 'false'" :aria-describedby="fieldErrors.nss ? fieldErrorId('nss') : undefined" required @input="onInput('nss')" @blur="validateField('nss')">
                        <small>De 5 a 20 caracteres alfanuméricos, espacios, puntos o guiones.</small>
                        <small v-if="fieldErrors.nss" :id="fieldErrorId('nss')" data-field-error role="alert">{{ fieldErrors.nss }}</small>
                    </div>
                    <div class="student-registration__field">
                        <label for="profile-birth-date">Fecha de nacimiento</label>
                        <input id="profile-birth-date" v-model="values.fecha_nacimiento" type="date" name="fecha_nacimiento" :max="todayIso" autocomplete="bday" :aria-invalid="fieldErrors.fecha_nacimiento ? 'true' : 'false'" :aria-describedby="fieldErrors.fecha_nacimiento ? fieldErrorId('fecha_nacimiento') : undefined" required @input="onInput('fecha_nacimiento')" @blur="validateField('fecha_nacimiento')">
                        <small v-if="fieldErrors.fecha_nacimiento" :id="fieldErrorId('fecha_nacimiento')" data-field-error role="alert">{{ fieldErrors.fecha_nacimiento }}</small>
                    </div>
                    <div class="student-registration__field">
                        <label for="profile-email">Correo electrónico</label>
                        <input id="profile-email" v-model="values.email" type="email" name="email" maxlength="100" autocomplete="email" :aria-invalid="fieldErrors.email ? 'true' : 'false'" :aria-describedby="fieldErrors.email ? fieldErrorId('email') : undefined" required @input="onInput('email')" @blur="validateField('email')">
                        <small v-if="fieldErrors.email" :id="fieldErrorId('email')" data-field-error role="alert">{{ fieldErrors.email }}</small>
                    </div>
                    <div class="student-registration__field">
                        <label for="profile-phone">Teléfono</label>
                        <input id="profile-phone" v-model="values.telefono" type="tel" name="telefono" maxlength="20" autocomplete="tel" :aria-invalid="fieldErrors.telefono ? 'true' : 'false'" :aria-describedby="fieldErrors.telefono ? fieldErrorId('telefono') : undefined" required @input="onInput('telefono')" @blur="validateField('telefono')">
                        <small>Acepta 9 cifras españolas, con o sin prefijo +34.</small>
                        <small v-if="fieldErrors.telefono" :id="fieldErrorId('telefono')" data-field-error role="alert">{{ fieldErrors.telefono }}</small>
                    </div>
                    <div class="student-registration__field">
                        <label for="profile-address">Dirección</label>
                        <input id="profile-address" v-model="values.direccion" name="direccion" maxlength="255" autocomplete="street-address" :aria-invalid="fieldErrors.direccion ? 'true' : 'false'" :aria-describedby="fieldErrors.direccion ? fieldErrorId('direccion') : undefined" required @input="onInput('direccion')" @blur="validateField('direccion')">
                        <small v-if="fieldErrors.direccion" :id="fieldErrorId('direccion')" data-field-error role="alert">{{ fieldErrors.direccion }}</small>
                    </div>
                    <div class="student-registration__field">
                        <label for="profile-postcode">Código postal</label>
                        <input id="profile-postcode" v-model="values.codigo_postal" name="codigo_postal" inputmode="numeric" maxlength="5" pattern="(?:0[1-9]|[1-4][0-9]|5[0-2])[0-9]{3}" autocomplete="postal-code" :aria-invalid="fieldErrors.codigo_postal ? 'true' : 'false'" :aria-describedby="fieldErrors.codigo_postal ? fieldErrorId('codigo_postal') : undefined" required @input="onInput('codigo_postal')" @blur="validateField('codigo_postal')">
                        <small>Código postal español de cinco cifras (provincias 01–52).</small>
                        <small v-if="fieldErrors.codigo_postal" :id="fieldErrorId('codigo_postal')" data-field-error role="alert">{{ fieldErrors.codigo_postal }}</small>
                    </div>
                </div>
            </fieldset>

            <fieldset class="student-registration__section">
                <legend>Datos del tutor legal</legend>
                <p>Completa esta sección si eres menor de 18 años. Si los datos de tu expediente son antiguos o están incompletos, puedes actualizarlos aquí.</p>
                <div class="student-registration__grid">
                    <div class="student-registration__field">
                        <label for="profile-guardian-name">Nombre completo</label>
                        <input id="profile-guardian-name" v-model="values.tutor_nombre" name="tutor_nombre" maxlength="100" autocomplete="off" :required="isMinor" :aria-invalid="fieldErrors.tutor_nombre ? 'true' : 'false'" :aria-describedby="fieldErrors.tutor_nombre ? fieldErrorId('tutor_nombre') : undefined" @input="onInput('tutor_nombre')" @blur="validateField('tutor_nombre')">
                        <small v-if="fieldErrors.tutor_nombre" :id="fieldErrorId('tutor_nombre')" data-field-error role="alert">{{ fieldErrors.tutor_nombre }}</small>
                    </div>
                    <div class="student-registration__field">
                        <label for="profile-guardian-dni">DNI/NIE</label>
                        <input id="profile-guardian-dni" v-model="values.tutor_dni" name="tutor_dni" maxlength="20" autocomplete="off" :required="isMinor" :aria-invalid="fieldErrors.tutor_dni ? 'true' : 'false'" :aria-describedby="fieldErrors.tutor_dni ? fieldErrorId('tutor_dni') : undefined" @input="onInput('tutor_dni')" @blur="validateField('tutor_dni')">
                        <small v-if="fieldErrors.tutor_dni" :id="fieldErrorId('tutor_dni')" data-field-error role="alert">{{ fieldErrors.tutor_dni }}</small>
                    </div>
                    <div class="student-registration__field">
                        <label for="profile-guardian-nss">Número de Seguridad Social</label>
                        <input id="profile-guardian-nss" v-model="values.tutor_nss" name="tutor_nss" maxlength="20" autocomplete="off" :required="isMinor" :aria-invalid="fieldErrors.tutor_nss ? 'true' : 'false'" :aria-describedby="fieldErrors.tutor_nss ? fieldErrorId('tutor_nss') : undefined" @input="onInput('tutor_nss')" @blur="validateField('tutor_nss')">
                        <small v-if="fieldErrors.tutor_nss" :id="fieldErrorId('tutor_nss')" data-field-error role="alert">{{ fieldErrors.tutor_nss }}</small>
                    </div>
                    <div class="student-registration__field">
                        <label for="profile-guardian-email">Correo electrónico</label>
                        <input id="profile-guardian-email" v-model="values.tutor_correo" type="email" name="tutor_correo" maxlength="100" autocomplete="off" :required="isMinor" :aria-invalid="fieldErrors.tutor_correo ? 'true' : 'false'" :aria-describedby="fieldErrors.tutor_correo ? fieldErrorId('tutor_correo') : undefined" @input="onInput('tutor_correo')" @blur="validateField('tutor_correo')">
                        <small v-if="fieldErrors.tutor_correo" :id="fieldErrorId('tutor_correo')" data-field-error role="alert">{{ fieldErrors.tutor_correo }}</small>
                    </div>
                    <div class="student-registration__field">
                        <label for="profile-guardian-phone">Teléfono</label>
                        <input id="profile-guardian-phone" v-model="values.tutor_telefono" type="tel" name="tutor_telefono" maxlength="20" autocomplete="off" :required="isMinor" :aria-invalid="fieldErrors.tutor_telefono ? 'true' : 'false'" :aria-describedby="fieldErrors.tutor_telefono ? fieldErrorId('tutor_telefono') : undefined" @input="onInput('tutor_telefono')" @blur="validateField('tutor_telefono')">
                        <small v-if="fieldErrors.tutor_telefono" :id="fieldErrorId('tutor_telefono')" data-field-error role="alert">{{ fieldErrors.tutor_telefono }}</small>
                    </div>
                    <div class="student-registration__field">
                        <label for="profile-guardian-address">Dirección</label>
                        <input id="profile-guardian-address" v-model="values.tutor_direccion" name="tutor_direccion" maxlength="255" autocomplete="off" :required="isMinor" :aria-invalid="fieldErrors.tutor_direccion ? 'true' : 'false'" :aria-describedby="fieldErrors.tutor_direccion ? fieldErrorId('tutor_direccion') : undefined" @input="onInput('tutor_direccion')" @blur="validateField('tutor_direccion')">
                        <small v-if="fieldErrors.tutor_direccion" :id="fieldErrorId('tutor_direccion')" data-field-error role="alert">{{ fieldErrors.tutor_direccion }}</small>
                    </div>
                </div>
            </fieldset>

            <div class="student-registration__actions">
                <p>Los cambios se guardan en tu expediente privado.</p>
                <button class="student-registration__submit" type="submit" :disabled="profileStore.status === 'saving'">
                    {{ profileStore.status === 'saving' ? 'Guardando…' : 'Guardar cambios' }}
                </button>
                <a class="student-portal__text-action" href="index.php?action=portal-alumno">Cancelar</a>
            </div>
        </form>
    </section>
</template>
