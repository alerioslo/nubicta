import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import './icons';
import AppLayout from './Layouts/AppLayout.vue';

createInertiaApp({
    title: (title) => (title ? `${title} - ERP` : 'ERP'),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        const page = pages[`./Pages/${name}.vue`];
        if (!page) {
            throw new Error(`Página no encontrada: ${name}`);
        }
        if (page.default.layout === undefined && !name.startsWith('Auth/')) {
            page.default.layout = AppLayout;
        }
        return page;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .component('Icon', FontAwesomeIcon)
            .mount(el);
    },
    progress: { color: '#009dff' },
});
