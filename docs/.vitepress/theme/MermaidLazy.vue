<template>
  <div :class="props.class" :aria-busy="!svg && !failed">
    <div v-if="svg" v-html="svg"></div>
    <p v-else-if="failed" role="status">No se pudo representar el diagrama.</p>
    <p v-else role="status">Cargando diagrama…</p>
  </div>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { useData } from 'vitepress'
import { mermaidConfig } from './mermaid-config'

type MermaidEngine = typeof import('mermaid').default

const props = withDefaults(defineProps<{
  graph: string
  id: string
  class?: string
}>(), {
  class: 'mermaid',
})

const { page } = useData()
const mermaidPageTheme = page.value.frontmatter.mermaidTheme || ''
const svg = ref('')
const failed = ref(false)

let mermaid: MermaidEngine | undefined
let observer: MutationObserver | undefined
let renderGeneration = 0

const renderChart = async (): Promise<void> => {
  if (!mermaid) {
    return
  }

  const currentGeneration = ++renderGeneration
  const theme = document.documentElement.classList.contains('dark')
    ? 'dark'
    : mermaidPageTheme || mermaidConfig.theme

  try {
    mermaid.initialize({
      ...mermaidConfig,
      theme,
    })
    const rendered = await mermaid.render(props.id, decodeURIComponent(props.graph))
    if (currentGeneration === renderGeneration) {
      svg.value = rendered.svg
      failed.value = false
    }
  } catch (error) {
    console.error('No se pudo representar un diagrama Mermaid.', error)
    if (currentGeneration === renderGeneration) {
      svg.value = ''
      failed.value = true
    }
  }
}

onMounted(async () => {
  try {
    mermaid = (await import('mermaid')).default
    observer = new MutationObserver(() => {
      void renderChart()
    })
    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['class'],
    })
    await renderChart()
  } catch (error) {
    console.error('No se pudo cargar Mermaid.', error)
    failed.value = true
  }
})

onUnmounted(() => {
  renderGeneration += 1
  observer?.disconnect()
})
</script>
