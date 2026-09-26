import { useEffect, useRef, useState } from 'react';
import { Card, Button, Input, Row, Col, Select, Popconfirm, Tooltip, Typography } from 'antd';
import {
    PlusOutlined,
    SearchOutlined,
    ReloadOutlined,
    CheckCircleOutlined,
    StopOutlined,
    TagsOutlined,
    EditOutlined,
    LoadingOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../../Layouts/MainLayout';
import PageHeader from '../../../Components/PageHeader';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import { crmApi } from '../../../Components/Crm/crmApi';
import BrandFormModal, { Brand } from './BrandFormModal';
import { THEME, STYLES } from '../../../theme';
import { toBool } from '../../../Utils/bool';

const { Text } = Typography;

interface BrandRow extends Brand {
    ActivePartyCount: number | string;
}

/** لوگویِ برند داخلِ ناحیهٔ ثابتِ کارت (object-fit: contain از CSS)؛ نبودن یا خطایِ بارگذاری = Placeholder */
function BrandLogo({ url, name }: { url?: string | null; name: string }) {
    const [failed, setFailed] = useState(false);
    if (url && !failed) {
        return <img src={url} alt={name} loading="lazy" onError={() => setFailed(true)} />;
    }
    return <TagsOutlined className="brand-card-logo-placeholder" aria-hidden />;
}

export default function CrmBrandsIndex() {
    const { brands, filters, canManage } = usePage().props as unknown as {
        brands: BrandRow[];
        filters: { search: string | null; isActive: boolean | null };
        canManage: boolean;
    };

    const [items, setItems] = useState<BrandRow[]>(brands || []);
    const [searchText, setSearchText] = useState(filters?.search || '');
    const [statusFilter, setStatusFilter] = useState<string | null>(
        filters?.isActive === null || filters?.isActive === undefined ? null : filters.isActive ? '1' : '0'
    );
    const [searching, setSearching] = useState(false);

    const [modalOpen, setModalOpen] = useState(false);
    const [editingBrand, setEditingBrand] = useState<BrandRow | null>(null);
    const [togglingId, setTogglingId] = useState<number | null>(null);

    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({
        open: false,
        type: 'success',
        message: '',
    });
    const notify = (type: NotificationType, message: string) => setNotification({ open: true, type, message });

    const searchTimeoutRef = useRef<NodeJS.Timeout | null>(null);
    const isFirstRender = useRef(true);

    const reload = async () => {
        const res = await crmApi(
            '/crm/brands?' +
                new URLSearchParams({
                    ...(searchText ? { search: searchText } : {}),
                    ...(statusFilter !== null ? { isActive: statusFilter } : {}),
                }).toString()
        );
        if (res.ok && res.success) setItems(res.items || []);
    };

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            return;
        }
        setSearching(true);
        if (searchTimeoutRef.current) clearTimeout(searchTimeoutRef.current);
        searchTimeoutRef.current = setTimeout(async () => {
            await reload();
            setSearching(false);
        }, 300);
        return () => {
            if (searchTimeoutRef.current) clearTimeout(searchTimeoutRef.current);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [searchText, statusFilter]);

    const handleReset = () => {
        setSearchText('');
        setStatusFilter(null);
    };

    const handleCreate = () => {
        setEditingBrand(null);
        setModalOpen(true);
    };

    const handleEdit = (b: BrandRow, e: React.MouseEvent) => {
        e.stopPropagation();
        setEditingBrand(b);
        setModalOpen(true);
    };

    const handleModalSuccess = (message: string) => {
        setModalOpen(false);
        setEditingBrand(null);
        notify('success', message);
        reload();
    };

    const handleToggle = async (b: BrandRow) => {
        setTogglingId(b.BrandID);
        const res = await crmApi(`/crm/brands/${b.BrandID}/toggle`, 'POST');
        setTogglingId(null);
        if (!res.ok || !res.success) {
            notify('error', res.message);
            return;
        }
        notify('success', res.message);
        reload();
    };

    const totalCount = items?.length || 0;
    const activeCount = items?.filter((b) => toBool(b.IsActive)).length || 0;

    return (
        <MainLayout>
            <PageHeader
                icon={<TagsOutlined />}
                title="برندها"
                subtitle="مدیریتِ برندهایِ CRM — هر برند می‌تواند به چند طرف‌حساب مرتبط باشد"
                stats={[
                    { icon: <TagsOutlined />, label: 'کل', value: `${totalCount} برند` },
                    { icon: <CheckCircleOutlined />, label: 'فعال', value: `${activeCount} برند` },
                ]}
                actions={
                    canManage ? (
                        <Button type="primary" icon={<PlusOutlined />} size="large" style={STYLES.primaryButton} onClick={handleCreate}>
                            برندِ جدید
                        </Button>
                    ) : undefined
                }
            />

            <Card style={{ marginBottom: 16, ...STYLES.filterCard }}>
                <Row gutter={[16, 16]} align="middle">
                    <Col xs={24} sm={12} md={14}>
                        <Input
                            placeholder="جستجو در نام، توضیحات..."
                            prefix={searching ? <LoadingOutlined style={{ color: THEME.primary }} /> : <SearchOutlined />}
                            value={searchText}
                            onChange={(e) => setSearchText(e.target.value)}
                            allowClear
                            size="large"
                        />
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Select
                            placeholder="وضعیت"
                            style={{ width: '100%' }}
                            size="large"
                            value={statusFilter ?? undefined}
                            onChange={(v) => setStatusFilter(v ?? null)}
                            allowClear
                            options={[
                                { value: '1', label: 'فعال' },
                                { value: '0', label: 'غیرفعال' },
                            ]}
                        />
                    </Col>
                    <Col xs={24} sm={12} md={4}>
                        <Button icon={<ReloadOutlined />} onClick={handleReset} size="large" block>
                            بازنشانی
                        </Button>
                    </Col>
                </Row>
            </Card>

            <Card style={STYLES.card} bodyStyle={{ padding: 20 }}>
                {searching ? (
                    <div style={{ textAlign: 'center', padding: '48px 0', color: THEME.textLight }}>
                        <LoadingOutlined style={{ fontSize: 20, color: THEME.primary }} />
                    </div>
                ) : (items || []).length === 0 ? (
                    <div style={{ textAlign: 'center', padding: '48px 0', color: THEME.textLight }}>هیچ برندی یافت نشد</div>
                ) : (
                    <div className="brand-cards-grid">
                        {items.map((b) => {
                            const active = toBool(b.IsActive);
                            const open = () => router.visit(`/crm/brands/${b.BrandID}`);
                            return (
                                <div
                                    className={`brand-card${active ? '' : ' is-inactive'}`}
                                    key={b.BrandID}
                                    role="button"
                                    tabIndex={0}
                                    aria-label={b.Name}
                                    onClick={open}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter' && e.target === e.currentTarget) open();
                                    }}
                                >
                                    <div className="brand-card-logo">
                                        <BrandLogo key={b.LogoUrl || 'none'} url={b.LogoUrl} name={b.Name} />
                                        <span className={`brand-card-status${active ? ' is-active' : ''}`}>{active ? 'فعال' : 'غیرفعال'}</span>
                                    </div>

                                    <Text strong className="brand-card-name" ellipsis={{ tooltip: b.Name }}>
                                        {b.Name || '—'}
                                    </Text>

                                    {canManage && (
                                        // کلیک رویِ Actionها صفحهٔ جزئیات را باز نمی‌کند (Popconfirm هم از طریقِ همین wrapper متوقف می‌شود)
                                        <div className="brand-card-actions" onClick={(e) => e.stopPropagation()} onKeyDown={(e) => e.stopPropagation()}>
                                            <Tooltip title="ویرایش">
                                                <Button type="text" size="small" icon={<EditOutlined />} style={{ color: THEME.info }} onClick={(e) => handleEdit(b, e)} />
                                            </Tooltip>
                                            <Popconfirm
                                                title={active ? 'غیرفعال‌کردنِ برند' : 'فعال‌کردنِ برند'}
                                                description="آیا مطمئن هستید؟"
                                                onConfirm={() => handleToggle(b)}
                                                okText="بله"
                                                cancelText="خیر"
                                            >
                                                <Tooltip title={active ? 'غیرفعال‌کردن' : 'فعال‌کردن'}>
                                                    <Button
                                                        type="text"
                                                        size="small"
                                                        icon={active ? <StopOutlined /> : <CheckCircleOutlined />}
                                                        loading={togglingId === b.BrandID}
                                                        style={{ color: active ? THEME.error : THEME.success }}
                                                    />
                                                </Tooltip>
                                            </Popconfirm>
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}
            </Card>

            <style>{`
                /* کارتِ برند: دسکتاپ 240×160؛ ستون‌ها با auto-fill/minmax (بدونِ تعدادِ ستونِ ثابت و بدونِ Overflowِ افقی) */
                .brand-cards-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(min(100%, 200px), 1fr));
                    gap: 16px;
                }
                @media (min-width: 768px) {
                    .brand-cards-grid {
                        grid-template-columns: repeat(auto-fill, minmax(min(100%, 240px), 240px));
                    }
                }
                .brand-card {
                    position: relative;
                    box-sizing: border-box;
                    min-width: 0;
                    height: 160px;
                    display: flex;
                    flex-direction: column;
                    gap: 10px;
                    padding: 16px;
                    background: #fff;
                    border: 1px solid ${THEME.border};
                    border-radius: 12px;
                    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
                    cursor: pointer;
                    outline: none;
                    transition: border-color 0.15s ease, box-shadow 0.15s ease;
                }
                .brand-card:hover,
                .brand-card:focus-visible {
                    border-color: ${THEME.borderPrimary};
                    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.06);
                }
                /* ناحیهٔ ثابتِ لوگو (~200×90)؛ خودِ تصویر با object-fit: contain، بدونِ تغییرِ نسبت */
                .brand-card-logo {
                    position: relative;
                    width: 100%;
                    max-width: 200px;
                    height: 90px;
                    flex: 0 0 auto;
                    margin: 0 auto;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    border-radius: 8px;
                    background: ${THEME.borderLight};
                    overflow: hidden;
                }
                .brand-card-logo img {
                    width: 100%;
                    height: 100%;
                    object-fit: contain;
                    display: block;
                }
                .brand-card-logo-placeholder {
                    font-size: 28px;
                    color: ${THEME.textLight};
                }
                /* وضعیت به‌صورتِ Overlay در گوشهٔ ناحیهٔ لوگو (بدونِ مصرفِ فضایِ عمودی) */
                .brand-card-status {
                    position: absolute;
                    top: 6px;
                    inset-inline-start: 6px;
                    display: inline-flex;
                    align-items: center;
                    gap: 4px;
                    padding: 1px 8px;
                    font-size: 11px;
                    line-height: 18px;
                    border-radius: 999px;
                    background: #fff;
                    border: 1px solid ${THEME.border};
                    color: ${THEME.textSecondary};
                }
                .brand-card-status::before {
                    content: '';
                    width: 6px;
                    height: 6px;
                    border-radius: 50%;
                    background: ${THEME.textLight};
                }
                .brand-card-status.is-active {
                    color: ${THEME.success};
                }
                .brand-card-status.is-active::before {
                    background: ${THEME.success};
                }
                .brand-card-name {
                    display: block;
                    width: 100%;
                    text-align: center;
                    font-size: 14px;
                    color: ${THEME.textPrimary};
                }
                /* برندِ غیرفعال: کمی کم‌رنگ‌تر، ولی نام و وضعیت خوانا */
                .brand-card.is-inactive .brand-card-logo img,
                .brand-card.is-inactive .brand-card-logo-placeholder {
                    opacity: 0.5;
                }
                .brand-card.is-inactive .brand-card-name {
                    color: ${THEME.textSecondary};
                }
                /* Actionها در گوشهٔ کارت؛ دسکتاپ با Hover/Focus، دستگاهِ لمسی همیشه دیده می‌شوند */
                .brand-card-actions {
                    position: absolute;
                    top: 10px;
                    inset-inline-end: 10px;
                    display: flex;
                    gap: 2px;
                    padding: 2px;
                    background: #fff;
                    border: 1px solid ${THEME.border};
                    border-radius: 8px;
                    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
                }
                @media (hover: hover) and (pointer: fine) {
                    .brand-card-actions {
                        opacity: 0;
                        transition: opacity 0.15s ease;
                    }
                    .brand-card:hover .brand-card-actions,
                    .brand-card:focus-within .brand-card-actions {
                        opacity: 1;
                    }
                }
                @media (max-width: 575px) {
                    .brand-card {
                        height: 150px;
                        gap: 8px;
                    }
                    .brand-card-logo {
                        height: 84px;
                    }
                }
            `}</style>

            <BrandFormModal
                open={modalOpen}
                onClose={() => {
                    setModalOpen(false);
                    setEditingBrand(null);
                }}
                editingBrand={editingBrand}
                onSuccess={handleModalSuccess}
            />

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </MainLayout>
    );
}
