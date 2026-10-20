<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { requestApi } from '../api/client';

interface AssignedCompany {
    nombre_empresa: string;
    categoria: string | null;
    nombre_especialidad: string;
    fecha_inicio: string;
    fecha_fin: string;
}

const company = ref<AssignedCompany | null>(null);
const message = ref('');
const loading = ref(true);

onMounted(async () => {
    try {
        company.value = await requestApi<AssignedCompany>('index.php?action=empresa-asignada');
    } catch (cause) {
        message.value = cause instanceof Error
            ? cause.message
            : 'No se pudo cargar la información de la asignación.';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <p v-if="loading" role="status">Cargando los detalles de la empresa asignada…</p>
    <p v-else-if="message" class="message message-error" role="alert">{{ message }}</p>
    <dl v-else-if="company" class="student-portal__details">
        <div><dt>Empresa asignada</dt><dd>{{ company.nombre_empresa }}</dd></div>
        <div><dt>Sector</dt><dd>{{ company.categoria || 'No indicado' }}</dd></div>
        <div><dt>Ciclo</dt><dd>{{ company.nombre_especialidad }}</dd></div>
        <div><dt>Periodo</dt><dd>{{ company.fecha_inicio }} – {{ company.fecha_fin }}</dd></div>
    </dl>
</template>
