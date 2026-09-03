import { Layout, Drawer, Grid } from 'antd';
import { useState, useEffect, ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import Sidebar, { SidebarMenu } from '../Components/Sidebar';
import Header from '../Components/Header';

const { Content, Footer } = Layout;
const { useBreakpoint } = Grid;

interface MainLayoutProps {
    children: ReactNode;
}

export default function MainLayout({ children }: MainLayoutProps) {
    // فشرده کردن Sider در دسکتاپ (رفتار فعلی، بدون تغییر)
    const [collapsed, setCollapsed] = useState(false);
    // باز/بسته بودن منوی کشویی در موبایل
    const [drawerOpen, setDrawerOpen] = useState(false);

    const { auth, menus, appVersion } = usePage().props as any;
    const currentUrl = usePage().url;

    // زیر breakpoint «lg» (۹۹۲px) حالت موبایل/تبلت است.
    // قبل از اندازه‌گیری (screens.lg === undefined) دسکتاپ فرض می‌شود تا در دسکتاپ پرشی رخ ندهد.
    const screens = useBreakpoint();
    const isMobile = screens.lg === false;

    // برگشت به دسکتاپ ⇒ Drawer بسته شود
    useEffect(() => {
        if (!isMobile && drawerOpen) {
            setDrawerOpen(false);
        }
    }, [isMobile, drawerOpen]);

    // دکمه‌ی همبرگر: در موبایل Drawer، در دسکتاپ collapse
    const handleToggle = () => {
        if (isMobile) {
            setDrawerOpen((open) => !open);
        } else {
            setCollapsed((value) => !value);
        }
    };

    return (
        <Layout style={{ minHeight: '100vh', direction: 'rtl' }}>
            {/* Sider دسکتاپ — در موبایل با CSS مخفی می‌شود */}
            <Sidebar menus={menus || []} collapsed={collapsed} currentUrl={currentUrl} />

            {/* منوی کشویی موبایل — از سمت راست (RTL) */}
            <Drawer
                placement="right"
                open={drawerOpen}
                onClose={() => setDrawerOpen(false)}
                width={260}
                closable={false}
                className="app-mobile-nav-drawer"
                styles={{ body: { padding: 0, background: '#001529' } }}
            >
                <SidebarMenu
                    menus={menus || []}
                    currentUrl={currentUrl}
                    onNavigate={() => setDrawerOpen(false)}
                />
            </Drawer>

            <Layout style={{ background: '#f0f2f5', minWidth: 0 }}>
                <Header
                    user={auth?.user}
                    collapsed={collapsed}
                    isMobile={isMobile}
                    onToggleCollapse={handleToggle}
                />

                <Content
                    className="app-main-content"
                    style={{
                        margin: '24px 24px 0 24px',
                        padding: 24,
                        background: '#fff',
                        borderRadius: 12,
                        minHeight: 'calc(100vh - 160px)',
                        boxShadow: '0 2px 8px rgba(0,0,0,0.06)',
                    }}
                >
                    {children}
                </Content>

                <Footer
                    className="app-shell-footer"
                    style={{
                        textAlign: 'center',
                        color: '#9CA3AF',
                        fontSize: 12,
                        padding: '12px 24px',
                    }}
                >
                    پنل مدیریت سازمانی | نسخه {appVersion || '1.0.0'}
                </Footer>
            </Layout>

            <style>{`
                /* موبایل / تبلت (کمتر از breakpoint «lg» انت‌دیزاین) */
                @media (max-width: 991px) {
                    .app-desktop-sider { display: none !important; }
                    .app-main-content {
                        margin: 12px !important;
                        padding: 14px !important;
                    }
                    .app-shell-footer { padding: 10px 12px !important; }
                }
                @media (max-width: 575px) {
                    .app-main-content {
                        margin: 8px !important;
                        padding: 10px !important;
                        border-radius: 10px !important;
                    }
                }
            `}</style>
        </Layout>
    );
}
