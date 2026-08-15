import { Layout } from 'antd';
import { useState, ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import Sidebar from '../Components/Sidebar';
import Header from '../Components/Header';
import type { PageProps } from '../Types';

const { Content } = Layout;

interface MainLayoutProps {
    children: ReactNode;
}

export default function MainLayout({ children }: MainLayoutProps) {
    const [collapsed, setCollapsed] = useState(false);
    const { auth, menus, url } = usePage<PageProps>().props as any;
    const currentUrl = usePage().url;

    return (
        <Layout style={{ minHeight: '100vh', direction: 'rtl' }}>
            {/* سایدبار سمت راست */}
            <Sidebar
                menus={menus || []}
                collapsed={collapsed}
                currentUrl={currentUrl}
            />

            {/* بخش اصلی (هدر + محتوا) */}
            <Layout style={{ background: '#f0f2f5' }}>
                {/* هدر بالا */}
                <Header
                    user={auth?.user}
                    collapsed={collapsed}
                    onToggleCollapse={() => setCollapsed(!collapsed)}
                />

                {/* محتوای اصلی صفحه */}
                <Content
                    style={{
                        margin: 24,
                        padding: 24,
                        background: '#fff',
                        borderRadius: 12,
                        minHeight: 'calc(100vh - 112px)',
                        boxShadow: '0 2px 8px rgba(0,0,0,0.06)',
                    }}
                >
                    {children}
                </Content>
            </Layout>
        </Layout>
    );
}