import { Layout } from 'antd';
import { useState, ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import Sidebar from '../Components/Sidebar';
import Header from '../Components/Header';

const { Content, Footer } = Layout;

interface MainLayoutProps {
    children: ReactNode;
}

export default function MainLayout({ children }: MainLayoutProps) {
    const [collapsed, setCollapsed] = useState(false);
    const { auth, menus, appVersion } = usePage().props as any;
    const currentUrl = usePage().url;

    return (
        <Layout style={{ minHeight: '100vh', direction: 'rtl' }}>
            <Sidebar menus={menus || []} collapsed={collapsed} currentUrl={currentUrl} />

            <Layout style={{ background: '#f0f2f5' }}>
                <Header
                    user={auth?.user}
                    collapsed={collapsed}
                    onToggleCollapse={() => setCollapsed(!collapsed)}
                />

                <Content
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
        </Layout>
    );
}