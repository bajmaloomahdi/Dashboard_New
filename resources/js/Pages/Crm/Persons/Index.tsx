import { useEffect, useRef, useState } from 'react';
import { Card, Button, Input, Row, Col, Select, Tag, Popconfirm, Space, Tooltip, Typography, Table } from 'antd';
import type { ColumnsType } from 'antd/es/table';
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
import CrmDeleteButton from '../../../Components/Crm/CrmDeleteButton';
import PrimaryContactLine from '../../../Components/Crm/PrimaryContactLine';
import PrimaryAddressCell from '../../../Components/Crm/PrimaryAddressCell';
import { CooperationEndedTag } from '../../../Components/Crm/CooperationEnded';
import ListViewToggle, { useListViewMode } from '../../../Components/Crm/ListViewToggle';
import { useColumnFilters, FilterHeader, TextFilterInput, SelectFilterInput, filterHeaderCss } from '../../../Components/Crm/tableFilters';

const { Title, Text } = Typography;

interface PersonRow extends Person {
    DisplayName: string;
    TitleName: string | null;
    /** تماسِ اصلی (sp_Crm_GetPersons) */
    PrimaryContactValue?: string | null;
    PrimaryContactType?: string | null;
    PrimaryContactExtension?: string | null;
    /** آدرسِ اصلی (اولین آدرسِ فعال) و طرف‌حساب‌هایِ مرتبط — نمایِ جدولی */
    PrimaryAddressProvince?: string | null;
    PrimaryAddressCity?: string | null;
    PrimaryAddressText?: string | null;
    PrimaryAddressTitle?: string | null;
    RelatedPartyNames?: string | null;
}

export default function CrmPersonsIndex() {
    const { persons, titles, filters, canManage, canDelete = false } = usePage().props as unknown as {
        persons: PersonRow[];
        titles: { TitleID: number; DisplayName: string }[];
        filters: { search: string | null; isActive: boolean | null };
        canManage: boolean;
        canDelete?: boolean;
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
    const [viewMode, setViewMode] = useListViewMode('crm.persons.viewMode');
    const [page, setPage] = useState({ current: 1, pageSize: 20 });
    // فیلترهایِ ستونیِ شبیهِ اکسل (با تایپ، همان لحظه؛ سمتِ مرورگر رویِ همین فهرستِ بارگذاری‌شده)
    const fullName = (p: PersonRow) => [p.TitleName, p.FirstName, p.LastName].filter(Boolean).join(' ') || '—';
    const colF = useColumnFilters<PersonRow>(items, {
        name: { get: fullName, mode: 'text' },
        identifier: { get: (p) => p.IdentifierNumber, mode: 'text' },
        birth: { get: (p) => (p.IdentifierDate ? gregorianToJalaliDisplay(p.IdentifierDate) : ''), mode: 'text' },
        parties: { get: (p) => p.RelatedPartyNames, mode: 'text' },
        contact: { get: (p) => [p.PrimaryContactValue, p.PrimaryContactType, p.PrimaryContactExtension].filter(Boolean).join(' '), mode: 'text' },
        address: { get: (p) => [p.PrimaryAddressTitle, p.PrimaryAddressProvince, p.PrimaryAddressCity, p.PrimaryAddressText].filter(Boolean).join(' '), mode: 'text' },
        status: { get: (p) => (toBool(p.IsActive) ? '1' : '0'), mode: 'exact' },
    });
    const setColFilter = (key: string, value: string) => {
        colF.setValue(key, value);
        setPage((pg) => ({ ...pg, current: 1 }));
    };

    // در نمایِ جدولی جست‌وجو فقط از فیلترِ ستون‌هاست؛ فیلترهایِ بالایِ صفحه (حالتِ کارتی) پاک می‌شوند تا همهٔ ردیف‌ها بیایند
    useEffect(() => {
        if (viewMode === 'grid' && (searchText || statusFilter !== null)) {
            setSearchText('');
            setStatusFilter(null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [viewMode]);

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
        setPage((pg) => ({ ...pg, current: 1 }));
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
        colF.clear();
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

    const renderActions = (p: PersonRow) => {
        const active = toBool(p.IsActive);
        return (
            <>
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
                {canDelete && (
                    <CrmDeleteButton entity="person" id={p.PersonID} label="شخص" size="middle" onDone={(ok, msg) => { notify(ok ? 'success' : 'error', msg); if (ok) reload(); }} />
                )}
            </>
        );
    };

    const columns: ColumnsType<PersonRow> = [
        { title: 'ردیف', key: 'row', width: 64, align: 'center', render: (_, __, i) => (page.current - 1) * page.pageSize + i + 1 },
        {
            title: <FilterHeader label="نام"><TextFilterInput value={colF.values.name} onChange={(v) => setColFilter('name', v)} /></FilterHeader>,
            key: 'name',
            render: (_, p) => (
                <Space size={6} wrap>
                    <Text strong>{fullName(p)}</Text>
                    <CooperationEndedTag at={p.CooperationEndedAt} />
                </Space>
            ),
        },
        {
            title: <FilterHeader label="کدِ ملی"><TextFilterInput value={colF.values.identifier} onChange={(v) => setColFilter('identifier', v)} /></FilterHeader>,
            key: 'identifier',
            render: (_, p) => (p.IdentifierNumber ? <Text dir="ltr">{p.IdentifierNumber}</Text> : <Text type="secondary">—</Text>),
        },
        {
            title: <FilterHeader label="تاریخِ تولد"><TextFilterInput value={colF.values.birth} onChange={(v) => setColFilter('birth', v)} placeholder="مثلاً 1370" /></FilterHeader>,
            key: 'birth',
            render: (_, p) => (p.IdentifierDate ? gregorianToJalaliDisplay(p.IdentifierDate) : <Text type="secondary">—</Text>),
        },
        {
            title: <FilterHeader label="طرف‌حساب‌هایِ مرتبط"><TextFilterInput value={colF.values.parties} onChange={(v) => setColFilter('parties', v)} /></FilterHeader>,
            key: 'parties',
            width: 220,
            render: (_, p) => (p.RelatedPartyNames ? <Text ellipsis={{ tooltip: p.RelatedPartyNames }} style={{ maxWidth: 220 }}>{p.RelatedPartyNames}</Text> : <Text type="secondary">—</Text>),
        },
        {
            title: <FilterHeader label="تماسِ اصلی"><TextFilterInput value={colF.values.contact} onChange={(v) => setColFilter('contact', v)} placeholder="شماره، ایمیل، نوع…" /></FilterHeader>,
            key: 'contact',
            render: (_, p) => <PrimaryContactLine value={p.PrimaryContactValue} type={p.PrimaryContactType} extension={p.PrimaryContactExtension} />,
        },
        {
            title: <FilterHeader label="آدرسِ اصلی"><TextFilterInput value={colF.values.address} onChange={(v) => setColFilter('address', v)} placeholder="استان، شهر، آدرس…" /></FilterHeader>,
            key: 'address',
            width: 260,
            render: (_, p) => (
                <PrimaryAddressCell province={p.PrimaryAddressProvince} city={p.PrimaryAddressCity} text={p.PrimaryAddressText} title={p.PrimaryAddressTitle} />
            ),
        },
        {
            title: (
                <FilterHeader label="وضعیت">
                    <SelectFilterInput value={colF.values.status} onChange={(v) => setColFilter('status', v)} options={[{ value: '1', label: 'فعال' }, { value: '0', label: 'غیرفعال' }]} />
                </FilterHeader>
            ),
            key: 'status',
            align: 'center',
            render: (_, p) => {
                const active = toBool(p.IsActive);
                return (
                    <Tag icon={active ? <CheckCircleOutlined /> : <StopOutlined />} color={active ? 'success' : 'default'} style={{ borderRadius: 6, margin: 0 }}>
                        {active ? 'فعال' : 'غیرفعال'}
                    </Tag>
                );
            },
        },
        {
            title: 'عملیات',
            key: 'actions',
            width: 170,
            align: 'center',
            render: (_, p) => (
                <div className="person-grid-actions" onClick={(e) => e.stopPropagation()}>
                    {renderActions(p)}
                </div>
            ),
        },
    ];

    const totalCount = items?.length || 0;
    const activeCount = items?.filter((p) => toBool(p.IsActive)).length || 0;

    return (
        <MainLayout>
            <PageHeader
                icon={<TeamOutlined />}
                title="مخاطبین"
                subtitle="مدیریتِ اشخاصِ حقیقیِ «مخاطبین و ارتباطات» —مخاطبینِ مرتبط با طرف‌حساب‌ها"
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
                <Row gutter={[16, 16]} align="middle" justify={viewMode === 'grid' ? 'end' : 'start'}>
                    {viewMode === 'cards' ? (
                    <>
                    <Col xs={24} sm={12} md={10}>
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
                    </>
                    ) : null}
                    <Col xs={24} sm={12} md={4} style={{ textAlign: 'center' }}>
                        <ListViewToggle value={viewMode} onChange={setViewMode} />
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
                ) : viewMode === 'grid' ? (
                    <>
                    <Space size={8} wrap style={{ marginBottom: 8 }}>
                        <Text type="secondary">{colF.hasAny ? `${colF.filtered.length} از ${items.length} ردیف` : `${items.length} ردیف`}</Text>
                        {colF.hasAny ? (
                            <Button size="small" icon={<ReloadOutlined />} onClick={() => { colF.clear(); setPage((pg) => ({ ...pg, current: 1 })); }}>پاک‌کردنِ فیلترهایِ جدول</Button>
                        ) : null}
                    </Space>
                    <Table
                        rowKey="PersonID"
                        columns={columns}
                        dataSource={colF.filtered}
                        size="middle"
                        locale={{ emptyText: 'ردیفی با این فیلترها یافت نشد' }}
                        scroll={{ x: 'max-content' }}
                        rowClassName={(p) => (toBool(p.IsActive) ? 'person-grid-row' : 'person-grid-row is-inactive')}
                        onRow={(p) => ({ onClick: () => router.visit(`/crm/persons/${p.PersonID}`) })}
                        onChange={(pg) => setPage({ current: pg.current ?? 1, pageSize: pg.pageSize ?? 20 })}
                        pagination={{
                            current: page.current,
                            pageSize: page.pageSize,
                            showSizeChanger: true,
                            pageSizeOptions: [10, 20, 50, 100],
                            showTotal: (total) => (colF.hasAny ? `${total} از ${items.length} مخاطب` : `${total} مخاطب`),
                        }}
                    />
                    </>
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
                                        {fullName(p)}
                                    </Title>

                                    {p.IdentifierNumber ? (
                                        <Text type="secondary" style={{ fontSize: 12, display: 'block', marginBottom: 10 }} dir="ltr">
                                            {p.IdentifierNumber}
                                        </Text>
                                    ) : null}

                                    <div style={{ marginBottom: 8 }}>
                                        <PrimaryContactLine value={p.PrimaryContactValue} type={p.PrimaryContactType} extension={p.PrimaryContactExtension} />
                                    </div>

                                    {p.IdentifierDate ? (
                                        <Space size={6} style={{ marginBottom: 12 }}>
                                            <IdcardOutlined style={{ color: THEME.textLight, fontSize: 12 }} />
                                            <Text style={{ fontSize: 12 }}>{gregorianToJalaliDisplay(p.IdentifierDate)}</Text>
                                        </Space>
                                    ) : null}

                                    <div className="person-card-actions" onClick={(e) => e.stopPropagation()}>
                                        {renderActions(p)}
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
                ${filterHeaderCss}
                .person-grid-row { cursor: pointer; }
                .person-grid-row.is-inactive td { color: ${THEME.textLight}; }
                .person-grid-actions { display: inline-flex; gap: 2px; }
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
