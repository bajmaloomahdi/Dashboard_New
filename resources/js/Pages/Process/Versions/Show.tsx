import { useEffect, useMemo, useState } from 'react';
import { Card, Tag, Space, Button, Typography, Alert, Modal, Empty, List } from 'antd';
import {
    ApartmentOutlined,
    SaveOutlined,
    CheckCircleOutlined,
    CloseCircleOutlined,
    RocketOutlined,
    CopyOutlined,
    NodeIndexOutlined,
    WarningOutlined,
    EditOutlined,
    FilterOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../../Layouts/MainLayout';
import PageHeader from '../../../Components/PageHeader';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import DesignerCanvas from './Designer/DesignerCanvas';
import ConditionFieldManagerModal from './Designer/ConditionFieldManagerModal';
import type { ConditionField } from './Designer/ruleTypes';
import DefinitionFormModal from '../Definitions/DefinitionFormModal';
import type { WorkflowCategory } from '../Definitions/CategoryManagerModal';
import { wfApi } from '../../../Components/Workflow/workflowApi';
import { THEME, STYLES } from '../../../theme';
import {
    graphFromServer,
    graphToPayload,
    graphSignature,
    lintGraph,
    type GraphDraft,
} from './versionEditor';

const { Text } = Typography;

const gradientHeadStyle = { background: THEME.primaryGradient, border: 'none', borderRadius: '12px 12px 0 0' };
const whiteTitle = (text: any) => <span style={{ color: '#fff', fontWeight: 600 }}>{text}</span>;

interface VersionMeta {
    VersionID: number;
    DefinitionID: number;
    VersionNo: number;
    Status: 'DRAFT' | 'ACTIVE' | 'ARCHIVED';
    ValidationResultJson: string | null;
    PublishedAt: string | null;
    ArchivedAt: string | null;
    ClonedFromVersionID: number | null;
    DefinitionCode: string;
    DefinitionName: string;
    EntityType: string;
    CategoryID: number | null;
    CategoryName: string | null;
}

interface LookupOption {
    UserID?: number; RoleID?: number; PositionID?: number; UnitID?: number;
    FullName?: string; RoleName?: string; PositionName?: string; UnitName?: string;
}

/** شکلِ کاملِ Definition — همان چیزی که DefinitionFormModal برایِ ویرایش نیاز دارد. */
interface FullDefinition {
    DefinitionID: number;
    Code: string;
    Name: string;
    Description: string | null;
    EntityType: string;
    IsActive: boolean | number | string;
    CategoryID: number | null;
}

interface PageProps {
    meta: VersionMeta;
    steps: any[]; actions: any[]; assignments: any[]; transitions: any[];
    permissions: string[];
    users: LookupOption[]; roles: LookupOption[]; positions: LookupOption[]; units: LookupOption[];
    categories: WorkflowCategory[];
    conditionFields: ConditionField[];
}

const statusTag: Record<string, { color: string; label: string }> = {
    DRAFT: { color: 'default', label: 'پیش‌نویس' },
    ACTIVE: { color: 'success', label: 'فعال' },
    ARCHIVED: { color: 'purple', label: 'بایگانی‌شده' },
};

interface PersistedValidation { ok: boolean; errors: string[]; warnings: string[]; checkedAt?: string }

function parsePersistedValidation(json: string | null): PersistedValidation | null {
    if (!json) return null;
    try {
        const parsed = JSON.parse(json);
        return { ok: !!parsed.ok, errors: parsed.errors || [], warnings: parsed.warnings || [], checkedAt: parsed.checkedAt };
    } catch {
        return null;
    }
}

export default function ProcessVersionShow() {
    const props = usePage().props as unknown as PageProps;

    const [meta, setMeta] = useState<VersionMeta>(props.meta);
    const [graph, setGraph] = useState<GraphDraft>(() => graphFromServer(props));
    const [baselineSig, setBaselineSig] = useState<string>(() => graphSignature(graphFromServer(props)));
    const [freshValidation, setFreshValidation] = useState<{ ok: boolean; errors: string[]; warnings: string[] } | null>(null);

    const [saving, setSaving] = useState(false);
    const [validating, setValidating] = useState(false);
    const [publishing, setPublishing] = useState(false);
    const [cloning, setCloning] = useState(false);
    const [reloading, setReloading] = useState(false);
    const [publishConfirmOpen, setPublishConfirmOpen] = useState(false);

    const [definitionModalOpen, setDefinitionModalOpen] = useState(false);
    const [editingDefinitionData, setEditingDefinitionData] = useState<FullDefinition | null>(null);
    const [loadingDefinition, setLoadingDefinition] = useState(false);

    const [conditionFields, setConditionFields] = useState<ConditionField[]>(props.conditionFields || []);
    const [conditionFieldModalOpen, setConditionFieldModalOpen] = useState(false);

    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({
        open: false, type: 'success', message: '',
    });
    const notify = (type: NotificationType, message: string) => setNotification({ open: true, type, message });

    const permissions = props.permissions || [];
    const hasDesign = permissions.includes('WORKFLOW_DESIGN');
    const hasPublish = permissions.includes('WORKFLOW_PUBLISH');
    const hasManageConditionFields = permissions.includes('WORKFLOW_MANAGE_CONDITION_FIELDS');
    const isDraft = meta.Status === 'DRAFT';
    const isEditable = isDraft && hasDesign;
    const canValidate = isEditable;
    const canPublish = isDraft && hasPublish;
    const canClone = hasDesign;

    const dirty = graphSignature(graph) !== baselineSig;
    const lint = useMemo(() => lintGraph(graph), [graph]);

    const persistedValidation = useMemo(() => parsePersistedValidation(meta.ValidationResultJson), [meta.ValidationResultJson]);
    const displayValidation = freshValidation ?? persistedValidation;
    const validationStale = dirty && displayValidation !== null;

    /* ---------- هشدارِ تغییراتِ ذخیره‌نشده (Unsaved Changes) ---------- */
    useEffect(() => {
        const handler = (e: BeforeUnloadEvent) => {
            if (dirty) { e.preventDefault(); e.returnValue = ''; }
        };
        window.addEventListener('beforeunload', handler);
        return () => window.removeEventListener('beforeunload', handler);
    }, [dirty]);

    useEffect(() => {
        return router.on('before', (event: any) => {
            if (dirty && !window.confirm('تغییراتِ ذخیره‌نشده دارید. آیا مطمئنید می‌خواهید این صفحه را ترک کنید؟')) {
                event.preventDefault();
            }
        });
    }, [dirty]);

    /* ---------- بازخوانیِ کاملِ گراف از GET /workflow/versions/{id}/graph ---------- */
    const reloadGraph = async () => {
        setReloading(true);
        const res = await wfApi(`/workflow/versions/${meta.VersionID}/graph`);
        setReloading(false);

        if (!res.ok || !res.success) {
            notify('error', res.message);
            return;
        }

        const g = graphFromServer(res as any);
        setGraph(g);
        setBaselineSig(graphSignature(g));
        setMeta(res.meta);
        setFreshValidation(null); // Save همیشه ValidationResultJson را null می‌کند؛ Publish خودش نتیجهٔ تازه را در meta می‌گذارد
    };

    const handleSave = async () => {
        if (lint.blocking.length > 0) {
            notify('error', 'پیش از ذخیره، خطاهایِ فهرست‌شده در نوارِ Linter را برطرف کنید.');
            return;
        }
        setSaving(true);
        const res = await wfApi(`/workflow/versions/${meta.VersionID}/graph`, 'PUT', graphToPayload(graph));
        setSaving(false);

        if (!res.ok || !res.success) { notify('error', res.message); return; }
        notify('success', res.message);
        await reloadGraph();
    };

    const handleValidate = async () => {
        setValidating(true);
        const res = await wfApi(`/workflow/versions/${meta.VersionID}/validate`, 'POST');
        setValidating(false);

        // نکته: پاسخِ این endpoint خودش فیلدِ `ok` دارد (معتبربودنِ گراف) که رویِ
        // فیلدِ HTTP-levelِ ok در wfApi می‌نشیند؛ لذا موفقیتِ خودِ درخواست را از
        // res.success/res.status می‌خوانیم، نه res.ok.
        if (!res.success || res.status >= 400) { notify('error', res.message); return; }

        setFreshValidation({ ok: !!res.ok, errors: res.errors || [], warnings: res.warnings || [] });
    };

    const handlePublish = async () => {
        setPublishing(true);
        const res = await wfApi(`/workflow/versions/${meta.VersionID}/publish`, 'POST');
        setPublishing(false);
        setPublishConfirmOpen(false);

        if (!res.ok || !res.success) { notify('error', res.message); return; }
        notify('success', res.message);
        await reloadGraph();
    };

    const handleClone = async () => {
        setCloning(true);
        const res = await wfApi(`/workflow/versions/${meta.VersionID}/clone`, 'POST');
        setCloning(false);

        if (!res.ok || !res.success) { notify('error', res.message); return; }
        router.visit(`/process/versions/${res.versionId}`);
    };

    /**
     * ویرایشِ اطلاعاتِ Definition (نام/EntityType/Category/توضیحات) از همینجا، بدونِ
     * ترکِ صفحهٔ Canvas. چون metaِ همین صفحه (sp_Wf_GetVersionMeta) شاملِ Description/IsActive
     * نیست (فقط Code/Name/EntityType/Category)، برایِ جلوگیری از هرگونه از‌دست‌رفتنِ داده
     * هنگامِ Submit، اطلاعاتِ کاملِ Definition را از همان Endpointِ JSONِ موجود
     * (GET /workflow/definitions/{id}) می‌خوانیم — بدونِ هیچ SP/Endpointِ جدید.
     */
    const openDefinitionEditor = async () => {
        setLoadingDefinition(true);
        const res = await wfApi(`/workflow/definitions/${meta.DefinitionID}`);
        setLoadingDefinition(false);

        if (!res.ok || !res.success) { notify('error', res.message); return; }
        setEditingDefinitionData(res.definition);
        setDefinitionModalOpen(true);
    };

    const handleDefinitionModalSuccess = async (message: string) => {
        setDefinitionModalOpen(false);
        setEditingDefinitionData(null);
        notify('success', message);
        await reloadGraph(); // meta (Name/EntityType/CategoryName) را هم تازه می‌کند
    };

    /** بازخوانیِ فیلدهایِ شرط از همان GET /workflow/definitions/{id}/condition-fields موجود */
    const refreshConditionFields = async () => {
        const res = await wfApi(`/workflow/definitions/${meta.DefinitionID}/condition-fields?includeInactive=1`);
        if (res.ok && res.success) {
            setConditionFields(res.items || []);
        }
    };

    return (
        <MainLayout>
            <PageHeader
                icon={<ApartmentOutlined />}
                title={meta.DefinitionName}
                subtitle={`نسخهٔ ${meta.VersionNo}`}
                backHref={`/process/definitions/${meta.DefinitionID}`}
                backLabel="بازگشت به فرایند"
                tags={[
                    { label: statusTag[meta.Status]?.label ?? meta.Status, color: meta.Status === 'ACTIVE' ? THEME.success : meta.Status === 'ARCHIVED' ? undefined : undefined },
                    { label: meta.DefinitionCode },
                    { label: meta.EntityType },
                    ...(meta.CategoryName ? [{ label: meta.CategoryName, color: THEME.info }] : []),
                ]}
                actions={
                    <Space wrap>
                        {isEditable && (
                            <Button icon={<EditOutlined />} loading={loadingDefinition} onClick={openDefinitionEditor}>
                                ویرایشِ اطلاعاتِ فرایند
                            </Button>
                        )}
                        {hasManageConditionFields && (
                            <Button icon={<FilterOutlined />} onClick={() => setConditionFieldModalOpen(true)}>
                                مدیریتِ فیلدهایِ شرط
                            </Button>
                        )}
                        {isEditable && (
                            <Button type="primary" icon={<SaveOutlined />} loading={saving} onClick={handleSave} style={STYLES.primaryButton}>
                                ذخیرهٔ گراف {dirty ? '●' : ''}
                            </Button>
                        )}
                        {canValidate && (
                            <Button icon={<CheckCircleOutlined />} loading={validating} onClick={handleValidate}>
                                اعتبارسنجی
                            </Button>
                        )}
                        {canPublish && (
                            <Button
                                icon={<RocketOutlined />}
                                loading={publishing}
                                disabled={dirty}
                                title={dirty ? 'ابتدا تغییرات را ذخیره کنید.' : undefined}
                                onClick={() => setPublishConfirmOpen(true)}
                            >
                                انتشار
                            </Button>
                        )}
                        {canClone && (
                            <Button icon={<CopyOutlined />} loading={cloning} onClick={handleClone}>
                                Clone
                            </Button>
                        )}
                    </Space>
                }
            />

            {!isDraft ? (
                <Alert
                    type="info"
                    showIcon
                    message={`این نسخه ${statusTag[meta.Status]?.label ?? meta.Status} است — فقط قابلِ مشاهده`}
                    description="ویرایش/ذخیره/اعتبارسنجی/انتشار فقط برایِ نسخهٔ پیش‌نویس (DRAFT) ممکن است. برایِ ایجادِ نسخهٔ قابلِ ویرایش، از دکمهٔ Clone استفاده کنید."
                    style={{ marginBottom: 16, borderRadius: 8 }}
                />
            ) : !hasDesign ? (
                <Alert type="info" showIcon message="شما فقط دسترسیِ مشاهده دارید؛ برایِ ویرایش به WORKFLOW_DESIGN نیاز است." style={{ marginBottom: 16, borderRadius: 8 }} />
            ) : null}

            {dirty && (
                <Alert type="warning" showIcon message="تغییراتِ ذخیره‌نشده دارید." style={{ marginBottom: 16, borderRadius: 8 }} />
            )}

            {isEditable && (lint.blocking.length > 0 || lint.advisory.length > 0) && (
                <Card style={{ ...STYLES.card, marginBottom: 16 }} title={whiteTitle(<Space><WarningOutlined /><span>بررسیِ محلیِ گراف (Linter)</span></Space>)} headStyle={gradientHeadStyle}>
                    {lint.blocking.length > 0 && (
                        <Alert
                            type="error" showIcon style={{ marginBottom: lint.advisory.length ? 12 : 0 }}
                            message={`${lint.blocking.length} خطایِ مسدودکننده — ذخیره تا رفعِ این‌ها ممکن نیست`}
                            description={<List size="small" dataSource={lint.blocking} renderItem={(i) => <List.Item>{i.message}</List.Item>} />}
                        />
                    )}
                    {lint.advisory.length > 0 && (
                        <Alert
                            type="warning" showIcon
                            message={`${lint.advisory.length} هشدار (مانعِ ذخیره نیست، ولی احتمالاً مانعِ اعتبارسنجی/انتشار می‌شود)`}
                            description={<List size="small" dataSource={lint.advisory} renderItem={(i) => <List.Item>{i.message}</List.Item>} />}
                        />
                    )}
                </Card>
            )}

            <Card
                style={{ ...STYLES.card, marginBottom: 16 }}
                title={whiteTitle(<Space><CheckCircleOutlined /><span>نتیجهٔ اعتبارسنجی</span></Space>)}
                headStyle={gradientHeadStyle}
            >
                {validationStale && (
                    <Alert type="warning" showIcon message="نیازمندِ اعتبارسنجیِ مجدد — گراف پس از آخرین اعتبارسنجی تغییر کرده است." style={{ marginBottom: 12, borderRadius: 8 }} />
                )}
                {displayValidation === null ? (
                    <Empty description="هنوز اعتبارسنجی نشده است" />
                ) : (
                    <>
                        <Tag icon={displayValidation.ok ? <CheckCircleOutlined /> : <CloseCircleOutlined />} color={displayValidation.ok ? 'success' : 'error'} style={{ borderRadius: 6, marginBottom: 10 }}>
                            {displayValidation.ok ? 'گراف معتبر است' : 'گراف نامعتبر است'}
                        </Tag>
                        {!freshValidation && persistedValidation?.checkedAt ? (
                            <Text type="secondary" style={{ display: 'block', fontSize: 12, marginBottom: 8 }}>
                                آخرین اعتبارسنجیِ Backend — {new Date(persistedValidation.checkedAt).toLocaleString('fa-IR')}
                            </Text>
                        ) : freshValidation ? (
                            <Text type="secondary" style={{ display: 'block', fontSize: 12, marginBottom: 8 }}>همین الان بررسی شد</Text>
                        ) : null}
                        {displayValidation.errors.length > 0 && (
                            <List size="small" header="خطاها" dataSource={displayValidation.errors} renderItem={(e) => <List.Item><Text type="danger">{e}</Text></List.Item>} />
                        )}
                        {displayValidation.warnings.length > 0 && (
                            <List size="small" header="هشدارها" dataSource={displayValidation.warnings} renderItem={(w) => <List.Item><Text type="warning">{w}</Text></List.Item>} />
                        )}
                    </>
                )}
            </Card>

            <Card
                style={{ ...STYLES.card, marginBottom: 16 }}
                title={whiteTitle(<Space><NodeIndexOutlined /><span>نمودارِ فرایند (Process Designer)</span></Space>)}
                headStyle={gradientHeadStyle}
                bodyStyle={{ padding: 12 }}
            >
                <DesignerCanvas
                    graph={graph}
                    readOnly={!isEditable}
                    onChange={setGraph}
                    users={props.users}
                    roles={props.roles}
                    positions={props.positions}
                    units={props.units}
                    conditionFields={conditionFields}
                />
            </Card>

            <Modal
                open={publishConfirmOpen}
                title="انتشارِ نسخه"
                onCancel={() => setPublishConfirmOpen(false)}
                onOk={handlePublish}
                okText="بله، منتشر کن"
                cancelText="انصراف"
                confirmLoading={publishing}
            >
                <Alert
                    type="warning" showIcon
                    message="این عملیات غیرقابلِ بازگشت است"
                    description="این نسخه ACTIVE می‌شود و نسخهٔ ACTIVEِ فعلیِ همین فرایند (اگر وجود داشته باشد) به‌طورِ خودکار ARCHIVED خواهد شد."
                />
            </Modal>

            <DefinitionFormModal
                open={definitionModalOpen}
                onClose={() => { setDefinitionModalOpen(false); setEditingDefinitionData(null); }}
                editingDefinition={editingDefinitionData}
                categories={props.categories || []}
                onSuccess={handleDefinitionModalSuccess}
            />

            <ConditionFieldManagerModal
                open={conditionFieldModalOpen}
                onClose={() => setConditionFieldModalOpen(false)}
                definitionId={meta.DefinitionID}
                fields={conditionFields}
                onChanged={refreshConditionFields}
            />

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((prev) => ({ ...prev, open: false }))} />
        </MainLayout>
    );
}
