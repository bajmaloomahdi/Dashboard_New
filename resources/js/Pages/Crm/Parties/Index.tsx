import { useEffect, useRef, useState } from 'react';
import { Card, Button, Input, Space, Tag, Tooltip, Typography, Row, Col, Select, Popconfirm } from 'antd';
import {
    PlusOutlined,
    EditOutlined,
    SearchOutlined,
    ReloadOutlined,
    CheckCircleOutlined,
    StopOutlined,
    TeamOutlined,
    EyeOutlined,
    LoadingOutlined,
    UserOutlined,
    BankOutlined,
    TagOutlined,
    EnvironmentOutlined,
    PhoneOutlined,
    ApartmentOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../../Layouts/MainLayout';
import PageHeader from '../../../Components/PageHeader';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import { crmApi } from '../../../Components/Crm/crmApi';
import PartyFormModal, { Party } from './PartyFormModal';
import { THEME, STYLES } from '../../../theme';
import { toBool } from '../../../Utils/bool';

const { Title, Text } = Typography;

interface PartyRow extends Party {
    DisplayName: string;
    DepartmentName: string | null;
    PartyTypeName: string | null;
    ActivityName: string | null;
    ActiveBrandCount: number;
    ActiveAddressCount: number;
    ActiveContactCount: number;
    ClassificationCount: number;
}

export default function CrmPartiesIndex() {
    const { parties, filters, canManage } = usePage().props as unknown as {
        parties: PartyRow[];
        filters: { search: string | null; isActive: boolean | null; partyNature: string | null };
        canManage: boolean;
    };

    const [items, setItems] = useState<PartyRow[]>(parties || []);
    const [searchText, setSearchText] = useState(filters?.search || '');
    const [statusFilter, setStatusFilter] = useState<string | null>(
        filters?.isActive === null || filters?.isActive === undefined ? null : filters.isActive ? '1' : '0'
    );
    const [natureFilter, setNatureFilter] = useState<string | null>(filters?.partyNature ?? null);
    const [searching, setSearching] = useState(false);

    const [modalOpen, setModalOpen] = useState(false);
    const [editingParty, setEditingParty] = useState<PartyRow | null>(null);
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
            '/crm/parties-list?' +
                new URLSearchParams({
                    ...(searchText ? { search: searchText } : {}),
                    ...(statusFilter !== null ? { isActive: statusFilter } : {}),
                    ...(natureFilter ? { partyNature: natureFilter } : {}),
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
    }, [searchText, statusFilter, natureFilter]);

    const handleReset = () => {
        setSearchText('');
        setStatusFilter(null);
        setNatureFilter(null);
    };

    const handleCreate = () => {
        setEditingParty(null);
        setModalOpen(true);
    };

    const handleEdit = (p: PartyRow, e: React.MouseEvent) => {
        e.stopPropagation();
        setEditingParty(p);
        setModalOpen(true);
    };

    const handleModalSuccess = (message: string) => {
        setModalOpen(false);
        setEditingParty(null);
        notify('success', message);
        reload();
    };

    const handleToggle = async (p: PartyRow) => {
        setTogglingId(p.PartyID);
        const res = await crmApi(`/crm/parties/${p.PartyID}/toggle`, 'POST');
        setTogglingId(null);
        if (!res.ok || !res.success) {
            notify('error', res.message);
            return;
        }
        notify('success', res.message);
        reload();
    };

    const totalCount = items?.length || 0;
    const activeCount = items?.filter((p) => toBool(p.IsActive)).length || 0;

    return (
        <MainLayout>
            <PageHeader
                icon={<TeamOutlined />}
                title="طرف‌حساب‌ها"
                subtitle="مدیریتِ فروشگاه‌ها، شرکت‌ها و کارخانه‌هایِ CRM"
                stats={[
                    { icon: <TeamOutlined />, label: 'کل', value: `${totalCount} طرف‌حساب` },
                    { icon: <CheckCircleOutlined />, label: 'فعال', value: `${activeCount} طرف‌حساب` },
                ]}
                actions={
                    canManage ? (
                        <Button type="primary" icon={<PlusOutlined />} size="large" style={STYLES.primaryButton} onClick={handleCreate}>
                            طرف‌حسابِ جدید
                        </Button>
                    ) : undefined
                }
            />

            <Card style={{ marginBottom: 16, ...STYLES.filterCard }}>
                <Row gutter={[16, 16]} align="middle">
                    <Col xs={24} sm={12} md={9}>
                        <Input
                            placeholder="جستجو در نام، شناسه..."
                            prefix={searching ? <LoadingOutlined style={{ color: THEME.primary }} /> : <SearchOutlined />}
                            value={searchText}
                            onChange={(e) => setSearchText(e.target.value)}
                            allowClear
                            size="large"
                        />
                    </Col>
                    <Col xs={24} sm={12} md={5}>
                        <Select
                            placeholder="ماهیت"
                            style={{ width: '100%' }}
                            size="large"
                            value={natureFilter ?? undefined}
                            onChange={(v) => setNatureFilter(v ?? null)}
                            allowClear
                            options={[
                                { value: 'INDIVIDUAL', label: 'حقیقی' },
                                { value: 'LEGAL', label: 'حقوقی' },
                            ]}
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
                    <div style={{ textAlign: 'center', padding: '48px 0', color: THEME.textLight }}>هیچ طرف‌حسابی یافت نشد</div>
                ) : (
                    <div className="party-cards-grid">
                        {items.map((p) => {
                            const isActive = toBool(p.IsActive);
                            const isLegal = p.PartyNature === 'LEGAL';
                            return (
                                <div className="party-card" key={p.PartyID} onClick={() => router.visit(`/crm/parties/${p.PartyID}`)}>
                                    <div className="party-card-header">
                                        <Tag color={isLegal ? 'geekblue' : 'purple'} style={{ borderRadius: 6, margin: 0 }} icon={isLegal ? <BankOutlined /> : <UserOutlined />}>
                                            {isLegal ? 'حقوقی' : 'حقیقی'}
                                        </Tag>
                                        <Tag icon={isActive ? <CheckCircleOutlined /> : <StopOutlined />} color={isActive ? 'success' : 'default'} style={{ borderRadius: 6, margin: 0 }}>
                                            {isActive ? 'فعال' : 'غیرفعال'}
                                        </Tag>
                                    </div>

                                    <Title level={5} style={{ margin: '10px 0 4px', color: THEME.textPrimary }}>
                                        {p.DisplayName || '—'}
                                    </Title>

                                    {p.IdentifierNumber ? (
                                        <Text type="secondary" style={{ fontSize: 12, display: 'block', marginBottom: 10 }} dir="ltr">
                                            {p.IdentifierNumber}
                                        </Text>
                                    ) : null}

                                    <Space size={6} wrap style={{ marginBottom: 12 }}>
                                        {Number(p.ClassificationCount) > 0 ? (
                                            <Tag color="gold" style={{ borderRadius: 6, margin: 0 }}>
                                                <ApartmentOutlined /> {p.ClassificationCount} دسته‌بندی
                                            </Tag>
                                        ) : null}
                                    </Space>

                                    <div className="party-card-meta">
                                        <Tooltip title="برند">
                                            <Space size={6}>
                                                <TagOutlined style={{ color: THEME.textLight }} />
                                                <Text style={{ fontSize: 12 }}>{p.ActiveBrandCount}</Text>
                                            </Space>
                                        </Tooltip>
                                        <Tooltip title="آدرس">
                                            <Space size={6}>
                                                <EnvironmentOutlined style={{ color: THEME.textLight }} />
                                                <Text style={{ fontSize: 12 }}>{p.ActiveAddressCount}</Text>
                                            </Space>
                                        </Tooltip>
                                        <Tooltip title="تماس">
                                            <Space size={6}>
                                                <PhoneOutlined style={{ color: THEME.textLight }} />
                                                <Text style={{ fontSize: 12 }}>{p.ActiveContactCount}</Text>
                                            </Space>
                                        </Tooltip>
                                    </div>

                                    <div className="party-card-actions" onClick={(e) => e.stopPropagation()}>
                                        <Tooltip title="مشاهده">
                                            <Button type="text" icon={<EyeOutlined />} style={{ color: THEME.primary }} onClick={() => router.visit(`/crm/parties/${p.PartyID}`)} />
                                        </Tooltip>
                                        {canManage && (
                                            <>
                                                <Tooltip title="ویرایش">
                                                    <Button type="text" icon={<EditOutlined />} style={{ color: THEME.info }} onClick={(e) => handleEdit(p, e)} />
                                                </Tooltip>
                                                <Popconfirm
                                                    title={isActive ? 'غیرفعال‌کردنِ طرف‌حساب' : 'فعال‌کردنِ طرف‌حساب'}
                                                    description="آیا مطمئن هستید؟"
                                                    onConfirm={() => handleToggle(p)}
                                                    okText="بله"
                                                    cancelText="خیر"
                                                >
                                                    <Button
                                                        type="text"
                                                        icon={isActive ? <StopOutlined /> : <CheckCircleOutlined />}
                                                        loading={togglingId === p.PartyID}
                                                        style={{ color: isActive ? THEME.error : THEME.success }}
                                                    />
                                                </Popconfirm>
                                            </>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </Card>

            <style>{`
                .party-cards-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                    gap: 18px;
                }
                .party-card {
                    background: #fff;
                    border: 1px solid ${THEME.border};
                    border-inline-end-width: 5px;
                    border-inline-end-style: solid;
                    border-inline-end-color: transparent;
                    border-radius: 14px;
                    padding: 16px;
                    cursor: pointer;
                    transition: transform 0.22s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.22s ease, border-color 0.22s ease;
                }
                .party-card:hover {
                    transform: translateY(-8px) scale(1.02);
                    box-shadow: 0 16px 32px rgba(102, 126, 234, 0.22);
                    border-color: ${THEME.borderPrimary};
                }
                .party-card-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }
                .party-card-meta {
                    display: flex;
                    justify-content: space-between;
                    padding-top: 8px;
                    border-top: 1px dashed ${THEME.borderLight};
                    margin-top: 6px;
                }
                .party-card-actions {
                    display: flex;
                    justify-content: flex-end;
                    gap: 2px;
                    margin-top: 10px;
                    padding-top: 8px;
                    border-top: 1px solid ${THEME.borderLight};
                }
            `}</style>

            <PartyFormModal
                open={modalOpen}
                onClose={() => {
                    setModalOpen(false);
                    setEditingParty(null);
                }}
                editingParty={editingParty}
                onSuccess={handleModalSuccess}
            />

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </MainLayout>
    );
}
