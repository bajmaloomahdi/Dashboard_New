import { useEffect, useRef, useState } from 'react';
import { Card, Button, Input, Row, Col, Select, Tag, Popconfirm, Space, Tooltip, Typography } from 'antd';
import {
    PlusOutlined,
    SearchOutlined,
    ReloadOutlined,
    CheckCircleOutlined,
    StopOutlined,
    TeamOutlined,
    EditOutlined,
    EyeOutlined,
    LoadingOutlined,
    IdcardOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../../Layouts/MainLayout';
import PageHeader from '../../../Components/PageHeader';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import { crmApi } from '../../../Components/Crm/crmApi';
import PersonFormModal, { Person } from './PersonFormModal';
import { THEME, STYLES } from '../../../theme';
import { toBool } from '../../../Utils/bool';
import { gregorianToJalaliDisplay } from '../../../Utils/jalali';

const { Title, Text } = Typography;

interface PersonRow extends Person {
    DisplayName: string;
    TitleName: string | null;
}

export default function CrmPersonsIndex() {
    const { persons, titles, filters, canManage } = usePage().props as unknown as {
        persons: PersonRow[];
        titles: { TitleID: number; DisplayName: string }[];
        filters: { search: string | null; isActive: boolean | null };
        canManage: boolean;
    };

    const [items, setItems] = useState<PersonRow[]>(persons || []);
    const [searchText, setSearchText] = useState(filters?.search || '');
    const [statusFilter, setStatusFilter] = useState<string | null>(
        filters?.isActive === null || filters?.isActive === undefined ? null : filters.isActive ? '1' : '0'
    );
    const [searching, setSearching] = useState(false);

    const [modalOpen, setModalOpen] = useState(false);
    const [editingPerson, setEditingPerson] = useState<PersonRow | null>(null);
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
            '/crm/persons?' +
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
        setEditingPerson(null);
        setModalOpen(true);
    };

    const handleEdit = (p: PersonRow, e: React.MouseEvent) => {
        e.stopPropagation();
        setEditingPerson(p);
        setModalOpen(true);
    };

    const handleModalSuccess = (message: string) => {
        setModalOpen(false);
        setEditingPerson(null);
        notify('success', message);
        reload();
    };

    const handleToggle = async (p: PersonRow) => {
        setTogglingId(p.PersonID);
        const res = await crmApi(`/crm/persons/${p.PersonID}/toggle`, 'POST');
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
                title="مخاطبین"
                subtitle="مدیریتِ اشخاصِ حقیقیِ CRM — مخاطبینِ مرتبط با طرف‌حساب‌ها"
                stats={[
                    { icon: <TeamOutlined />, label: 'کل', value: `${totalCount} مخاطب` },
                    { icon: <CheckCircleOutlined />, label: 'فعال', value: `${activeCount} مخاطب` },
                ]}
                actions={
                    canManage ? (
                        <Button type="primary" icon={<PlusOutlined />} size="large" style={STYLES.primaryButton} onClick={handleCreate}>
                            مخاطبِ جدید
                        </Button>
                    ) : undefined
                }
            />

            <Card style={{ marginBottom: 16, ...STYLES.filterCard }}>
                <Row gutter={[16, 16]} align="middle">
                    <Col xs={24} sm={12} md={14}>
                        <Input
                            placeholder="جستجو در نام، کدِ ملی..."
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
                    <div style={{ textAlign: 'center', padding: '48px 0', color: THEME.textLight }}>هیچ مخاطبی یافت نشد</div>
                ) : (
                    <div className="person-cards-grid">
                        {items.map((p) => {
                            const active = toBool(p.IsActive);
                            return (
                                <div className="person-card" key={p.PersonID} onClick={() => router.visit(`/crm/persons/${p.PersonID}`)}>
                                    <div className="person-card-header">
                                        <Tag color="purple" style={{ borderRadius: 6, margin: 0 }} icon={<TeamOutlined />}>
                                            مخاطب
                                        </Tag>
                                        <Tag icon={active ? <CheckCircleOutlined /> : <StopOutlined />} color={active ? 'success' : 'default'} style={{ borderRadius: 6, margin: 0 }}>
                                            {active ? 'فعال' : 'غیرفعال'}
                                        </Tag>
                                    </div>

                                    <Title level={5} style={{ margin: '10px 0 4px', color: THEME.textPrimary }}>
                                        {[p.TitleName, p.FirstName, p.LastName].filter(Boolean).join(' ') || '—'}
                                    </Title>

                                    {p.IdentifierNumber ? (
                                        <Text type="secondary" style={{ fontSize: 12, display: 'block', marginBottom: 10 }} dir="ltr">
                                            {p.IdentifierNumber}
                                        </Text>
                                    ) : null}

                                    {p.IdentifierDate ? (
                                        <Space size={6} style={{ marginBottom: 12 }}>
                                            <IdcardOutlined style={{ color: THEME.textLight, fontSize: 12 }} />
                                            <Text style={{ fontSize: 12 }}>{gregorianToJalaliDisplay(p.IdentifierDate)}</Text>
                                        </Space>
                                    ) : null}

                                    <div className="person-card-actions" onClick={(e) => e.stopPropagation()}>
                                        <Tooltip title="مشاهده">
                                            <Button type="text" icon={<EyeOutlined />} style={{ color: THEME.primary }} onClick={() => router.visit(`/crm/persons/${p.PersonID}`)} />
                                        </Tooltip>
                                        {canManage && (
                                            <>
                                                <Tooltip title="ویرایش">
                                                    <Button type="text" icon={<EditOutlined />} style={{ color: THEME.info }} onClick={(e) => handleEdit(p, e)} />
                                                </Tooltip>
                                                <Popconfirm
                                                    title={active ? 'غیرفعال‌کردنِ مخاطب' : 'فعال‌کردنِ مخاطب'}
                                                    description="آیا مطمئن هستید؟"
                                                    onConfirm={() => handleToggle(p)}
                                                    okText="بله"
                                                    cancelText="خیر"
                                                >
                                                    <Button
                                                        type="text"
                                                        icon={active ? <StopOutlined /> : <CheckCircleOutlined />}
                                                        loading={togglingId === p.PersonID}
                                                        style={{ color: active ? THEME.error : THEME.success }}
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
                .person-cards-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                    gap: 18px;
                }
                .person-card {
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
                .person-card:hover {
                    transform: translateY(-8px) scale(1.02);
                    box-shadow: 0 16px 32px rgba(102, 126, 234, 0.22);
                    border-color: ${THEME.borderPrimary};
                }
                .person-card-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }
                .person-card-actions {
                    display: flex;
                    justify-content: flex-end;
                    gap: 2px;
                    margin-top: 10px;
                    padding-top: 8px;
                    border-top: 1px solid ${THEME.borderLight};
                }
            `}</style>

            <PersonFormModal
                open={modalOpen}
                onClose={() => {
                    setModalOpen(false);
                    setEditingPerson(null);
                }}
                editingPerson={editingPerson}
                titles={titles || []}
                onSuccess={handleModalSuccess}
            />

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </MainLayout>
    );
}
