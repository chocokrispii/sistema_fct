import DefaultTheme from 'vitepress/theme'
import { defineComponent, h } from 'vue'
import { useData } from 'vitepress'
import './custom.css'

export default {
  extends: DefaultTheme,
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
