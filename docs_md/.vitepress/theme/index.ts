import DefaultTheme from 'vitepress/theme'
import { defineComponent, h, type App } from 'vue'
import { useData } from 'vitepress'
import MermaidLazy from './MermaidLazy.vue'
import './custom.css'

export default {
  extends: DefaultTheme,
  enhanceApp({ app }: { app: App }) {
    app.component('MermaidLazy', MermaidLazy)
  },
  Layout: defineComponent({
    name: 'DocumentationLayout',
    setup() {
      const { frontmatter } = useData()

      return () => h(DefaultTheme.Layout, {
        class: {
          'wide-layout': frontmatter.value.wide === true,
          'roadmap-layout': frontmatter.value.roadmap === true,
        },
      })
    },
  }),
}
