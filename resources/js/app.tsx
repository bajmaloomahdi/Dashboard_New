import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { ConfigProvider } from 'antd';
import faIR from 'antd/locale/fa_IR';
import { THEME } from './theme';

const appName = import.meta.env.VITE_APP_NAME || 'داشبورد مدیریت';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.tsx', { eager: true });
        const page = (pages as Record<string, any>)[`./Pages/${name}.tsx`];
        if (!page) {
            throw new Error(`Page ${name} not found`);
        }
        return page.default;
    },
    setup({ el, App, props }) {
        const root = createRoot(el);
        root.render(
            <ConfigProvider
                locale={faIR}
                direction="rtl"
                theme={{
                    token: {
                        colorPrimary: THEME?.primary || '#667eea',
                        fontFamily: 'Vazirmatn, system-ui, -apple-system, Segoe UI, Roboto, sans-serif',
                        borderRadius: 8,
                    },
                }}
            >
                <App {...props} />
            </ConfigProvider>
        );
    },
    progress: {
        color: THEME?.primary || '#667eea',
        showSpinner: true,
    },
});