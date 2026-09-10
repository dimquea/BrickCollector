import './bootstrap';
import '../css/app.css';
import 'bootstrap';

import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { setTranslations, t, tChoice } from './i18n';

createInertiaApp({
    title: (title) => (title ? `${title} — BrickCollector` : 'BrickCollector'),

    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });

        return pages[`./Pages/${name}.vue`];
    },

    setup({ el, App, props, plugin }) {
        setTranslations(props.initialPage.props.translations, props.initialPage.props.locale);

        // Shared props are re-sent on every visit; keep the dictionary in step
        // so a language switch takes effect without a full page load.
        router.on('success', (event) => {
            setTranslations(event.detail.page.props.translations, event.detail.page.props.locale);
        });

        const app = createApp({ render: () => h(App, props) }).use(plugin);

        app.config.globalProperties.$t = t;
        app.config.globalProperties.$tChoice = tChoice;

        app.mount(el);
    },

    progress: { color: '#0d6efd' },
});
