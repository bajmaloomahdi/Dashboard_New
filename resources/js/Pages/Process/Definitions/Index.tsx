import { useState, useEffect, useRef } from 'react';
import { Card, Button, Input, Space, Tag, Tooltip, Typography, Row, Col, Select, Popconfirm } from 'antd';
import {
    PlusOutlined,
    EditOutlined,
    SearchOutlined,
    ReloadOutlined,
    CheckCircleOutlined,
    StopOutlined,
    ApartmentOutlined,
    EyeOutlined,
    LoadingOutlined,
    BranchesOutlined,
    PlayCircleOutlined,
    TagsOutlined,
    UserOutlined,
    CalendarOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../../Layouts/MainLayout';
import PageHeader from '../../../Components/PageHeader';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import DefinitionFormModal from './DefinitionFormModal';
import CategoryManagerModal, { type WorkflowCategory } from './CategoryManagerModal';
import { wfApi } from '../../../Components/Workflow/workflowApi';
import { THEME, STYLES } from '../../../theme';
import { gregorianToJalaliDisplay } from '../../../Utils/jalali';
import { toBool } from '../../../Utils/bool';

const { Title, Text } = Typography;

interface Definition {
    DefinitionID: number;
    Code: string;
    Name: string;
    Description: string | null;
    EntityType: string;
    IsActive: boolean | number | string;
    CategoryID: number | null;
    CategoryName: string | null;
    Date_InsertFirst: string;
    CreatedByName: string | null;
    VersionCount: number;
    ActiveVersionNo: number | null;
    InstanceCount: number;
}

/**
 * وضعیتِ نسخه از خودِ وضعیتِ فرایند (IsActive) کاملاً جداست — یکی نیست. IsActive
 * فقط یعنی «آیا Startِ نمونهٔ جدید مجاز است»؛ این‌جا فقط بر اساسِ VersionCount/
 * ActiveVersionNoِ موجود می‌گوییم که آیا این فرایند اصلاً نسخهٔ منتشرشده‌ای دارد یا نه
 * (تفکیکِ دقیقِ «پیش‌نویس» از «فقط بایگانی‌شده» به فیلدِ دیگری از Backend نیاز دارد
 * که فعلاً برگردانده نمی‌شود).
 */
function versionStatusLabel(def: Definition): { text: string; color: string } {
    if (def.ActiveVersionNo) {
        return { text: `منتشرشده: v${def.ActiveVersionNo}`, color: 'blue' };
    }
    if (def.VersionCount > 0) {
        return { text: 'بدونِ نسخهٔ منتشرشده', color: 'gold' };
    }
    return { text: 'بدونِ نسخه', color: 'default' };
}

export default function ProcessDefinitionsIndex() {
    const { definitions, filters, categories, permissions } = usePage().props as unknown as {
        definitions: Definition[];
        filters: { search: string | null; isActive: boolean | null; categoryId: number | null };
        categories: WorkflowCategory[];
        permissions: string[];
    };

    const canDesign = (permissions || []).includes('WORKFLOW_DESIGN');
    const canManageCategories = (permissions || []).includes('WORKFLOW_MANAGE_CATEGORIES');

    const [searchText, setSearchText] = useState(filters?.search || '');
    const [statusFilter, setStatusFilter] = useState<string | null>(
        filters?.isActive === null || filters?.isActive === undefined ? null : filters.isActive ? '1' : '0'
    );
    const [categoryFilter, setCategoryFilter] = useState<number | null>(filters?.categoryId ?? null);
    const [categoryManagerOpen, setCategoryManagerOpen] = useState(false);
    const [searching, setSearching] = useState(false);

    const [modalOpen, setModalOpen] = useState(false);
    const [editingDefinition, setEditingDefinition] = useState<Definition | null>(null);
    const [togglingId, setTogglingId] = useState<number | null>(null);

    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({
        open: false,
        type: 'success',
        message: '',
    });
    const showNotification = (type: NotificationType, message: string) => setNotification({ open: true, type, message });
    const closeNotification = () => setNotification((prev) => ({ ...prev, open: false }));

    const searchTimeoutRef = useRef<NodeJS.Timeout | null>(null);
    const isFirstRender = useRef(true);

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            return;
        }

        setSearching(true);
        if (searchTimeoutRef.current) clearTimeout(searchTimeoutRef.current);

        searchTimeoutRef.current = setTimeout(() => {
            router.get(
                '/process/definitions',
                {
                    search: searchText || undefined,
                    isActive: statusFilter !== null ? statusFilter : undefined,
                    categoryId: categoryFilter !== null ? categoryFilter : undefined,
                },
                { preserveState: true, preserveScroll: true, replace: true, only: ['definitions', 'filters'], onFinish: () => setSearching(false) }
            );
        }, 300);

        return () => {
            if (searchTimeoutRef.current) clearTimeout(searchTimeoutRef.current);
        };
    }, [searchText, statusFilter, categoryFilter]);

    const handleReset = () => {
        setSearchText('');
        setStatusFilter(null);
        setCategoryFilter(null);
    };

    const handleCategoriesChanged = () => {
        router.reload({ only: ['categories', 'definitions'] });
    };

    const handleCreate = () => {
        setEditingDefinition(null);
        setModalOpen(true);
    };

    const handleEdit = (def: Definition) => {
        setEditingDefinition(def);
        setModalOpen(true);
    };

    const handleModalSuccess = async (message: string, definitionId: number, isNew: boolean) => {
        setModalOpen(false);
        setEditingDefinition(null);
        showNotification('success', message);

        // برایِ Definitionِ تازه‌ایجادشده، بلافاصله یک نسخهٔ Draft می‌سازیم (همان
        // Endpointِ POST موجود که WORKFLOW_DESIGN را چک می‌کند — دقیقاً همان مسیری
        // که دکمهٔ «نسخهٔ پیش‌نویسِ جدید» در صفحهٔ تاریخچه از قبل استفاده می‌کند) و
        // مستقیم به Canvas می‌رویم. این یک POST/Write است، نه بخشی از GET /open.
        if (isNew) {
            const draftRes = await wfApi(`/workflow/definitions/${definitionId}/versions`, 'POST');
            if (draftRes.ok && draftRes.success) {
                router.visit(`/process/versions/${draftRes.versionId}`);
            } else {
                // بدونِ WORKFLOW_DESIGN یا هر خطایِ دیگر: به صفحهٔ تاریخچهٔ همان Definition می‌رویم
                router.visit(`/process/definitions/${definitionId}`);
            }
            return;
        }

        // 'categories' هم Reload می‌شود چون DefinitionCountِ هر دسته (در CategoryManagerModal)
        // با تغییرِ CategoryIDِ این Definition ممکن است عوض شده باشد.
        router.reload({ only: ['definitions', 'categories'] });
    };

    const handleToggle = async (def: Definition) => {
        setTogglingId(def.DefinitionID);
        const res = await wfApi(`/workflow/definitions/${def.DefinitionID}/toggle`, 'POST');
        setTogglingId(null);

        if (!res.ok || !res.success) {
            showNotification('error', res.message);
            return;
        }

        showNotification('success', res.message);
        router.reload({ only: ['definitions'] });
    };

    const totalCount = definitions?.length || 0;
    const activeCount = definitions?.filter((d) => toBool(d.IsActive)).length || 0;
    const runningInstances = definitions?.reduce((sum, d) => sum + (d.InstanceCount || 0), 0) || 0;

    return (
        <MainLayout>
            <PageHeader
                icon={<ApartmentOutlined />}
                title="اتوماسیونِ فرایند"
                subtitle="مدیریتِ تعریف‌هایِ Workflow"
                stats={[
                    { icon: <ApartmentOutlined />, label: 'کلِ فرایندها', value: `${totalCount} فرایند` },
                    { icon: <CheckCircleOutlined />, label: 'فعال', value: `${activeCount} فرایند` },
                    { icon: <PlayCircleOutlined />, label: 'نمونه‌هایِ ساخته‌شده', value: `${runningInstances} نمونه` },
                ]}
                actions={
                    <Space>
                        {canManageCategories && (
                            <Button icon={<TagsOutlined />} size="large" onClick={() => setCategoryManagerOpen(true)}>
                                مدیریتِ دسته‌بندی‌ها
                            </Button>
                        )}
                        {canDesign && (
                            <Button type="primary" icon={<PlusOutlined />} size="large" style={STYLES.primaryButton} onClick={handleCreate}>
                                فرایندِ جدید
                            </Button>
                        )}
                    </Space>
                }
            />

            <Card style={{ marginBottom: 16, ...STYLES.filterCard }}>
                <Row gutter={[16, 16]} align="middle">
                    <Col xs={24} sm={12} md={9}>
                        <Input
                            placeholder="جستجو در نام، کد، نوعِ موجودیت..."
                            prefix={searching ? <LoadingOutlined style={{ color: THEME.primary }} /> : <SearchOutlined />}
                            value={searchText}
                            onChange={(e) => setSearchText(e.target.value)}
                            allowClear
                            size="large"
                        />
                    </Col>
                    <Col xs={24} sm={12} md={5}>
                        <Select
                            placeholder="فیلترِ وضعیت"
                            style={{ width: '100%' }}
                            size="large"
                            value={statusFilter}
                            onChange={(value) => setStatusFilter(value)}
                            allowClear
                            options={[
                                { value: '1', label: 'فعال' },
                                { value: '0', label: 'غیرفعال' },
                            ]}
                        />
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Select
                            placeholder="فیلترِ دسته‌بندی"
                            style={{ width: '100%' }}
                            size="large"
                            value={categoryFilter ?? undefined}
                            onChange={(value) => setCategoryFilter(value ?? null)}
                            allowClear
                            showSearch
                            optionFilterProp="label"
                            options={(categories || []).map((c) => ({ value: c.CategoryID, label: c.Name }))}
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
                ) : (definitions || []).length === 0 ? (
                    <div style={{ textAlign: 'center', padding: '48px 0', color: THEME.textLight }}>
                        هیچ فرایندی یافت نشد
                    </div>
                ) : (
                    <div className="definition-cards-grid">
                        {(definitions || []).map((def) => {
                            const isActive = toBool(def.IsActive);
                            const vStatus = versionStatusLabel(def);
                            return (
                                <div
                                    className="definition-card"
                                    key={def.DefinitionID}
                                    onClick={() => router.visit(`/process/definitions/${def.DefinitionID}/open`)}
                                >
                                    <div className="definition-card-header">
                                        <span style={STYLES.codeBadge}>{def.Code}</span>
                                        <Space size={4}>
                                            <Tag
                                                icon={isActive ? <CheckCircleOutlined /> : <StopOutlined />}
                                                color={isActive ? 'success' : 'default'}
                                                style={{ borderRadius: 6, margin: 0 }}
                                            >
                                                {isActive ? 'فعال' : 'غیرفعال'}
                                            </Tag>
                                        </Space>
                                    </div>

                                    <Title level={5} style={{ margin: '10px 0 4px', color: THEME.textPrimary }}>
                                        <ApartmentOutlined style={{ marginLeft: 6, color: THEME.primary }} />
                                        {def.Name}
                                    </Title>

                                    {def.Description ? (
                                        <Text type="secondary" style={{ fontSize: 12, display: 'block', marginBottom: 10 }}>
                                            {def.Description.length > 80 ? def.Description.substring(0, 80) + '...' : def.Description}
                                        </Text>
                                    ) : (
                                        <div style={{ marginBottom: 10 }} />
                                    )}

                                    <Space size={6} wrap style={{ marginBottom: 12 }}>
                                        <Tag color={vStatus.color} style={{ borderRadius: 6, margin: 0 }}>
                                            {vStatus.text}
                                        </Tag>
                                        {def.CategoryName ? (
                                            <Tag color="geekblue" style={{ borderRadius: 6, margin: 0 }}>{def.CategoryName}</Tag>
                                        ) : null}
                                        <Tag color="purple" style={{ borderRadius: 6, margin: 0 }}>{def.EntityType}</Tag>
                                    </Space>

                                    <div className="definition-card-meta">
                                        <Space size={6}>
                                            <BranchesOutlined style={{ color: '#2563EB' }} />
                                            <Text style={{ fontSize: 12 }}>{def.VersionCount} نسخه</Text>
                                        </Space>
                                        <Space size={6}>
                                            <PlayCircleOutlined style={{ color: def.InstanceCount > 0 ? '#16A34A' : THEME.textLight }} />
                                            <Text style={{ fontSize: 12 }}>{def.InstanceCount} نمونه</Text>
                                        </Space>
                                    </div>

                                    <div className="definition-card-meta">
                                        <Space size={6}>
                                            <UserOutlined style={{ color: THEME.textLight }} />
                                            <Text type="secondary" style={{ fontSize: 12 }}>{def.CreatedByName || '—'}</Text>
                                        </Space>
                                        <Space size={6}>
                                            <CalendarOutlined style={{ color: THEME.textLight }} />
                                            <Text type="secondary" style={{ fontSize: 12 }}>
                                                {def.Date_InsertFirst ? gregorianToJalaliDisplay(def.Date_InsertFirst) : '—'}
                                            </Text>
                                        </Space>
                                    </div>

                                    <div className="definition-card-actions" onClick={(e) => e.stopPropagation()}>
                                        <Tooltip title="باز کردن">
                                            <Button
                                                type="text"
                                                icon={<EyeOutlined />}
                                                style={{ color: THEME.primary }}
                                                onClick={() => router.visit(`/process/definitions/${def.DefinitionID}/open`)}
                                            />
                                        </Tooltip>
                                        {canDesign && (
                                            <>
                                                <Tooltip title="ویرایش">
                                                    <Button type="text" icon={<EditOutlined />} style={{ color: THEME.info }} onClick={() => handleEdit(def)} />
                                                </Tooltip>
                                                <Popconfirm
                                                    title={isActive ? 'غیرفعال‌کردنِ فرایند' : 'فعال‌کردنِ فرایند'}
                                                    description={isActive ? 'پس از غیرفعال‌شدن، Startِ نمونهٔ جدید برایِ این فرایند ممکن نخواهد بود؛ نمونه‌هایِ در‌حالِ‌اجرا تحتِ تأثیر قرار نمی‌گیرند.' : 'آیا مطمئن هستید؟'}
                                                    onConfirm={() => handleToggle(def)}
                                                    okText="بله"
                                                    cancelText="خیر"
                                                    okButtonProps={{ danger: isActive }}
                                                >
                                                    <Tooltip title={isActive ? 'غیرفعال کردن' : 'فعال کردن'}>
                                                        <Button
                                                            type="text"
                                                            icon={isActive ? <StopOutlined /> : <CheckCircleOutlined />}
                                                            loading={togglingId === def.DefinitionID}
                                                            style={{ color: isActive ? THEME.error : THEME.success }}
                                                        />
                                                    </Tooltip>
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
                .definition-cards-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                    gap: 18px;
                }
                .definition-card {
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
                .definition-card:hover {
                    transform: translateY(-8px) scale(1.02);
                    box-shadow: 0 16px 32px rgba(102, 126, 234, 0.22);
                    border-color: ${THEME.borderPrimary};
                }
                .definition-card-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }
                .definition-card-meta {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding-top: 8px;
                    border-top: 1px dashed ${THEME.borderLight};
                    margin-top: 6px;
                }
                .definition-card-actions {
                    display: flex;
                    justify-content: flex-end;
                    gap: 2px;
                    margin-top: 10px;
                    padding-top: 8px;
                    border-top: 1px solid ${THEME.borderLight};
                }
            `}</style>

            <DefinitionFormModal
                open={modalOpen}
                onClose={() => {
                    setModalOpen(false);
                    setEditingDefinition(null);
                }}
                editingDefinition={editingDefinition}
                categories={categories || []}
                onSuccess={handleModalSuccess}
            />

            <CategoryManagerModal
                open={categoryManagerOpen}
                onClose={() => setCategoryManagerOpen(false)}
                categories={categories || []}
                onChanged={handleCategoriesChanged}
            />

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={closeNotification} />
        </MainLayout>
    );
}
