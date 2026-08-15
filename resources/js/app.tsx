import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { ConfigProvider } from 'antd';
import faIR from 'antd/locale/fa_IR';

createInertiaApp({
    title: (title) => `${title} - Dashboard`,
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.tsx', { eager: true });
        const page = pages[`./Pages/${name}.tsx`];
        if (!page) {
            throw new Error(`Page ${name} not found`);
        }
        return (page as any).default;
    },
    setup({ el, App, props }) {
        createRoot(el).render(
            <ConfigProvider locale={faIR} direction="rtl">
                <App {...props} />
            </ConfigProvider>
        );
    },
});