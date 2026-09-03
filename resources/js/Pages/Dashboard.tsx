import { useMemo, useState } from 'react';
import { Card, Row, Col, Typography, Space, Avatar, Button, Tooltip } from 'antd';
import * as AntIcons from '@ant-design/icons';
import {
    AppstoreOutlined,
    BarChartOutlined,
    FileTextOutlined,
    FolderOutlined,
    LayoutOutlined,
    LinkOutlined,
    ArrowLeftOutlined,
    InboxOutlined,
    ThunderboltOutlined,
    CheckCircleOutlined,
    ExpandOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../Layouts/MainLayout';
import CompanyLogo from '../Components/CompanyLogo';
import ChipTabs from '../Components/ChipTabs';
import { THEME, STYLES } from '../theme';

const { Title, Text } = Typography;

/* ------------------------------------------------------------------ */
/* تایپ‌ها                                                             */
/* ------------------------------------------------------------------ */

interface HomeTab {
    MenuID: number;
    MenuCode: string;
    MenuTitle: string;
    MenuKind: string;
    Url: string | null;
    Icon: string | null;
    SortOrder: number;
    Description: string | null;
}

interface HomeTabItem {
    TabMenuID: number;
    ItemID: number;
    ItemType: 'MENU' | 'REPORT';
    ItemCode: string;
    ItemTitle: string;
    ItemKind: string;
    Url: string | null;
    Icon: string | null;
    SortOrder: number;
    Description: string | null;
    ReportID: number | null;
    /** عرض پنجره از 24 (اختیاری — اگر ستون PanelWidth اضافه شود) */
    PanelWidth?: number | null;
}

interface PriorityStat {
    msgPriorityID: number | null;
    Code: number | null;
    PriorityName: string;
    PrioritySortOrder: number;
    MessageCount: number;
}

/* ------------------------------------------------------------------ */
/* استایل                                                              */
/* ------------------------------------------------------------------ */

const DASH_CSS = `
/* پنجره (Panel) */
.dash-panel {
    border-radius: 16px;
    border: 1px solid #EFECFB;
    background: #fff;
    box-shadow: 0 2px 10px rgba(17,24,39,.05);
    height: 100%;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    transition: box-shadow .25s ease, transform .25s ease;
}
.dash-panel:hover {
    box-shadow: 0 10px 26px rgba(102,126,234,.14);
}
.dash-panel-head {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    background: linear-gradient(135deg, #F7F5FE 0%, #FFFFFF 100%);
    border-bottom: 1px solid #EFECFB;
}
.dash-panel-body {
    padding: 16px;
    flex: 1;
}

/* کارت میان‌بر داخل پنجره */
.shortcut-row {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px; border-radius: 10px;
    border: 1px solid #F3F4F6; background: #FCFCFF;
    cursor: pointer; transition: all .2s ease; margin-bottom: 8px;
}
.shortcut-row:last-child { margin-bottom: 0; }
.shortcut-row:hover { background: #F5F3FF; border-color: #DDD6FE; transform: translateX(-3px); }

/* کارت اولویت پیام */
.priority-card {
    border-radius: 14px; padding: 16px; cursor: pointer;
    transition: all .25s ease; position: relative; overflow: hidden; height: 100%;
}
.priority-card:hover { transform: translateY(-4px); box-shadow: 0 12px 24px rgba(0,0,0,.14); }
.priority-card .pc-glow {
    position: absolute; width: 110px; height: 110px; border-radius: 50%;
    background: rgba(255,255,255,.16); top: -46px; left: -26px;
}
`;

/* ------------------------------------------------------------------ */
/* کمکی‌ها                                                             */
/* ------------------------------------------------------------------ */

const renderIcon = (name: string | null, style: React.CSSProperties = {}) => {
    if (!name) return <AppstoreOutlined style={style} />;
    const key = name.endsWith('Outlined') ? name : `${name}Outlined`;
    const IconComponent = (AntIcons as any)[key];
    return IconComponent ? <IconComponent style={style} /> : <AppstoreOutlined style={style} />;
};

const itemTypeMeta = (item: HomeTabItem) => {
    if (item.ItemType === 'REPORT') {
        return { color: '#7C3AED', gradient: 'linear-gradient(135deg, #8B5CF6 0%, #6D28D9 100%)', label: 'گزارش', icon: <BarChartOutlined /> };
    }
    switch (item.ItemKind) {
        case 'FOLDER':
            return { color: '#D97706', gradient: 'linear-gradient(135deg, #FBBF24 0%, #D97706 100%)', label: 'پوشه', icon: <FolderOutlined /> };
        case 'LINK':
            return { color: '#0891B2', gradient: 'linear-gradient(135deg, #22D3EE 0%, #0891B2 100%)', label: 'لینک', icon: <LinkOutlined /> };
        default:
            return { color: '#2563EB', gradient: 'linear-gradient(135deg, #60A5FA 0%, #2563EB 100%)', label: 'صفحه', icon: <FileTextOutlined /> };
    }
};

const priorityTheme = (sortOrder: number, maxSort: number) => {
    if (sortOrder === 0) {
        return { gradient: 'linear-gradient(135deg, #9CA3AF 0%, #6B7280 100%)', shadow: 'rgba(107,114,128,.35)' };
    }
    const ratio = maxSort <= 1 ? 1 : (sortOrder - 1) / (maxSort - 1);
    if (ratio >= 0.75) return { gradient: 'linear-gradient(135deg, #F87171 0%, #DC2626 100%)', shadow: 'rgba(220,38,38,.35)' };
    if (ratio >= 0.5)  return { gradient: 'linear-gradient(135deg, #FB923C 0%, #EA580C 100%)', shadow: 'rgba(234,88,12,.35)' };
    if (ratio >= 0.25) return { gradient: 'linear-gradient(135deg, #FBBF24 0%, #D97706 100%)', shadow: 'rgba(217,119,6,.35)' };
    return { gradient: 'linear-gradient(135deg, #34D399 0%, #059669 100%)', shadow: 'rgba(5,150,105,.35)' };
};

/* ------------------------------------------------------------------ */
/* پوسته‌ی پنجره                                                       */
/* ------------------------------------------------------------------ */

interface PanelProps {
    title: string;
    icon: React.ReactNode;
    accent: string;
    extraText?: string;
    onOpen?: () => void;
    children: React.ReactNode;
}

function Panel({ title, icon, accent, extraText, onOpen, children }: PanelProps) {
    return (
        <div className="dash-panel">
            <div className="dash-panel-head">
                <div
                    style={{
                        width: 34, height: 34, borderRadius: 10,
                        background: accent, color: '#fff',
                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                        fontSize: 16, flexShrink: 0,
                    }}
                >
                    {icon}
                </div>
                <div style={{ flex: 1, minWidth: 0 }}>
                    <Text strong style={{ fontSize: 14 }}>{title}</Text>
                    {extraText && (
                        <div>
                            <Text type="secondary" style={{ fontSize: 11 }}>{extraText}</Text>
                        </div>
                    )}
                </div>
                {onOpen && (
                    <Tooltip title="باز کردن">
                        <Button
                            type="text"
                            size="small"
                            icon={<ExpandOutlined />}
                            style={{ color: THEME.primary }}
                            onClick={onOpen}
                        />
                    </Tooltip>
                )}
            </div>
            <div className="dash-panel-body">{children}</div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/* محتوای پنجره: پیام‌های من                                           */
/* ------------------------------------------------------------------ */

function MyMessagesContent({ stats }: { stats: PriorityStat[] }) {
    const total = (stats || []).reduce((sum, s) => sum + (s.MessageCount || 0), 0);
    const maxSort = Math.max(1, ...(stats || []).map((s) => s.PrioritySortOrder || 0));
    const list = (stats || []).filter((s) => s.msgPriorityID !== null || s.MessageCount > 0);

    if (total === 0) {
        return (
            <div
                style={{
                    background: 'linear-gradient(180deg, #F0FDF4 0%, #FFFFFF 100%)',
                    border: '1px dashed #86EFAC',
                    borderRadius: 12, padding: '32px 20px', textAlign: 'center',
                }}
            >
                <CheckCircleOutlined style={{ fontSize: 38, color: '#10B981', marginBottom: 10 }} />
                <Text strong style={{ display: 'block', fontSize: 14 }}>
                    پیام رسیدگی‌نشده‌ای ندارید
                </Text>
                <Text type="secondary" style={{ fontSize: 12 }}>همه‌چیز مرتب است 🎉</Text>
            </div>
        );
    }

    return (
        <Row gutter={[12, 12]}>
            {list.map((stat) => {
                const th = priorityTheme(stat.PrioritySortOrder, maxSort);
                const isEmpty = stat.MessageCount === 0;

                return (
                    <Col xs={12} md={8} xl={6} key={stat.msgPriorityID ?? 'none'}>
                        <div
                            className="priority-card"
                            style={{
                                background: isEmpty ? '#F9FAFB' : th.gradient,
                                boxShadow: isEmpty ? 'none' : `0 8px 18px ${th.shadow}`,
                                border: isEmpty ? '1px solid #E5E7EB' : '1px solid transparent',
                                opacity: isEmpty ? 0.7 : 1,
                            }}
                            onClick={() =>
                                router.visit(
                                    stat.msgPriorityID
                                        ? `/messages?msg_priority_id=${stat.msgPriorityID}`
                                        : '/messages'
                                )
                            }
                        >
                            {!isEmpty && <div className="pc-glow" />}
                            <div style={{ position: 'relative' }}>
                                <Space size={5}>
                                    <ThunderboltOutlined
                                        style={{ fontSize: 13, color: isEmpty ? '#9CA3AF' : 'rgba(255,255,255,.9)' }}
                                    />
                                    <Text
                                        style={{
                                            fontSize: 12, fontWeight: 600,
                                            color: isEmpty ? '#6B7280' : 'rgba(255,255,255,.95)',
                                        }}
                                    >
                                        {stat.PriorityName}
                                    </Text>
                                </Space>
                                <div style={{ marginTop: 8, display: 'flex', alignItems: 'baseline', gap: 5 }}>
                                    <span
                                        style={{
                                            fontSize: 26, fontWeight: 800, lineHeight: 1,
                                            color: isEmpty ? '#9CA3AF' : '#fff', fontFamily: 'monospace',
                                        }}
                                    >
                                        {stat.MessageCount}
                                    </span>
                                    <span style={{ fontSize: 11, color: isEmpty ? '#9CA3AF' : 'rgba(255,255,255,.85)' }}>
                                        پیام
                                    </span>
                                </div>
                            </div>
                        </div>
                    </Col>
                );
            })}
        </Row>
    );
}

/* ------------------------------------------------------------------ */
/* محتوای پنجره: میان‌برها (زیرمنوهای یک پوشه یا گزارش‌ها)              */
/* ------------------------------------------------------------------ */

function ShortcutContent({ items }: { items: HomeTabItem[] }) {
    return (
        <div>
            {items.map((item) => {
                const meta = itemTypeMeta(item);
                return (
                    <div
                        key={`${item.ItemType}-${item.ItemID}`}
                        className="shortcut-row"
                        onClick={() => item.Url && router.visit(item.Url)}
                    >
                        <div
                            style={{
                                width: 34, height: 34, borderRadius: 9,
                                background: meta.gradient, color: '#fff',
                                display: 'flex', alignItems: 'center', justifyContent: 'center',
                                fontSize: 15, flexShrink: 0,
                            }}
                        >
                            {item.Icon ? renderIcon(item.Icon) : meta.icon}
                        </div>
                        <div style={{ flex: 1, minWidth: 0 }}>
                            <Text strong style={{ fontSize: 13, display: 'block' }}>{item.ItemTitle}</Text>
                            <Text type="secondary" style={{ fontSize: 11, fontFamily: 'monospace', direction: 'ltr' }}>
                                {item.ItemCode}
                            </Text>
                        </div>
                        <ArrowLeftOutlined style={{ color: meta.color, fontSize: 12 }} />
                    </div>
                );
            })}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/* صفحه داشبورد                                                        */
/* ------------------------------------------------------------------ */

export default function Dashboard() {
    const { auth, company, homeTabs, homeTabItems, messagePriorityStats } = usePage().props as any;

    const tabs: HomeTab[] = homeTabs || [];
    const items: HomeTabItem[] = homeTabItems || [];
    const stats: PriorityStat[] = messagePriorityStats || [];

    const [activeKey, setActiveKey] = useState<string>(
        tabs.length ? String(tabs[0].MenuID) : ''
    );

    const itemsByTab = useMemo(() => {
        const map: Record<number, HomeTabItem[]> = {};
        items.forEach((item) => {
            if (!map[item.TabMenuID]) map[item.TabMenuID] = [];
            map[item.TabMenuID].push(item);
        });
        return map;
    }, [items]);

    /**
     * آیا این تب پنجره «پیام‌های من» را دارد؟
     * فقط زمانی که تب صراحتاً با Url یا MenuCode مربوط به my-messages / mymessages
     * مشخص شده باشد. (قبلاً به‌صورت fallback روی تب اول — index === 0 — هم اضافه می‌شد
     * که باعث می‌شد پنجره به اشتباه در تب‌های دیگری مثل «کارهای روزانه من» ظاهر شود.)
     */
    const hasMessagesPanel = (tab: HomeTab) => {
        const key = `${tab.Url || ''} ${tab.MenuCode || ''}`.toLowerCase();
        return key.includes('my-messages') || key.includes('mymessages');
    };

    /**
     * ساخت پنجره‌های یک تب
     * - پنجره پیام‌های من (فقط در تبی که با my-messages / mymessages مشخص شده باشد)
     * - هر «پوشه» ⇒ یک پنجره که میان‌برهای داخلش را نشان می‌دهد
     * - گزارش‌ها و صفحه‌ها ⇒ داخل یک پنجره «میان‌برها»
     */
    const buildPanels = (tab: HomeTab) => {
        const tabItems = itemsByTab[tab.MenuID] || [];
        const panels: { key: string; span: number; node: React.ReactNode }[] = [];

        if (hasMessagesPanel(tab)) {
            panels.push({
                key: 'w-messages',
                span: 24,
                node: (
                    <Panel
                        title="پیام‌های من"
                        icon={<InboxOutlined />}
                        accent={THEME.primaryGradient}
                        extraText="بر اساس اولویت"
                        onOpen={() => router.visit('/messages')}
                    >
                        <MyMessagesContent stats={stats} />
                    </Panel>
                ),
            });
        }

        const reports = tabItems.filter((i) => i.ItemType === 'REPORT');
        const folders = tabItems.filter((i) => i.ItemType === 'MENU' && i.ItemKind === 'FOLDER');
        const pages = tabItems.filter((i) => i.ItemType === 'MENU' && i.ItemKind !== 'FOLDER');

        // هر پوشه یک پنجره مستقل
        folders.forEach((folder) => {
            const children = items.filter((i) => i.TabMenuID === folder.ItemID);
            panels.push({
                key: `folder-${folder.ItemID}`,
                span: folder.PanelWidth || 12,
                node: (
                    <Panel
                        title={folder.ItemTitle}
                        icon={folder.Icon ? renderIcon(folder.Icon) : <FolderOutlined />}
                        accent="linear-gradient(135deg, #FBBF24 0%, #D97706 100%)"
                        extraText={`${children.length} مورد`}
                        onOpen={folder.Url ? () => router.visit(folder.Url!) : undefined}
                    >
                        {children.length ? (
                            <ShortcutContent items={children} />
                        ) : (
                            <Text type="secondary" style={{ fontSize: 12 }}>موردی ثبت نشده است</Text>
                        )}
                    </Panel>
                ),
            });
        });

        // پنجره گزارش‌ها
        if (reports.length) {
            panels.push({
                key: 'reports',
                span: 12,
                node: (
                    <Panel
                        title="گزارش‌ها"
                        icon={<BarChartOutlined />}
                        accent="linear-gradient(135deg, #8B5CF6 0%, #6D28D9 100%)"
                        extraText={`${reports.length} گزارش`}
                    >
                        <ShortcutContent items={reports} />
                    </Panel>
                ),
            });
        }

        // پنجره میان‌برها (صفحه‌ها)
        if (pages.length) {
            panels.push({
                key: 'pages',
                span: 12,
                node: (
                    <Panel
                        title="میان‌برها"
                        icon={<AppstoreOutlined />}
                        accent="linear-gradient(135deg, #60A5FA 0%, #2563EB 100%)"
                        extraText={`${pages.length} مورد`}
                    >
                        <ShortcutContent items={pages} />
                    </Panel>
                ),
            });
        }

        return panels;
    };

    // داده‌ی هر تب: پنجره‌ها یک‌بار ساخته می‌شود و هم برای شمارنده‌ی چیپ و هم برای بدنه استفاده می‌شود
    const tabsData = tabs.map((tab) => ({
        tab,
        panels: buildPanels(tab),
    }));

    // آیتم‌های نوار تب به‌شکل چیپ — همان الگوی ChipTabs در «نامه‌ها» و «پروژه‌ها»
    const tabDefs = tabsData.map(({ tab, panels }) => ({
        key: String(tab.MenuID),
        label: tab.MenuTitle,
        icon: renderIcon(tab.Icon),
        count: panels.length || null,
    }));

    const activeTabData =
        tabsData.find((d) => String(d.tab.MenuID) === activeKey) ?? tabsData[0];

    const renderTabBody = (tab: HomeTab, panels: ReturnType<typeof buildPanels>) => (
        <div>
            {tab.Description && (
                <div
                    style={{
                        background: '#F7F5FE', border: '1px solid #EFECFB',
                        borderRadius: 10, padding: '10px 14px', marginBottom: 16,
                    }}
                >
                    <Text type="secondary" style={{ fontSize: 13 }}>{tab.Description}</Text>
                </div>
            )}

            {panels.length === 0 ? (
                <div
                    style={{
                        background: 'linear-gradient(180deg, #FAFAFF 0%, #FFFFFF 100%)',
                        border: '1px dashed #DDD6FE',
                        borderRadius: 14, padding: '48px 24px', textAlign: 'center',
                    }}
                >
                    <div
                        style={{
                            width: 64, height: 64, borderRadius: 18, margin: '0 auto 16px',
                            background: THEME.primaryGradientLight,
                            display: 'flex', alignItems: 'center', justifyContent: 'center',
                            fontSize: 28, color: THEME.primary,
                        }}
                    >
                        <LayoutOutlined />
                    </div>
                    <Text strong style={{ display: 'block', fontSize: 15 }}>
                        این تب هنوز پنجره‌ای ندارد
                    </Text>
                    <Text type="secondary" style={{ fontSize: 12 }}>
                        در «مدیریت گزارشات» یا «مدیریت منوها»، منوی والد را «{tab.MenuTitle}» انتخاب کنید
                    </Text>
                </div>
            ) : (
                <Row gutter={[16, 16]}>
                    {panels.map((p) => (
                        <Col xs={24} lg={p.span} key={p.key}>
                            {p.node}
                        </Col>
                    ))}
                </Row>
            )}
        </div>
    );

    return (
        <MainLayout>
            <style>{DASH_CSS}</style>

            {/* هدر خوشامدگویی */}
            <Card
                style={{ marginBottom: 24, ...STYLES.card, background: THEME.primaryGradient, border: 'none' }}
                styles={{ body: { padding: 24 } }}
            >
                <Row align="middle" justify="space-between">
                    <Col>
                        <Space size="middle">
                            <Avatar
                                size={64}
                                style={{
                                    background: 'rgba(255,255,255,0.2)',
                                    border: '3px solid rgba(255,255,255,0.4)',
                                    fontSize: 28, fontWeight: 'bold',
                                }}
                            >
                                {auth?.user?.FirstName?.charAt(0) || 'م'}
                            </Avatar>
                            <div>
                                <Title level={3} style={{ color: '#fff', margin: 0 }}>
                                    سلام {auth?.user?.FullName || 'کاربر'} 👋
                                </Title>
                                <Text style={{ color: 'rgba(255,255,255,0.9)', fontSize: 14 }}>
                                    خلاصه فعالیت‌های امروز شما
                                </Text>
                            </div>
                        </Space>
                    </Col>
                    <Col>
                        <CompanyLogo
                            hasLogo={company?.LogoMimeType}
                            variant="welcome"
                            fallback="📊"
                        />
                    </Col>
                </Row>
            </Card>

            {/* تب‌های صفحه اصلی */}
            <Card style={STYLES.card} styles={{ body: { padding: '16px 20px 24px' } }}>
                {tabs.length === 0 ? (
                    <div style={{ padding: '60px 24px', textAlign: 'center' }}>
                        <div
                            style={{
                                width: 80, height: 80, borderRadius: 22, margin: '0 auto 20px',
                                background: THEME.primaryGradientLight,
                                display: 'flex', alignItems: 'center', justifyContent: 'center',
                                fontSize: 34, color: THEME.primary,
                            }}
                        >
                            <LayoutOutlined />
                        </div>
                        <Title level={5} style={{ marginBottom: 6 }}>
                            هنوز تبی برای شما تعریف نشده است
                        </Title>
                        <Text type="secondary" style={{ fontSize: 13 }}>
                            در «مدیریت منوها» یک منو از نوع «تب صفحه اصلی (TAB)» بسازید
                            و در «نقش‌ها» به آن دسترسی بدهید
                        </Text>
                    </div>
                ) : (
                    <>
                        <div
                            style={{
                                display: 'flex', alignItems: 'center', gap: 6, marginBottom: 12,
                            }}
                        >
                            <LayoutOutlined style={{ color: THEME.primary }} />
                            <Text type="secondary" style={{ fontSize: 12 }}>
                                {tabs.length} تب
                            </Text>
                        </div>

                        <ChipTabs
                            items={tabDefs}
                            activeKey={activeKey}
                            onChange={setActiveKey}
                        />

                        {activeTabData && renderTabBody(activeTabData.tab, activeTabData.panels)}
                    </>
                )}
            </Card>
        </MainLayout>
    );
}
