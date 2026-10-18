import { Layout, Button, Dropdown, Avatar, Space, Typography, Badge, Divider, Tooltip } from 'antd';
import {
    MenuFoldOutlined,
    MenuUnfoldOutlined,
    MenuOutlined,
    UserOutlined,
    LogoutOutlined,
    BellOutlined,
    KeyOutlined,
    MailOutlined,
    IdcardOutlined,
    CalendarOutlined,
    ClockCircleOutlined,
    SearchOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import type { User } from '../Types';
import MenuSearchPalette from './GlobalSearch/MenuSearchPalette';
import PushSettingsModal from './Push/PushSettingsModal';
import raga360Logo from '../../images/raga360-logo.webp';

const { Header: AntHeader } = Layout;
const { Text } = Typography;

interface HeaderProps {
    user: User | null;
    collapsed: boolean;
    /** حالت موبایل/تبلت — همبرگر به‌جای collapse، منوی کشویی را باز می‌کند */
    isMobile?: boolean;
    onToggleCollapse: () => void;
}

/**
 * گرفتن تاریخ شمسی فرمت شده
 */
const getPersianDate = (date: Date): string => {
    const weekday = new Intl.DateTimeFormat('fa-IR', {
        weekday: 'long',
    }).format(date);

    const day = new Intl.DateTimeFormat('fa-IR-u-nu-latn', {
        day: 'numeric',
        calendar: 'persian',
    }).format(date);

    const month = new Intl.DateTimeFormat('fa-IR', {
        month: 'long',
        calendar: 'persian',
    }).format(date);

    const year = new Intl.DateTimeFormat('fa-IR-u-nu-latn', {
        year: 'numeric',
        calendar: 'persian',
    }).format(date);

    return `${weekday} ${day} ${month} ${year}`;
};

/**
 * گرفتن ساعت فرمت شده
 */
const getPersianTime = (date: Date): string => {
    const formatter = new Intl.DateTimeFormat('fa-IR-u-nu-latn', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    });
    return formatter.format(date);
};

/** پلتفرمِ مک (و iPad با کیبورد) — میان‌برِ جستجو آنجا ⌘K است، نه Ctrl+K */
const isMacPlatform = (): boolean =>
    typeof navigator !== 'undefined' && /Mac|iPhone|iPad/i.test(navigator.platform || navigator.userAgent);

/**
 * آیا کلیدِ زده‌شده «K» است؟
 * - چیدمان‌هایِ لاتین (QWERTY، AZERTY، Dvorak، ...): حرفِ منطقی (event.key) ملاک است.
 * - چیدمان‌هایِ غیرلاتین (مثلاً فارسی که event.key آن «ن» است): جایِ فیزیکیِ کلیدِ K (event.code).
 */
const isSearchKey = (e: KeyboardEvent): boolean => {
    if (e.key.length === 1 && /^[a-z]$/i.test(e.key)) return e.key.toLowerCase() === 'k';
    return e.code === 'KeyK';
};

export default function Header({ user, collapsed, isMobile = false, onToggleCollapse }: HeaderProps) {
    const [currentTime, setCurrentTime] = useState(new Date());

    // دریافت تعداد پیام‌ها و شماره نسخه از props سراسری اینرشیا
    const { unreadNotificationsCount, appVersion } = usePage().props as any;
    const unreadCount = unreadNotificationsCount || 0;

    // جستجوی سریع منوها (مرحلهٔ ۱) — فقط روی prop فعلیِ menus، بدون درخواستِ شبکه یا دیتابیس
    const [searchOpen, setSearchOpen] = useState(false);
    const [searchFocusToken, setSearchFocusToken] = useState(0);
    const isMac = isMacPlatform();
    const openSearch = () => {
        setSearchOpen(true);
        setSearchFocusToken((t) => t + 1);
    };

    // Ctrl+K (ویندوز/لینوکس) یا ⌘K (مک). در مک فقط ⌘K، چون Ctrl+K آنجا در کادرهای متن
    // «حذف تا انتهای خط» است. Alt/AltGr و Shift (مثلاً Ctrl+Shift+K = کنسولِ Firefox) نادیده گرفته می‌شوند.
    useEffect(() => {
        const onKeyDown = (e: KeyboardEvent) => {
            if (e.repeat || e.isComposing || e.altKey || e.shiftKey) return;
            const modifierOk = isMac ? e.metaKey && !e.ctrlKey : e.ctrlKey && !e.metaKey;
            if (!modifierOk || !isSearchKey(e)) return;
            e.preventDefault();
            setSearchOpen(true);
            setSearchFocusToken((t) => t + 1);
        };
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [isMac]);

    useEffect(() => {
        const timer = setInterval(() => {
            setCurrentTime(new Date());
        }, 1000);
        return () => clearInterval(timer);
    }, []);

    const persianDate = getPersianDate(currentTime);
    const persianTime = getPersianTime(currentTime);

    const handleLogout = () => {
        // پرچمِ همگام‌سازیِ اشتراکِ اعلان پاک شود تا کاربرِ بعدیِ همین مرورگر اشتراک را به نامِ خودش ثبت کند
        try {
            sessionStorage.removeItem('raga360.push.synced');
        } catch {
            /* بی‌اهمیت */
        }
        router.post('/logout');
    };

    const handleChangePassword = () => {
        router.visit('/change-password');
    };

    // اعلانِ گوشی/مرورگر (Web Push)
    const [pushOpen, setPushOpen] = useState(false);

    const handleBellClick = () => {
        router.visit('/messages');
    };

    const getAvatarLetter = () => {
        if (user?.FirstName) return user.FirstName.charAt(0);
        if (user?.UserName) return user.UserName.charAt(0).toUpperCase();
        return null;
    };

    const dropdownContent = (
        <div
            style={{
                width: 300,
                background: '#fff',
                borderRadius: 12,
                boxShadow: '0 8px 24px rgba(0,0,0,0.12)',
                overflow: 'hidden',
            }}
        >
            <div
                style={{
                    background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                    padding: '20px 16px',
                    textAlign: 'center',
                    color: '#fff',
                }}
            >
                <Avatar
                    size={64}
                    style={{
                        background: 'rgba(255,255,255,0.2)',
                        border: '3px solid rgba(255,255,255,0.4)',
                        fontSize: 24,
                        fontWeight: 'bold',
                    }}
                >
                    {getAvatarLetter() || <UserOutlined />}
                </Avatar>
                <div style={{ marginTop: 12 }}>
                    <Text strong style={{ color: '#fff', fontSize: 15, display: 'block' }}>
                        {user?.FullName || 'کاربر'}
                    </Text>
                    <Text style={{ color: 'rgba(255,255,255,0.85)', fontSize: 12 }}>
                        @{user?.UserName}
                    </Text>
                </div>
            </div>

            <div style={{ padding: '12px 16px', background: '#fafafa' }}>
                <Space direction="vertical" size={6} style={{ width: '100%' }}>
                    <Space size={8}>
                        <IdcardOutlined style={{ color: '#8c8c8c', fontSize: 12 }} />
                        <Text style={{ fontSize: 12, color: '#595959' }}>
                            کد کاربری: <Text strong>{user?.UserCode || '-'}</Text>
                        </Text>
                    </Space>
                    {user?.Email && (
                        <Space size={8}>
                            <MailOutlined style={{ color: '#8c8c8c', fontSize: 12 }} />
                            <Text style={{ fontSize: 12, color: '#595959' }}>
                                {user.Email}
                            </Text>
                        </Space>
                    )}
                </Space>
            </div>

            <Divider style={{ margin: 0 }} />

            <div style={{ padding: 8 }}>
                <div className="header-menu-item" onClick={handleChangePassword}>
                    <KeyOutlined style={{ color: '#1890ff', fontSize: 16 }} />
                    <Text>تغییر کلمه عبور</Text>
                </div>

                <div className="header-menu-item" onClick={() => setPushOpen(true)}>
                    <BellOutlined style={{ color: '#667eea', fontSize: 16 }} />
                    <Text>اعلان‌هایِ گوشی و مرورگر</Text>
                </div>

                <Divider style={{ margin: '8px 0' }} />

                <div className="header-menu-item danger" onClick={handleLogout}>
                    <LogoutOutlined style={{ color: '#ff4d4f', fontSize: 16 }} />
                    <Text style={{ color: '#ff4d4f' }}>خروج از حساب</Text>
                </div>
            </div>

            <style>{`
                .header-menu-item {
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    padding: 10px 12px;
                    border-radius: 8px;
                    cursor: pointer;
                    transition: all 0.2s;
                }
                .header-menu-item:hover {
                    background: #f0f5ff;
                }
                .header-menu-item.danger:hover {
                    background: #fff1f0;
                }
            `}</style>
        </div>
    );

    return (
        <AntHeader
            className="app-header"
            style={{
                background: '#fff',
                padding: '0 24px',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                boxShadow: '0 2px 8px rgba(0,0,0,0.06)',
                position: 'sticky',
                top: 0,
                zIndex: 10,
                height: 64,
            }}
        >
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, minWidth: 0, overflow: 'hidden' }}>
                <Button
                    type="text"
                    aria-label="منو"
                    icon={
                        isMobile
                            ? <MenuOutlined />
                            : (collapsed ? <MenuUnfoldOutlined /> : <MenuFoldOutlined />)
                    }
                    onClick={onToggleCollapse}
                    style={{
                        fontSize: 18,
                        width: 48,
                        height: 48,
                        flexShrink: 0,
                    }}
                />
                {/* جستجوی ۳۶۰ درجه (منوها + جست‌وجویِ سراسری) — دسکتاپ و تبلت (در موبایل با CSS پنهان می‌شود) */}
                <button
                    type="button"
                    className="app-header-search"
                    onClick={openSearch}
                    aria-label="جستجوی ۳۶۰ درجه"
                    aria-haspopup="dialog"
                    aria-expanded={searchOpen}
                    aria-keyshortcuts={isMac ? 'Meta+K' : 'Control+K'}
                >
                    <SearchOutlined aria-hidden />
                    <span className="app-header-search-text">جستجوی ۳۶۰ درجه…</span>
                    <kbd className="app-header-search-kbd" dir="ltr" aria-hidden>{isMac ? '⌘K' : 'Ctrl K'}</kbd>
                </button>
                {/* برند فشرده — فقط در موبایل (در دسکتاپ لوگو داخل Sider است) */}
                <span className="app-header-brand"><img src={raga360Logo} alt="Raga 360°" /></span>
            </div>

            <Space className="app-header-actions" size="middle" align="center">
                {/* برچسب نسخه نرم‌افزار به صورت فارسی */}
                <div
                    className="app-header-version"
                    style={{
                        display: 'inline-flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        padding: '4px 12px',
                        borderRadius: 8,
                        background: '#EDE9FE',
                        color: '#6D28D9',
                        border: '1px solid #DDD6FE',
                        fontSize: 12,
                        fontWeight: 700,
                        direction: 'rtl',
                        height: 40,
                    }}
                >
                    نسخه : {appVersion || '1.0.0'}
                </div>

                {/* تاریخ و ساعت شمسی */}
                <div
                    style={{
                        display: 'flex',
                        flexDirection: 'column',
                        alignItems: 'flex-start',
                        lineHeight: 1.3,
                        padding: '4px 12px',
                        borderRadius: 8,
                        background: 'linear-gradient(135deg, #f6f9fc 0%, #e9f0f7 100%)',
                        border: '1px solid #e6f0fb',
                        direction: 'rtl',
                    }}
                    className="datetime-display"
                >
                    <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                        <CalendarOutlined style={{ color: '#667eea', fontSize: 12 }} />
                        <span
                            style={{
                                fontSize: 12,
                                color: '#4a5568',
                                fontWeight: 500,
                                direction: 'rtl',
                                unicodeBidi: 'plaintext',
                            }}
                        >
                            {persianDate}
                        </span>
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                        <ClockCircleOutlined style={{ color: '#764ba2', fontSize: 12 }} />
                        <span
                            style={{
                                fontSize: 13,
                                color: '#2d3748',
                                fontWeight: 'bold',
                                fontFamily: 'monospace',
                                letterSpacing: 1,
                                direction: 'ltr',
                                unicodeBidi: 'plaintext',
                            }}
                        >
                            {persianTime}
                        </span>
                    </div>
                </div>

                {/* جستجوی ۳۶۰ درجه — فقط موبایل (در دسکتاپ/تبلت با CSS پنهان می‌شود) */}
                <Button
                    type="text"
                    className="app-header-search-icon"
                    aria-label="جستجوی ۳۶۰ درجه"
                    aria-haspopup="dialog"
                    aria-expanded={searchOpen}
                    icon={<SearchOutlined style={{ fontSize: 22 }} aria-hidden />}
                    onClick={openSearch}
                    style={{ height: 48, width: 48 }}
                />

                {/* آیکون پیام‌ها */}
                <Tooltip title={unreadCount > 0 ? `${unreadCount} پیام خوانده‌نشده` : 'پیام‌ها'}>
                    <Badge count={unreadCount} showZero={false} overflowCount={99} offset={[2, 8]}>
                        <Button
                            type="text"
                            icon={<MailOutlined style={{ fontSize: 22 }} />}
                            onClick={handleBellClick}
                            style={{
                                height: 48,
                                width: 48,
                                position: 'relative',
                            }}
                        />
                    </Badge>
                </Tooltip>

                {/* منوی کاربر */}
                <Dropdown
                    dropdownRender={() => dropdownContent}
                    placement="bottomLeft"
                    trigger={['click']}
                    arrow={false}
                >
                    <div
                        className="user-profile-trigger"
                        style={{
                            cursor: 'pointer',
                            padding: '6px 12px 6px 6px',
                            borderRadius: 30,
                            display: 'flex',
                            alignItems: 'center',
                            gap: 10,
                            transition: 'all 0.3s',
                            border: '1px solid #f0f0f0',
                        }}
                    >
                        <Avatar
                            size={36}
                            style={{
                                background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                                verticalAlign: 'middle',
                                fontWeight: 'bold',
                            }}
                        >
                            {getAvatarLetter() || <UserOutlined />}
                        </Avatar>
                        <div
                            className="app-header-usertext"
                            style={{
                                display: 'flex',
                                flexDirection: 'column',
                                lineHeight: 1.3,
                                minWidth: 0,
                            }}
                        >
                            <Text strong style={{ fontSize: 13, whiteSpace: 'nowrap' }}>
                                {user?.FullName || 'کاربر'}
                            </Text>
                            <Text
                                type="secondary"
                                style={{
                                    fontSize: 11,
                                    whiteSpace: 'nowrap',
                                }}
                            >
                                خوش آمدید 👋
                            </Text>
                        </div>
                    </div>
                </Dropdown>
            </Space>

            <PushSettingsModal open={pushOpen} onClose={() => setPushOpen(false)} />

            <MenuSearchPalette
                open={searchOpen}
                onClose={() => setSearchOpen(false)}
                focusToken={searchFocusToken}
                isMobile={isMobile}
            />

            <style>{`
                .user-profile-trigger:hover {
                    background: #f5f5f5;
                    border-color: #d9d9d9;
                    transform: translateY(-1px);
                    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
                }
                .datetime-display {
                    transition: all 0.3s;
                }
                .datetime-display:hover {
                    transform: translateY(-1px);
                    box-shadow: 0 2px 8px rgba(102, 126, 234, 0.15);
                }

                /* برند فشرده‌ی هدر — فقط در موبایل/تبلت دیده می‌شود */
                .app-header-brand {
                    display: inline-flex;
                    align-items: center;
                    height: 40px;
                    width: 120px;
                    max-width: 42vw;
                    overflow: hidden;
                    border-radius: 8px;
                    flex-shrink: 0;
                }
                /* حاشیهٔ پس‌زمینهٔ بالا/پایینِ تصویر بریده می‌شود تا نوشتهٔ لوگو بزرگ‌تر دیده شود */
                .app-header-brand img { width: 100%; height: 100%; object-fit: cover; object-position: 50% 46%; display: block; }
                @media (min-width: 992px) {
                    .app-header-brand { display: none !important; }
                }

                /* موبایل / تبلت */
                @media (max-width: 991px) {
                    .app-header { padding: 0 12px !important; }
                }

                /* موبایل کوچک — حذف اطلاعات غیرضروری، فشرده‌سازی */
                @media (max-width: 767px) {
                    .datetime-display { display: none !important; }
                    .app-header-version { display: none !important; }
                    .app-header-usertext { display: none !important; }
                    .app-header-actions { gap: 6px !important; }
                    .user-profile-trigger {
                        padding: 5px !important;
                        border-color: transparent !important;
                    }
                }

                /* جستجوی سریع منوها */
                .app-header-search {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    height: 40px;
                    width: 280px;
                    padding: 0 12px;
                    border: 1px solid #e5e7eb;
                    border-radius: 8px;
                    background: #f9fafb;
                    color: #6b7280;
                    font: inherit;
                    font-size: 13px;
                    cursor: pointer;
                    flex-shrink: 1;
                    min-width: 0;
                    transition: border-color 0.2s, background 0.2s;
                }
                .app-header-search:hover { border-color: #c7d2fe; background: #fff; }
                .app-header-search:focus-visible { outline: 2px solid #667eea; outline-offset: -2px; }
                .app-header-search-text { flex: 1; text-align: start; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
                .app-header-search-kbd {
                    font-family: monospace;
                    font-size: 11px;
                    padding: 1px 6px;
                    border: 1px solid #e5e7eb;
                    border-radius: 4px;
                    background: #fff;
                    color: #6b7280;
                }
                .app-header-search-icon { display: none !important; }
                @media (max-width: 991px) {
                    .app-header-search { width: 180px; }
                }
                @media (max-width: 767px) {
                    .app-header-search { display: none !important; }
                    .app-header-search-icon { display: inline-flex !important; }
                }
            `}</style>
        </AntHeader>
    );
}