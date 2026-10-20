import { withMermaid } from 'vitepress-plugin-mermaid'
import { defineConfig } from 'vitepress'

export default withMermaid(defineConfig({
  title: 'Sistema FCT - Portal de Arquitectura',
  description: 'Arquitectura, rutas y documentación para desarrollar Sistema FCT.',
  cleanUrls: true,
  appearance: 'dark',
  themeConfig: {
    nav: [
      { text: 'Visión General', link: '/' },
      { text: 'Arquitectura y Flujo de Datos', link: '/flujo-de-datos' },
      { text: 'Diagrama completo', link: '/diagrama-completo' },
      { text: 'Hoja de ruta de migración', link: '/roadmap' },
      { text: 'Roles y permisos', link: '/roles_y_permisos' },
      { text: 'Rutas y Contratos API', link: '/flujo-de-datos#catalogo-de-rutas-http' },
    ],
    sidebar: [
      {
        text: 'Visión General',
        items: [
          { text: 'Sistema FCT', link: '/' },
          { text: 'Instalación', link: '/instalacion' },
          { text: 'Guía para no técnicos', link: '/guia-del-codigo-para-no-tecnicos' },
        ],
      },
      {
        text: 'Componentes y seguridad',
        items: [
          { text: 'Disponibilidad', link: '/componentes/disponibilidad' },
          { text: 'Seguridad y antivirus', link: '/seguridad_y_antivirus' },
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
        ],
      },
      {
        text: 'Rutas y Contratos API',
        items: [
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
  mermaid: {
    theme: 'default',
    securityLevel: 'strict',
    flowchart: {
      useMaxWidth: false,
      nodeSpacing: 48,
      rankSpacing: 64,
    },
  },
  vite: {
    optimizeDeps: {
      include: ['fastdom', 'fastdom/extensions/fastdom-promised.js'],
    },
  },
}))
