<script setup lang="ts">
import { computed, ref } from 'vue';

const props = defineProps<{ value?: string }>();
const value = ref(props.value ?? '');
const tags = computed(() => [...new Set(
    value.value
        .split(',')
        .map((tag) => tag.trim())
        .filter((tag) => tag.length > 0),
)]);
</script>

<template>
    <div class="technology-tags">
        <input
            id="offer-technologies"
            v-model="value"
            type="text"
            name="tecnologias"
            maxlength="500"
            placeholder="PHP, Vue, MySQL"
            aria-describedby="offer-technologies-help"
        >
        <div v-if="tags.length" class="technology-tags__list" role="list" aria-label="Etiquetas de tecnologías">
            <span v-for="tag in tags" :key="tag" class="technology-tags__tag" role="listitem">{{ tag }}</span>
        </div>
    </div>
</template>
