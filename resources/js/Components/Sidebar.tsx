import { Menu, Layout } from 'antd';
import { router } from '@inertiajs/react';
import {
    DashboardOutlined,
    FileTextOutlined,
    SettingOutlined,
    TeamOutlined,
    SafetyOutlined,
    BarChartOutlined,
    FolderOutlined,
    AppstoreOutlined,
} from '@ant-design/icons';
import { useMemo } from 'react';
import type { Menu as MenuType, MenuTree } from '../Types';
import { buildMenuTree } from '../Utils/menuHelpers';

const { Sider } = Layout;

interface SidebarProps {
    menus: MenuType[];
    collapsed: boolean;
    currentUrl: string;
}

/**
 * نگاشت کد منو به آیکون Ant Design
 */
const getMenuIcon = (menuCode: string) => {
    const iconMap: Record<string, JSX.Element> = {
        DASHBOARD: <DashboardOutlined />,
        REPORTS: <FileTextOutlined />,
        ADMIN: <SettingOutlined />,
        USERS: <TeamOutlined />,
        ROLES: <SafetyOutlined />,
        SETTINGS: <SettingOutlined />,
        CEO_REPORT: <BarChartOutlined />,
        SALE_REPORT: <BarChartOutlined />,
        INV_REPORT: <BarChartOutlined />,
        CHQ_REPORT: <BarChartOutlined />,
        ACC_REPORT: <BarChartOutlined />,
        Bazargani: <BarChartOutlined />,
    };
    return iconMap[menuCode] || <AppstoreOutlined />;
};

/**
 * تبدیل درخت منو به فرمت Ant Design Menu با آیکون‌های صحیح
 */
const buildAntMenuItems = (tree: MenuTree[]): any[] => {
    return tree.map((node) => {
        const isFolder = node.MenuKind === 'FOLDER' || (node.children && node.children.length > 0);

        const item: any = {
            key: isFolder ? `folder-${node.MenuID}` : (node.Url || `menu-${node.MenuID}`),
            label: node.MenuTitle,
            icon: getMenuIcon(node.MenuCode),
        };

        if (node.children && node.children.length > 0) {
            item.children = buildAntMenuItems(node.children);
        }

        return item;
    });
};

interface SidebarMenuProps {
    menus: MenuType[];
    currentUrl: string;
    /** فشرده بودن (فقط برای حالت Sider دسکتاپ) */
    collapsed?: boolean;
    /** بعد از یک ناوبری واقعی صدا زده می‌شود — برای بستن Drawer در موبایل */
    onNavigate?: () => void;
}

/**
 * لوگو + منوی داینامیک — بدون پوسته‌ی <Sider>.
 * در دسکتاپ داخل <Sider> و در موبایل داخل <Drawer> استفاده می‌شود.
 */
export function SidebarMenu({ menus, currentUrl, collapsed = false, onNavigate }: SidebarMenuProps) {
    // تبدیل flat به tree (فقط یک بار محاسبه میشه)
    const menuTree = useMemo(() => buildMenuTree(menus), [menus]);

    // تبدیل به فرمت Ant Design
    const menuItems = useMemo(() => buildAntMenuItems(menuTree), [menuTree]);

    // پیدا کردن منوی فعال
    const selectedKeys = [currentUrl];

    // باز نگه داشتن منوی والد اگر فرزندش انتخاب شده
    const openKeys = useMemo(() => {
        const keys: string[] = [];
        menuTree.forEach((node) => {
            if (node.children && node.children.length > 0) {
                const hasActiveChild = node.children.some((child) => child.Url === currentUrl);
                if (hasActiveChild) {
                    keys.push(`folder-${node.MenuID}`);
                }
            }
        });
        return keys;
    }, [menuTree, currentUrl]);

    /**
     * هندلر کلیک روی منو
     */
    const handleMenuClick = ({ key }: { key: string }) => {
        // اگر روی folder کلیک شد، navigate نکن (و Drawer را هم نبند)
        if (key.startsWith('folder-') || key.startsWith('menu-')) return;

        // 🔴 خروج از سیستم (POST درخواست)
        if (key === '/logout') {
            router.post('/logout');
            onNavigate?.();
            return;
        }

        // navigate به آدرس
        router.visit(key);
        onNavigate?.();
    };

    return (
        <>
            {/* لوگو */}
            <div
                style={{
                    height: 64,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                    color: '#fff',
                    fontSize: collapsed ? 20 : 18,
                    fontWeight: 'bold',
                    letterSpacing: 1,
                }}
            >
                {collapsed ? '🎯' : '🎯 داشبورد'}
            </div>

            {/* منوی داینامیک */}
            <Menu
                theme="dark"
                mode="inline"
                selectedKeys={selectedKeys}
                defaultOpenKeys={openKeys}
                items={menuItems}
                onClick={handleMenuClick}
                style={{
                    background: '#001529',
                    borderInlineEnd: 'none',
                    marginTop: 8,
                }}
            />
        </>
    );
}

export default function Sidebar({ menus, collapsed, currentUrl }: SidebarProps) {
    return (
        <Sider
            collapsible
            collapsed={collapsed}
            trigger={null}
            width={260}
            className="app-desktop-sider"
            style={{
                background: '#001529',
                overflow: 'auto',
                height: '100vh',
                position: 'sticky',
                top: 0,
                right: 0,
            }}
        >
            <SidebarMenu menus={menus} currentUrl={currentUrl} collapsed={collapsed} />
        </Sider>
    );
}