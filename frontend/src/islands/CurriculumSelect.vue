<script setup lang="ts">
import { ref } from 'vue';

interface CurriculumChoice {
    id: number;
    nombre_especialidad: string;
}

const props = defineProps<{
    choices: string;
    selected?: string;
    selectId?: string;
}>();

const choices = JSON.parse(props.choices) as CurriculumChoice[];
const selected = ref(props.selected ?? '');
</script>

<template>
    <select :id="selectId || 'especialidad-id'" v-model="selected" name="especialidad_id" required>
        <option value="">-- Selecciona un ciclo formativo --</option>
        <option v-for="choice in choices" :key="choice.id" :value="String(choice.id)">
            {{ choice.nombre_especialidad }}
        </option>
    </select>
</template>
