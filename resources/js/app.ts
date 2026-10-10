import '../css/app.css';
import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { responsiveTable } from './responsive-table';

const pages = import.meta.glob<{ default: DefineComponent }>('./Pages/**/*.vue');

void createInertiaApp({
    title: (title) => title,
    resolve: async (name) => {
        const loader = pages[`./Pages/${name}.vue`];
        if (!loader) throw new Error(`Unknown Inertia page: ${name}`);
        return (await loader()).default;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .directive('responsive-table', responsiveTable)
            .mount(el);
    },
});
