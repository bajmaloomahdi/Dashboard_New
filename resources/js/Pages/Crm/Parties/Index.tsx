import { Confirmation } from '../../../Components/Confirmation';
import { compareGridValues } from '../../../Utils/gridSorting';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Card, Button, Input, Space, Tag, Tooltip, Typography, Row, Col, Select, Table } from 'antd';
import type { ColumnsType } from 'antd/es/table';
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
import { applyGridColumnSettings, useGridColumnSettings } from '../../../Components/GridColumnSettings';
import MainLayout from '../../../Layouts/MainLayout';
import PageHeader from '../../../Components/PageHeader';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import { crmApi } from '../../../Components/Crm/crmApi';
import PartyFormModal, { Party, PositionOption, RoleOption } from './PartyFormModal';
import { THEME, STYLES } from '../../../theme';
import { toBool } from '../../../Utils/bool';
import CrmDeleteButton from '../../../Components/Crm/CrmDeleteButton';
import PrimaryContactLine from '../../../Components/Crm/PrimaryContactLine';
import PrimaryAddressCell from '../../../Components/Crm/PrimaryAddressCell';
import ListViewToggle, { useCardPage, useListViewMode } from '../../../Components/Crm/ListViewToggle';
import { useColumnFilters, FilterHeader, TextFilterInput, MultiSelectFilterInput, distinctOptions, distinctListOptions, filterHeaderCss, normalizeForFilter } from '../../../Components/Crm/tableFilters';
import { emptyCategory, emptySelections, filterPartyCategories, type CategorySelections } from './categoryFilters';
import FirstWithTooltipCell, { parseNameList } from '../../../Components/Crm/FirstWithTooltipCell';
import { CooperationEndedTag } from '../../../Components/Crm/CooperationEnded';

const { Title, Text } = Typography;

const cooperationFilterOptions = [
    { value: 'all', label: 'همه' },
    { value: '0', label: 'فعال' },
    { value: '1', label: 'پایان‌یافته' },
];

interface PartyRow extends Party {
    DisplayName: string;
    DepartmentName: string | null;
    PartyTypeName: string | null;
    ActivityName: string | null;
    /** همهٔ گروه‌ها (نوع‌ها)/زیرگروه‌ها (فعالیت‌ها)یِ طبقه‌بندی‌هایِ فعال به ترتیبِ ثبت — آرایهٔ JSON (پچِ ۱۱۵) */
    PartyTypeNames?: string | null;
    ActivityNames?: string | null;
    ActiveBrandCount: number;
    ActiveAddressCount: number;
    ActiveContactCount: number;
    ClassificationCount: number;
    /** تماسِ اصلی (sp_Crm_GetParties) */
    PrimaryMobile?: string | null;
    PrimaryContactValue?: string | null;
    PrimaryContactType?: string | null;
    PrimaryContactExtension?: string | null;
    /** نامِ مخاطبِ تماسِ اصلی یا «مخاطبِ اصلی»ِ طرف‌حساب (sp_Crm_GetParties) */
    PrimaryContactPersonName?: string | null;
    /** آدرسِ اصلی (اولین آدرسِ فعال) — نمایِ جدولی */
    PrimaryAddressProvince?: string | null;
    PrimaryAddressCounty?: string | null;
    PrimaryAddressCity?: string | null;
    PrimaryAddressText?: string | null;
    PrimaryAddressTitle?: string | null;
}

const typeList = (p: PartyRow) => parseNameList(p.PartyTypeNames, p.PartyTypeName);
const activityList = (p: PartyRow) => parseNameList(p.ActivityNames, p.ActivityName);

function CategoryFilter({ label, value, onChange, options, testId }: {
    label: string;
    value: string[];
    onChange: (value: string[]) => void;
    options: { value: string; label: string }[];
    testId?: string;
}) {
    const available = new Set(options.map((option) => option.value));
    return (
        <Select<string[]>
            mode="multiple"
            data-testid={testId}
            aria-label={label}
            size="small"
            allowClear
            showSearch
            placeholder="همه"
            style={{ width: 160 }}
            maxTagCount={1}
            maxTagTextLength={12}
            maxTagPlaceholder={(omitted) => <Tooltip title={omitted.map((option) => option.label).join('، ')}>+{omitted.length}</Tooltip>}
            popupMatchSelectWidth={false}
            value={value}
            onChange={onChange}
            options={[...options, ...value.filter((name) => !available.has(name)).map((name) => ({
                value: name,
                label: name === emptyCategory ? '— (خالی)' : name,
            }))]}
            filterOption={(input, option) => normalizeForFilter(option?.label).includes(normalizeForFilter(input))}
        />
    );
}

export default function CrmPartiesIndex() {
    const { parties, filters, canManage, canDelete = false, positions, contactRoles, partyTypes, activities } = usePage().props as unknown as {
        parties: PartyRow[];
        filters: { search: string | null; isActive: boolean | null; partyNature: string | null; cooperationEnded?: boolean | null };
        canManage: boolean;
        canDelete?: boolean;
        positions: PositionOption[];
        contactRoles: RoleOption[];
        /** گروه و زیرگروهِ فرمِ تعریفِ جدید */
        partyTypes?: { PartyTypeID: number | string; DisplayName: string }[];
        activities?: { ActivityID: number | string; PartyTypeID: number | string; DisplayName: string }[];
    };

    const [items, setItems] = useState<PartyRow[]>(parties || []);
    const [searchText, setSearchText] = useState(filters?.search || '');
    const [statusFilter, setStatusFilter] = useState<string | null>(
        filters?.isActive === null || filters?.isActive === undefined ? null : toBool(filters.isActive) ? '1' : '0'
    );
    const [natureFilter, setNatureFilter] = useState<string | null>(filters?.partyNature ?? null);
    const [cooperationFilter, setCooperationFilter] = useState<'all' | '0' | '1'>(
        filters?.cooperationEnded === null || filters?.cooperationEnded === undefined
            ? 'all'
            : toBool(filters.cooperationEnded) ? '1' : '0'
    );
    const [searching, setSearching] = useState(false);

    const [modalOpen, setModalOpen] = useState(false);
    const [editingParty, setEditingParty] = useState<PartyRow | null>(null);
    const [togglingId, setTogglingId] = useState<number | null>(null);
    const [viewMode, setViewMode] = useListViewMode('crm.parties.viewMode');
    const [page, setPage] = useState({ current: 1, pageSize: 20 });
    // فیلترهایِ ستونیِ شبیهِ اکسل (با تایپ، همان لحظه؛ سمتِ مرورگر رویِ همین فهرستِ بارگذاری‌شده)
    const [categories, setCategories] = useState<CategorySelections>(emptySelections);
    const colF = useColumnFilters<PartyRow>(items, {
        name: { get: (p) => p.DisplayName, mode: 'text' },
        identifier: { get: (p) => p.IdentifierNumber, mode: 'text' },
        person: { get: (p) => p.PrimaryContactPersonName, mode: 'text' },
        contact: { get: (p) => [p.PrimaryContactValue, p.PrimaryContactType, p.PrimaryContactExtension].filter(Boolean).join(' '), mode: 'text' },
        province: { get: (p) => p.PrimaryAddressProvince, mode: 'text' },
        county: { get: (p) => p.PrimaryAddressCounty, mode: 'text' },
        mobile: { get: (p) => p.PrimaryMobile, mode: 'text' },
        city: { get: (p) => p.PrimaryAddressCity, mode: 'text' },
        address: { get: (p) => [p.PrimaryAddressTitle, p.PrimaryAddressProvince, p.PrimaryAddressCounty, p.PrimaryAddressCity, p.PrimaryAddressText].filter(Boolean).join(' '), mode: 'text' },
    });
    const filteredRows = useMemo(
        () => filterPartyCategories(colF.filtered, categories, {
            type: typeList,
            activity: activityList,
            nature: (p) => [p.PartyNature],
            status: (p) => [toBool(p.IsActive) ? '1' : '0'],
            cooperation: (p) => [p.CooperationEndedAt != null ? '1' : '0'],
        }),
        [colF.filtered, categories],
    );
    const hasGridFilters = colF.hasAny || Object.values(categories).some((values) => values.length > 0);
    const clearGridFilters = () => {
        colF.clear();
        setCategories(emptySelections());
        setPage((pg) => ({ ...pg, current: 1 }));
    };
    const setCategory = (key: keyof CategorySelections, value: string[]) => {
        setCategories((current) => current[key].length === value.length && current[key].every((v, i) => v === value[i])
            ? current : { ...current, [key]: value });
        setPage((pg) => pg.current === 1 ? pg : { ...pg, current: 1 });
    };
    const setColSelection = (key: string, values: string[]) => {
        colF.setSelection(key, values);
        setPage((current) => ({ ...current, current: 1 }));
    };

    const setColFilter = (key: string, value: string) => {
        colF.setValue(key, value);
        setPage((pg) => ({ ...pg, current: 1 }));
    };

    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({
        open: false,
        type: 'success',
        message: '',
    });
    const notify = (type: NotificationType, message: string) => setNotification({ open: true, type, message });

    const searchTimeoutRef = useRef<NodeJS.Timeout | null>(null);
    const isFirstRender = useRef(true);

    const cardQuery = new URLSearchParams({
        ...(searchText ? { search: searchText } : {}),
        ...(statusFilter !== null ? { isActive: statusFilter } : {}),
        ...(natureFilter ? { partyNature: natureFilter } : {}),
        ...(cooperationFilter !== 'all' ? { cooperationEnded: cooperationFilter } : {}),
    }).toString();
    const filterQuery = viewMode === 'cards' ? cardQuery : '';

    const reload = async () => {
        const res = await crmApi(
            '/crm/parties-list?' + filterQuery
        );
        if (res.ok && res.success) setItems(res.items || []);
    };

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            if (viewMode === 'cards' || !cardQuery) return;
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
    }, [filterQuery]);

    const handleReset = () => {
        clearGridFilters();
        setSearchText('');
        setStatusFilter(null);
        setNatureFilter(null);
        setCooperationFilter('all');
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

    const renderActions = (p: PartyRow) => {
        const isActive = toBool(p.IsActive);
        return (
            <>
                <Tooltip title="مشاهده">
                    <Button type="text" icon={<EyeOutlined />} style={{ color: THEME.primary }} onClick={() => router.visit(`/crm/parties/${p.PartyID}`)} />
                </Tooltip>
                {canManage && (
                    <>
                        <Tooltip title="ویرایش">
                            <Button type="text" icon={<EditOutlined />} style={{ color: THEME.info }} onClick={(e) => handleEdit(p, e)} />
                        </Tooltip>
                        <Confirmation
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
                        </Confirmation>
                    </>
                )}
                {canDelete && (
                    <CrmDeleteButton entity="party" id={p.PartyID} label="طرف‌حساب" size="middle" onDone={(ok, msg) => { notify(ok ? 'success' : 'error', msg); if (ok) reload(); }} />
                )}
            </>
        );
    };

    const natureTag = (p: PartyRow) => {
        const isLegal = p.PartyNature === 'LEGAL';
        return (
            <Tag color={isLegal ? 'geekblue' : 'purple'} style={{ borderRadius: 6, margin: 0 }} icon={isLegal ? <BankOutlined /> : <UserOutlined />}>
                {isLegal ? 'حقوقی' : 'حقیقی'}
            </Tag>
        );
    };

    const statusTag = (p: PartyRow) => {
        const isActive = toBool(p.IsActive);
        return (
            <Tag icon={isActive ? <CheckCircleOutlined /> : <StopOutlined />} color={isActive ? 'success' : 'default'} style={{ borderRadius: 6, margin: 0 }}>
                {isActive ? 'فعال' : 'غیرفعال'}
            </Tag>
        );
    };

    const cardPage = useCardPage(items || []);
    const columns: ColumnsType<PartyRow> = viewMode === 'cards' ? [] : [
        { title: 'ردیف', key: 'row', width: 64, align: 'center', render: (_, __, i) => (page.current - 1) * page.pageSize + i + 1 },
        {
            title: <FilterHeader label="نام"><TextFilterInput value={colF.values.name} onChange={(v) => setColFilter('name', v)} /></FilterHeader>,
            key: 'name',
            sorter: (a, b) => compareGridValues(a.DisplayName, b.DisplayName),
            render: (_, p) => (
                <Space size={6} wrap>
                    <Text strong>{p.DisplayName || '—'}</Text>
                    <CooperationEndedTag at={p.CooperationEndedAt} />
                </Space>
            ),
        },
        {
            title: (
                <FilterHeader label="ماهیت">
                    <CategoryFilter label="ماهیت جدول" value={categories.nature} onChange={(v) => setCategory('nature', v)} options={[{ value: 'INDIVIDUAL', label: 'حقیقی' }, { value: 'LEGAL', label: 'حقوقی' }]} />
                </FilterHeader>
            ),
            key: 'nature',
            sorter: (a, b) => compareGridValues(a.PartyNature, b.PartyNature),
            align: 'center',
            render: (_, p) => natureTag(p),
        },
        {
            title: <FilterHeader label="شناسه / کدِ ملی"><TextFilterInput value={colF.values.identifier} onChange={(v) => setColFilter('identifier', v)} /></FilterHeader>,
            key: 'identifier',
            sorter: (a, b) => compareGridValues(a.IdentifierNumber, b.IdentifierNumber),
            render: (_, p) => (p.IdentifierNumber ? <Text dir="ltr">{p.IdentifierNumber}</Text> : <Text type="secondary">—</Text>),
        },
        {
            title: (
                <FilterHeader label="گروه">
                    <CategoryFilter label="گروه" value={categories.type} onChange={(v) => setCategory('type', v)} options={distinctListOptions(items, typeList)} />
                </FilterHeader>
            ),
            key: 'type',
            sorter: (a, b) => compareGridValues(typeList(a)[0], typeList(b)[0]),
            // چند گروه → اولی نمایش داده می‌شود، بقیه در تولتیپ
            render: (_, p) => <FirstWithTooltipCell items={typeList(p)} />,
        },
        {
            title: (
                <FilterHeader label="زیرگروه">
                    <CategoryFilter label="زیرگروه" value={categories.activity} onChange={(v) => setCategory('activity', v)} options={distinctListOptions(items, activityList)} />
                </FilterHeader>
            ),
            key: 'activity',
            sorter: (a, b) => compareGridValues(activityList(a)[0], activityList(b)[0]),
            render: (_, p) => <FirstWithTooltipCell items={activityList(p)} />,
        },
        {
            title: <FilterHeader label="فرد مرتبط"><MultiSelectFilterInput label="فرد مرتبط" value={colF.selections.person}
                onChange={(values) => setColSelection('person', values)} options={distinctOptions(items, (row) => row.PrimaryContactPersonName)} /></FilterHeader>,
            key: 'person',
            sorter: (a, b) => compareGridValues(a.PrimaryContactPersonName, b.PrimaryContactPersonName),
            render: (_, p) => p.PrimaryContactPersonName || <Text type="secondary">—</Text>,
        },
        {
            title: <FilterHeader label="تماسِ اصلی"><TextFilterInput value={colF.values.contact} onChange={(v) => setColFilter('contact', v)} placeholder="شماره، ایمیل، نوع…" /></FilterHeader>,
            key: 'contact',
            sorter: (a, b) => compareGridValues(a.PrimaryContactValue, b.PrimaryContactValue),
            render: (_, p) => <PrimaryContactLine value={p.PrimaryContactValue} type={p.PrimaryContactType} extension={p.PrimaryContactExtension} />,
        },
        {
            title: <FilterHeader label="استان"><MultiSelectFilterInput label="استان" value={colF.selections.province} onChange={(values) => setColSelection('province', values)} options={distinctOptions(items, (row) => row.PrimaryAddressProvince)} /></FilterHeader>,
            key: 'province',
            dataIndex: 'PrimaryAddressProvince',
            width: 145,
            sorter: (a, b) => (a.PrimaryAddressProvince ?? '').localeCompare(b.PrimaryAddressProvince ?? '', 'fa'),
        },
        {
            title: <FilterHeader label="شهرستان"><MultiSelectFilterInput label="شهرستان" value={colF.selections.county} onChange={(values) => setColSelection('county', values)} options={distinctOptions(items, (row) => row.PrimaryAddressCounty)} /></FilterHeader>,
            key: 'county',
            dataIndex: 'PrimaryAddressCounty',
            width: 145,
            sorter: (a, b) => (a.PrimaryAddressCounty ?? '').localeCompare(b.PrimaryAddressCounty ?? '', 'fa'),
        },
        {
            title: <FilterHeader label="موبایل"><MultiSelectFilterInput label="موبایل" value={colF.selections.mobile} onChange={(values) => setColSelection('mobile', values)} options={distinctOptions(items, (row) => row.PrimaryMobile)} /></FilterHeader>,
            key: 'mobile',
            dataIndex: 'PrimaryMobile',
            width: 145,
            sorter: (a, b) => (a.PrimaryMobile ?? '').localeCompare(b.PrimaryMobile ?? '', 'fa'),
            render: (value: string | null) => value || <Text type="secondary">—</Text>,
        },
        {
            title: <FilterHeader label="شهر"><MultiSelectFilterInput label="شهر" value={colF.selections.city} onChange={(values) => setColSelection('city', values)} options={distinctOptions(items, (row) => row.PrimaryAddressCity)} /></FilterHeader>,
            key: 'city',
            dataIndex: 'PrimaryAddressCity',
            width: 145,
            sorter: (a, b) => (a.PrimaryAddressCity ?? '').localeCompare(b.PrimaryAddressCity ?? '', 'fa'),
        },
        {
            title: <FilterHeader label="آدرسِ اصلی"><TextFilterInput value={colF.values.address} onChange={(v) => setColFilter('address', v)} placeholder="استان، شهرستان، شهر، آدرس…" /></FilterHeader>,
            key: 'address',
            sorter: (a, b) => compareGridValues([a.PrimaryAddressProvince,a.PrimaryAddressCounty,a.PrimaryAddressCity,a.PrimaryAddressText].filter(Boolean).join(" "), [b.PrimaryAddressProvince,b.PrimaryAddressCounty,b.PrimaryAddressCity,b.PrimaryAddressText].filter(Boolean).join(" ")),
            width: 260,
            render: (_, p) => (
                <PrimaryAddressCell province={p.PrimaryAddressProvince} county={p.PrimaryAddressCounty} city={p.PrimaryAddressCity} text={p.PrimaryAddressText} title={p.PrimaryAddressTitle} />
            ),
        },
        {
            title: (
                <FilterHeader label="وضعیت">
                    <CategoryFilter label="وضعیت رکورد جدول" value={categories.status} onChange={(v) => setCategory('status', v)} options={[{ value: '1', label: 'فعال' }, { value: '0', label: 'غیرفعال' }]} />
                </FilterHeader>
            ),
            key: 'status',
            sorter: (a, b) => compareGridValues(Number(toBool(a.IsActive)), Number(toBool(b.IsActive))),
            align: 'center',
            render: (_, p) => statusTag(p),
        },
        {
            title: (
                <FilterHeader label="وضعیت همکاری">
                    <CategoryFilter
                        testId="cooperation-filter-table"
                        label="وضعیت همکاری جدول"
                        value={categories.cooperation}
                        onChange={(v) => setCategory('cooperation', v)}
                        options={cooperationFilterOptions.slice(1)}
                    />
                </FilterHeader>
            ),
            key: 'cooperationStatus',
            sorter: (a, b) => compareGridValues(Number(a.CooperationEndedAt != null), Number(b.CooperationEndedAt != null)),
            align: 'center',
            render: (_, p) => {
                const ended = p.CooperationEndedAt != null;
                return <Tag color={ended ? 'red' : 'success'}>{ended ? 'پایان‌یافته' : 'فعال'}</Tag>;
            },
        },
        {
            title: 'عملیات',
            key: 'actions',
            width: 170,
            align: 'center',
            render: (_, p) => (
                <div className="party-grid-actions" onClick={(e) => e.stopPropagation()}>
                    {renderActions(p)}
                </div>
            ),
        },
    ];

    const columnSettings = useGridColumnSettings('crm.parties.list', columns.map((column) => String(column.key)));
    const totalCount = items?.length || 0;
    const activeCount = items?.filter((p) => toBool(p.IsActive)).length || 0;

    return (
        <MainLayout>
            <PageHeader
                icon={<TeamOutlined />}
                title="مخاطبین / طرف‌حساب‌ها"
                subtitle="مدیریتِ فروشگاه‌ها، شرکت‌ها و کارخانه‌هایِ «مخاطبین و ارتباطات»"
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

            {viewMode === 'cards' && <Card style={{ marginBottom: 16, ...STYLES.filterCard }}>
                <Row gutter={[16, 16]} align="middle" justify="start">
                    <Col xs={24} sm={12} md={6}>
                        <Input
                            placeholder="جستجو در نام، شناسه..."
                            prefix={searching ? <LoadingOutlined style={{ color: THEME.primary }} /> : <SearchOutlined />}
                            value={searchText}
                            onChange={(e) => setSearchText(e.target.value)}
                            allowClear
                            size="large"
                        />
                    </Col>
                    <Col xs={24} sm={12} md={4}>
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
                    <Col xs={24} sm={12} md={4}>
                        <Select
                            placeholder="وضعیتِ رکورد"
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
                        <Space direction="vertical" size={4} style={{ width: '100%' }}>
                            <Text type="secondary" style={{ fontSize: 12, lineHeight: '16px' }}>
                                وضعیت همکاری
                            </Text>
                            <Select
                                data-testid="cooperation-filter"
                                aria-label="وضعیت همکاری"
                                style={{ width: '100%' }}
                                size="large"
                                value={cooperationFilter}
                                onChange={(v) => setCooperationFilter(v ?? 'all')}
                                options={cooperationFilterOptions}
                            />
                        </Space>
                    </Col>
                    <Col xs={24} sm={12} md={3}>
                        <Button icon={<ReloadOutlined />} onClick={handleReset} size="large" block>
                            بازنشانیِ همهٔ فیلترها
                        </Button>
                    </Col>
                </Row>
            </Card>}
            <div style={{ marginBottom: 16 }}><ListViewToggle value={viewMode} onChange={setViewMode} /></div>

            <Card style={STYLES.card} bodyStyle={{ padding: 20 }}>
                {searching && viewMode !== 'grid' ? (
                    <div style={{ textAlign: 'center', padding: '48px 0', color: THEME.textLight }}>
                        <LoadingOutlined style={{ fontSize: 20, color: THEME.primary }} />
                    </div>
                ) : (items || []).length === 0 && viewMode !== 'grid' ? (
                    <div style={{ textAlign: 'center', padding: '48px 0', color: THEME.textLight }}>هیچ طرف‌حسابی یافت نشد</div>
                ) : viewMode === 'grid' ? (
                    <>
                    <Space size={8} wrap style={{ marginBottom: 8 }}>
                        <Text type="secondary">{hasGridFilters ? `${filteredRows.length} از ${items.length} ردیف` : `${items.length} ردیف`}</Text>
                        <Button size="small" disabled={!hasGridFilters} icon={<ReloadOutlined />} onClick={clearGridFilters}>پاک‌کردنِ فیلترهایِ جدول</Button>
                        {columnSettings.control}
                    </Space>
                    <Table
                        rowKey="PartyID"
                        loading={searching}
                        columns={applyGridColumnSettings(columns, columnSettings)}
                        dataSource={filteredRows}
                        size="middle"
                        locale={{ emptyText: 'ردیفی با این فیلترها یافت نشد' }}
                        tableLayout="fixed"
                        scroll={{ x: 2800 }}
                        rowClassName={(p) => (toBool(p.IsActive) ? 'party-grid-row' : 'party-grid-row is-inactive')}
                        onRow={(p) => ({ onClick: () => router.visit(`/crm/parties/${p.PartyID}`) })}
                        onChange={(pg) => setPage({ current: pg.current ?? 1, pageSize: pg.pageSize ?? 20 })}
                        pagination={{
                            current: page.current,
                            pageSize: page.pageSize,
                            showSizeChanger: true,
                            pageSizeOptions: [10, 20, 50, 100],
                            showTotal: (total) => (hasGridFilters ? `${total} از ${items.length} طرف‌حساب` : `${total} طرف‌حساب`),
                        }}
                    />
                    </>
                ) : (
                    <>
                    <div className="party-cards-grid">
                        {cardPage.items.map((p) => {
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

                                    <div style={{ marginBottom: 8 }}>
                                        <PrimaryContactLine value={p.PrimaryContactValue} type={p.PrimaryContactType} extension={p.PrimaryContactExtension} personName={p.PrimaryContactPersonName} />
                                    </div>

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
                                        {renderActions(p)}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                    {cardPage.pagination}
                    </>
                )}
            </Card>

            <style>{`
                .party-cards-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(min(300px, 100%), 1fr));
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
                ${filterHeaderCss}
                .party-grid-row { cursor: pointer; }
                .party-grid-row.is-inactive td { color: ${THEME.textLight}; }
                .party-grid-actions { display: inline-flex; gap: 2px; }
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
                contactRoles={contactRoles || []}
                positions={positions || []}
                partyTypes={partyTypes || []}
                activities={activities || []}
            />

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </MainLayout>
    );
}
