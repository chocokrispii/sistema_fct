import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Sistema FCT - Portal de Arquitectura',
  description: 'Arquitectura, rutas y documentación para desarrollar Sistema FCT.',
  cleanUrls: true,
  ignoreDeadLinks: 'localhostLinks',
  appearance: 'dark',
  markdown: {
    config(md) {
      const renderCodeFence = md.renderer.rules.fence
      md.renderer.rules.fence = (tokens, index, options, env, renderer) => {
        const token = tokens[index]
        const language = token.info.trim()

        if (language !== 'mermaid' && language !== 'mmd') {
          return renderCodeFence
            ? renderCodeFence(tokens, index, options, env, renderer)
            : renderer.renderToken(tokens, index, options)
        }

        return `<MermaidLazy id="mermaid-${index}" class="mermaid" graph="${encodeURIComponent(token.content)}"></MermaidLazy>`
      }
    },
  },
  themeConfig: {
    nav: [
      { text: 'Visión General', link: '/' },
      { text: 'Arquitectura y Flujo de Datos', link: '/flujo-de-datos' },
      { text: 'Diagrama completo', link: '/diagrama-completo' },
      { text: 'Comunicación frontend-backend', link: '/arquitectura/comunicacion-frontend' },
      { text: 'Hoja de ruta de migración', link: '/roadmap' },
      { text: 'Roles y permisos', link: '/roles_y_permisos' },
      { text: 'Paginación y consultas', link: '/optimizacion_consultas' },
      { text: 'Rutas y Contratos API', link: '/flujo-de-datos#catalogo-de-rutas-http' },
      { text: 'Integraciones', link: '/integraciones' },
    ],
    sidebar: [
      {
        text: 'Visión General',
        items: [
          { text: 'Sistema FCT', link: '/' },
          { text: 'Instalación', link: '/instalacion' },
          { text: 'Guía para no técnicos', link: '/guia-del-codigo-para-no-tecnicos' },
          { text: 'Guía de Integraciones', link: '/integraciones' },
        ],
      },
      {
        text: 'Componentes y seguridad',
        items: [
          { text: 'Disponibilidad', link: '/componentes/disponibilidad' },
          { text: 'Seguridad y antivirus', link: '/seguridad_y_antivirus' },
          { text: 'Integraciones y PostCSS', link: '/integraciones' },
          { text: 'Solución de problemas', link: '/troubleshooting' },
        ],
      },
      {
        text: 'Arquitectura y Flujo de Datos',
        items: [
          { text: 'Ciclo de vida', link: '/flujo-de-datos' },
          { text: 'Diagrama completo', link: '/diagrama-completo' },
          { text: 'Hoja de ruta de migración', link: '/roadmap' },
          { text: 'Roles y permisos', link: '/roles_y_permisos' },
          { text: 'Paginación y consultas', link: '/optimizacion_consultas' },
        ],
      },
      {
        text: 'Rutas y Contratos API',
        items: [
          { text: 'API REST tokenizada', link: '/api-rest' },
          { text: 'Catálogo de rutas HTTP', link: '/flujo-de-datos#catalogo-de-rutas-http' },
          { text: 'Ciclo de vida de los datos', link: '/flujo-de-datos#tabla-del-ciclo-de-vida-de-los-datos' },
          { text: 'Contratos y persistencia', link: '/flujo-de-datos#contratos-y-persistencia' },
        ],
      },
    ],
    socialLinks: [
      { icon: 'github', link: 'https://github.com/campusedx/Practicas_Desarrollo' },
    ],
    search: {
      provider: 'local',
    },
    footer: {
      message: 'Documentación de desarrollo de Sistema FCT.',
    },
  },
  vite: {
    publicDir: '../docs_pdf',
    optimizeDeps: {
      include: ['fastdom', 'fastdom/extensions/fastdom-promised.js'],
    },
  },
})
