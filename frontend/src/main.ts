import { createApp, type Component } from 'vue';
import { createPinia } from 'pinia';

const islands = import.meta.glob<{ default: Component }>('./islands/*.vue', {
    eager: true,
});
const pinia = createPinia();

document.querySelectorAll<HTMLElement>('[data-vue-island]').forEach((element) => {
    const name = element.dataset.vueIsland;
    if (!name || !/^[A-Za-z][A-Za-z0-9_-]*$/.test(name)) {
        console.error('El nombre de la isla Vue falta o no es válido.', element);
        return;
    }

    const component = islands[`./islands/${name}.vue`];
    if (!component) {
        console.error(`No se encontró la isla Vue "${name}".`);
        return;
    }

    const props = Object.fromEntries(
        Object.entries(element.dataset)
            .filter(([key]) => key.startsWith('prop'))
            .map(([key, value]) => [key.replace(/^prop/, '').replace(/^[A-Z]/, (letter) => letter.toLowerCase()), value]),
    );

    createApp(component.default, props).use(pinia).mount(element);
});
