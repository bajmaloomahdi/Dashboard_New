import { ReactNode } from 'react';
import { Button, Typography, Space } from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';
import { router } from '@inertiajs/react';
import { THEME } from '../theme';

const { Title, Text } = Typography;

interface HeroStat {
    icon: ReactNode;
    label: string;
    value: ReactNode;
}

interface HeroTag {
    label: string;
    color?: string; // رنگ سفارشی (مثلاً رنگ اولویت)؛ اگر ندهید حالت پیش‌فرض شیشه‌ای سفید استفاده می‌شود
}

interface PageHeaderProps {
    icon: ReactNode;
    title: string;
    /** متن کوچک زیر عنوان (مثلاً کد پروژه) یا زیرعنوان توصیفی */
    subtitle?: ReactNode;
    tags?: HeroTag[];
    stats?: HeroStat[];
    /** محتوای سمت راست هدر (مثلاً حلقه‌ی پیشرفت) */
    aside?: ReactNode;
    /** دکمه‌ی بازگشت؛ اگر ندهید نمایش داده نمی‌شود */
    backHref?: string;
    backLabel?: string;
    /** دکمه(های) عملیات سمت چپ هدر (مثلاً «پروژه جدید») */
    actions?: ReactNode;
}

/**
 * هدر گرادیانی مشترک برای همه‌ی صفحات — برای یکدست بودن ظاهر کل اپلیکیشن.
 * الگو از Projects/Show.tsx گرفته شده.
 */
export default function PageHeader({
    icon,
    title,
    subtitle,
    tags,
    stats,
    aside,
    backHref,
    backLabel = 'بازگشت',
    actions,
}: PageHeaderProps) {
    return (
        <div className="page-hero">
            <div className="page-hero-topbar">
                {backHref ? (
                    <Button
                        icon={<ArrowLeftOutlined />}
                        onClick={() => router.visit(backHref)}
                        className="page-hero-back"
                    >
                        {backLabel}
                    </Button>
                ) : (
                    <span />
                )}
                {actions ? <div className="page-hero-actions">{actions}</div> : null}
            </div>

            <div className="page-hero-body">
                <div className="page-hero-main">
                    {tags && tags.length > 0 ? (
                        <Space size={10} wrap style={{ marginBottom: 6 }}>
                            {tags.map((t, i) => (
                                <span
                                    key={i}
                                    className="page-hero-tag"
                                    style={
                                        t.color
                                            ? { border: `1px solid ${t.color}`, background: `${t.color}33` }
                                            : undefined
                                    }
                                >
                                    {t.label}
                                </span>
                            ))}
                        </Space>
                    ) : null}

                    <Title level={2} style={{ margin: '4px 0', color: '#fff' }}>
                        <span style={{ marginLeft: 10 }}>{icon}</span>
                        {title}
                    </Title>

                    {subtitle ? (
                        <Text style={{ color: 'rgba(255,255,255,0.85)' }}>{subtitle}</Text>
                    ) : null}

                    {stats && stats.length > 0 ? (
                        <div className="page-hero-stats">
                            {stats.map((s, i) => (
                                <div className="page-hero-stat" key={i}>
                                    {s.icon}
                                    <div>
                                        <div className="page-hero-stat-label">{s.label}</div>
                                        <div className="page-hero-stat-value">{s.value}</div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : null}
                </div>

                {aside ? <div className="page-hero-aside">{aside}</div> : null}
            </div>

            <style>{`
                .page-hero {
                    position: relative;
                    background: ${THEME.primaryGradient};
                    border-radius: 18px;
                    padding: 16px 26px;
                    margin-bottom: 18px;
                    overflow: hidden;
                    box-shadow: 0 12px 28px rgba(102, 126, 234, 0.28);
                }
                .page-hero::after {
                    content: '';
                    position: absolute;
                    inset: 0;
                    background: radial-gradient(circle at 15% 120%, rgba(255,255,255,0.16), transparent 55%);
                    pointer-events: none;
                }
                .page-hero-topbar {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 10px;
                    position: relative;
                }
                .page-hero-back {
                    background: rgba(255,255,255,0.14);
                    border: 1px solid rgba(255,255,255,0.3);
                    color: #fff;
                }
                .page-hero-back:hover {
                    background: rgba(255,255,255,0.24) !important;
                    color: #fff !important;
                    border-color: rgba(255,255,255,0.5) !important;
                }
                .page-hero-actions .ant-btn {
                    box-shadow: none !important;
                    background: rgba(255,255,255,0.95) !important;
                    color: ${THEME.primary} !important;
                    border: none !important;
                    font-weight: 700 !important;
                }
                .page-hero-actions .ant-btn:hover {
                    background: #fff !important;
                }
                .page-hero-body {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    gap: 24px;
                    flex-wrap: wrap;
                    position: relative;
                }
                .page-hero-main { flex: 1; min-width: 260px; }
                .page-hero-tag {
                    display: inline-block;
                    border-radius: 20px;
                    border: none;
                    color: #fff;
                    background: rgba(255,255,255,0.22);
                    padding: 2px 12px;
                    font-size: 12px;
                }
                .page-hero-stats {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 22px;
                    margin-top: 14px;
                }
                .page-hero-stat {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    color: #fff;
                    font-size: 18px;
                }
                .page-hero-stat-label {
                    font-size: 11px;
                    color: rgba(255,255,255,0.75);
                }
                .page-hero-stat-value {
                    font-size: 13px;
                    font-weight: 600;
                }
                .page-hero-aside {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                }
            `}</style>
        </div>
    );
}