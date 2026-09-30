import { useState, useEffect } from 'react';
import {
    Card,
    Button,
    Form,
    Input,
    InputNumber,
    Select,
    Radio,
    Space,
    Tag,
    Typography,
    Row,
    Col,
    Alert,
    Upload,
    Spin,
    TimePicker,
} from 'antd';
import dayjs from 'dayjs';
import {
    SendOutlined,
    MessageOutlined,
    UserOutlined,
    TeamOutlined,
    CrownOutlined,
    CopyOutlined,
    UploadOutlined,
    PaperClipOutlined,
    ThunderboltOutlined,
    ApartmentOutlined,
    FileTextOutlined,
    LockOutlined,
} from '@ant-design/icons';
import { router, usePage, useForm } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import PageHeader from '../../Components/PageHeader';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import PersianDateInput from '../../Components/PersianDateInput';
import { getPriorityPalette } from '../../Components/PriorityTag';
import { gregorianToJalaliDisplay } from '../../Utils/jalali';
import { THEME, STYLES, columnHelpers } from '../../theme';
import { wfApi } from '../../Components/Workflow/workflowApi';

const { Text } = Typography;

/** مقدارِ Sentinel برایِ گزینهٔ «پیامِ فرایندی» در کنترلِ «نوع پیام» — هرگز با یک MessageTypeIDِ واقعی برخورد نمی‌کند. */
const WORKFLOW_MESSAGE_TYPE = -1;

interface WfDefinition {
    DefinitionID: number;
    Code: string;
    Name: string;
    EntityType: string;
    ActiveVersionNo: number | null;
}

interface LetterTemplate {
    LetterTemplateID: number;
    Code: string;
    Name: string;
    EntityType: string;
    DefinitionID: number | null;
    SubjectTemplate: string;
    BodyTemplate: string;
}

interface TemplateParam {
    Code: string;
    Caption: string;
    DataType: string;
    SourceType: string;
    SourceKey: string;
}

/**
 * Workflow Start Context — فقط برایِ «نمایش». Submit هیچ‌یک از این مقادیر را به سرور نمی‌فرستد؛
 * سرور آغازکننده و گیرندگان را دوباره از Assignmentِ واقعیِ Stepِ اول محاسبه می‌کند.
 */
interface RecipientPreview {
    resolved: boolean;
    reason: string | null;
    users: { userId: number; fullName: string | null }[];
    startable: boolean;
    message: string | null;
    starter: { userId: number; fullName: string | null; positionName: string | null; unitName: string | null } | null;
    step: { code: string; name: string; assignPolicy: string; requiredApprovals: number | null } | null;
    /** گیرنده به شرطِ فرایند بستگی دارد و پیش از Submit قابلِ‌تعیین نیست — startable=true اما users=[]. */
    deferred: boolean;
}

const ASSIGN_POLICY_HINT: Record<string, string> = {
    ANY: 'هرکدام از گیرندگان می‌تواند اقدام کند.',
    ALL: 'همهٔ گیرندگان باید اقدام کنند.',
};

type TemplateSegment = { type: 'text'; value: string } | { type: 'token'; code: string };

const WF_TOKEN_PATTERN = /\{\{([A-Z][A-Z0-9_]{1,49})\}\}/g;

/**
 * جداکنندهٔ سه‌رقمی برایِ نمایش/ورودیِ مقادیرِ عددیِ INTEGER/DECIMALِ فرمِ نامهٔ فرایندی
 * (مثلِ مبلغ) — نه شناسه‌ها (UserID/MessageID/...). هم‌راستا با `columnHelpers.formatNumber`
 * (همان قراردادِ en-US/کاما) ولی بدونِ Roundingِ اعشار، چون این‌جا مقدارِ زنده‌یِ ورودیِ کاربر
 * است (ممکن است DECIMAL باشد)؛ `columnHelpers.formatNumber` برایِ Roundِ نمایشیِ Read-Only
 * (مثلِ DataGrid) دست‌نخورده می‌ماند.
 */
const formatNumberInput = (value: number | string | undefined): string => {
    if (value === undefined || value === null || value === '') return '';
    return String(value).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
};
const parseNumberInput = (value: string | undefined): string => (value ?? '').replace(/,/g, '');

/** شکستنِ متنِ خامِ قالب به دنباله‌ای از تکه‌های متنِ‌ثابت/Token — برایِ رندرِ Inline دقیقاً در محلِ خودِ Token. */
function splitTemplateIntoSegments(text: string): TemplateSegment[] {
    const segments: TemplateSegment[] = [];
    const re = new RegExp(WF_TOKEN_PATTERN.source, 'g');
    let lastIndex = 0;
    let m: RegExpExecArray | null;
    while ((m = re.exec(text)) !== null) {
        if (m.index > lastIndex) segments.push({ type: 'text', value: text.slice(lastIndex, m.index) });
        segments.push({ type: 'token', code: m[1] });
        lastIndex = re.lastIndex;
    }
    if (lastIndex < text.length) segments.push({ type: 'text', value: text.slice(lastIndex) });
    return segments;
}

const RECIPIENT_REASON_LABEL: Record<string, string> = {
    CONDITION: 'این فرایند از طریقِ نامهٔ فرایندی قابلِ شروع نیست: گیرندهٔ مرحلهٔ اول به شرط‌هایِ فرایند بستگی دارد.',
    NO_TASK: 'این فرایند مرحلهٔ تسکی ندارد؛ نامهٔ فرایندی برایِ آن معنا ندارد.',
    NO_ASSIGNEE_FOUND: 'برایِ این فرایند در حالِ حاضر کاربرِ فعالی یافت نشد.',
    NO_ACTIVE_VERSION: 'این فرایند نسخهٔ فعالی ندارد.',
    NO_START_STEP: 'تعریفِ این فرایند ناقص است (بدونِ مرحلهٔ شروع).',
    DEAD_END: 'مسیرِ این فرایند به بن‌بست می‌رسد.',
    UNSUPPORTED_STEP: 'نوعِ مرحله‌ای در این مسیر پشتیبانی نمی‌شود.',
    LOOP_GUARD: 'مسیرِ این فرایند قابلِ‌محاسبه نیست.',
};

interface MessageType {
    MessageTypeID: number;
    MessageTypeName: string;
}

interface MsgPriority {
    msgPriorityID: number;
    Code: number;
    Name: string;
    Description: string | null;
    SortOrder: number;
}

interface TargetUser {
    UserID: number;
    FullName: string;
    UserName: string;
    UnitName: string | null;
    IsManager: boolean | number;
}

interface TaskUnit {
    UnitID: number;
    UnitCode: string;
    UnitName: string;
    ManagerUserID: number | null;
    ManagerName: string | null;
}

export default function MessageCreate() {
    const { messageTypes, priorities, targets, taskUnits, flash } = usePage().props as any;

    const [form] = Form.useForm();
    const [fileList, setFileList] = useState<any[]>([]);

    const [notification, setNotification] = useState<{
        open: boolean;
        type: NotificationType;
        message: string;
    }>({ open: false, type: 'success', message: '' });

    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        MessageTypeID: null as number | null,
        msgPriorityID: null as number | null,
        Subject: '',
        MessageText: '',
        RecipientType: 1,
        RecipientUserIDs: [] as number[],
        CopyUserIDs: [] as number[],
        CopyDescription: '',
        DueDate: null as string | null,
        attachments: [] as File[],
    });

    /* ---------------- ارسال بر اساسِ فرایند — بخشی از کنترلِ «نوع پیام» ---------------- */
    const [wfDefinitions, setWfDefinitions] = useState<WfDefinition[]>([]);
    const [wfAllParams, setWfAllParams] = useState<TemplateParam[]>([]);
    const [wfDefinitionId, setWfDefinitionId] = useState<number | null>(null);
    const [wfTemplates, setWfTemplates] = useState<LetterTemplate[]>([]);
    const [wfTemplatesLoading, setWfTemplatesLoading] = useState(false);
    const [wfTemplateId, setWfTemplateId] = useState<number | null>(null);
    const [wfFormValues, setWfFormValues] = useState<Record<string, string>>({});
    const [wfRecipient, setWfRecipient] = useState<RecipientPreview | null>(null);
    const [wfRecipientLoading, setWfRecipientLoading] = useState(false);
    const [wfRenderLoading, setWfRenderLoading] = useState(false);
    /** Tokenهایِ USER/SYSTEM که برایِ این کاربر مقدار ندارند (مثلاً کاربرِ بدونِ سمت) — از resolve-preview. */
    const [wfUnresolvedCaptions, setWfUnresolvedCaptions] = useState<string[]>([]);
    const [wfSubmitting, setWfSubmitting] = useState(false);
    const [wfError, setWfError] = useState<string | null>(null);
    /** ویرایشِ دستیِ کاربر رویِ تکه‌هایِ متنِ‌ثابتِ موضوع/متن — کلید = اندیسِ Segment. */
    const [wfSubjectTextOverrides, setWfSubjectTextOverrides] = useState<Record<number, string>>({});
    const [wfBodyTextOverrides, setWfBodyTextOverrides] = useState<Record<number, string>>({});
    /** مقدارِ واقعیِ Tokenهایِ غیرِFORM (USER/SYSTEM مثلِ USER_FULL_NAME) — از رویِ Renderِ سرور. */
    const [wfResolvedNonForm, setWfResolvedNonForm] = useState<Record<string, string>>({});

    /** «نوع پیام» = گزینهٔ Sentinelِ «پیامِ فرایندی» — تنها دروازهٔ نمایشِ بخشِ Workflow. */
    const isWorkflowMessage = data.MessageTypeID === WORKFLOW_MESSAGE_TYPE;

    useEffect(() => {
        // نوعِ فرایند از کاربر پرسیده نمی‌شود — با انتخابِ خودِ «فرایند»، EntityTypeِ آن
        // (wfSelectedDefinition.EntityType) به‌طورِ ضمنی مشخص است و همان‌جا مصرف می‌شود.
        wfApi('/workflow/definitions?isActive=1').then((res) => {
            if (res.ok && res.success) {
                setWfDefinitions((res.items || []).filter((d: WfDefinition) => d.ActiveVersionNo != null));
            }
        });
        wfApi('/workflow/template-parameters?isActive=1').then((res) => {
            if (res.ok && res.success) {
                setWfAllParams(res.items || []);
            }
        });
    }, []);

    const wfSelectedDefinition = wfDefinitions.find((d) => d.DefinitionID === wfDefinitionId) || null;
    const wfSelectedTemplate = wfTemplates.find((t) => t.LetterTemplateID === wfTemplateId) || null;

    const paramByCode = (code: string): TemplateParam | null => wfAllParams.find((p) => p.Code === code) || null;

    const wfSubjectSegments = wfSelectedTemplate ? splitTemplateIntoSegments(wfSelectedTemplate.SubjectTemplate) : [];
    const wfBodySegments = wfSelectedTemplate ? splitTemplateIntoSegments(wfSelectedTemplate.BodyTemplate) : [];

    /** کدهایِ یکتایِ Tokenهایِ FORM در Subject+Body — از رویِ همان Segmentهایِ بالا (بدونِ Scanِ دوبارهٔ متنِ خام). */
    const wfFormFields = (() => {
        const codes = new Set<string>();
        for (const seg of [...wfSubjectSegments, ...wfBodySegments]) {
            if (seg.type === 'token') codes.add(seg.code);
        }
        return wfAllParams.filter((p) => codes.has(p.Code) && p.SourceType === 'FORM');
    })();

    /** آیا Tokenِ غیرِFORMی (USER/SYSTEM) در متن هست که هنوز مقدارش از resolve-preview نیامده؟ */
    const wfHasUnresolvedNonForm = [...wfSubjectSegments, ...wfBodySegments].some((s) => {
        if (s.type !== 'token') return false;
        const param = paramByCode(s.code);
        return !!param && param.SourceType !== 'FORM' && !wfResolvedNonForm[s.code];
    });

    /** مقدارِ نهاییِ یک Token برایِ ارسال — Tokenهایِ FORM محلی/شمسی، غیرِFORM از رویِ Resolveِ سرور (خالی تا وقتِ Resolve). */
    const tokenSubmitValue = (code: string): string => {
        const param = paramByCode(code);
        if (!param) return '';
        if (param.SourceType === 'FORM') {
            const raw = wfFormValues[param.SourceKey];
            if (!raw) return '';
            return param.DataType === 'DATE' ? gregorianToJalaliDisplay(raw) : raw;
        }
        return wfResolvedNonForm[code] ?? '';
    };

    const joinSegments = (segments: TemplateSegment[], overrides: Record<number, string>): string =>
        segments.map((seg, idx) => (seg.type === 'text' ? overrides[idx] ?? seg.value : tokenSubmitValue(seg.code))).join('');

    const wfJoinedSubject = wfSelectedTemplate ? joinSegments(wfSubjectSegments, wfSubjectTextOverrides) : '';
    const wfJoinedBody = wfSelectedTemplate ? joinSegments(wfBodySegments, wfBodyTextOverrides) : '';

    /** همگام‌سازیِ موضوع/متنِ نهاییِ ساخته‌شده از Segmentها با data (برایِ Validation و ارسال). */
    useEffect(() => {
        if (!isWorkflowMessage) return;
        setData('Subject', wfJoinedSubject);
        setData('MessageText', wfJoinedBody);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isWorkflowMessage, wfJoinedSubject, wfJoinedBody]);

    const resetWfTemplateState = () => {
        setWfFormValues({});
        setWfSubjectTextOverrides({});
        setWfBodyTextOverrides({});
        setWfResolvedNonForm({});
        setWfUnresolvedCaptions([]);
    };

    const handlePickWfDefinition = (id: number) => {
        setWfDefinitionId(id);
        setWfTemplateId(null);
        setWfTemplates([]);
        resetWfTemplateState();
        setData('RecipientType', 1);
        setData('RecipientUserIDs', []);

        setWfTemplatesLoading(true);
        wfApi(`/workflow/definitions/${id}/templates`).then((res) => {
            setWfTemplatesLoading(false);
            if (res.ok && res.success) setWfTemplates(res.items || []);
        });

        setWfRecipient(null);
        setWfRecipientLoading(true);
        wfApi(`/workflow/definitions/${id}/preview-assignees`).then((res) => {
            setWfRecipientLoading(false);
            if (res.ok && res.success) {
                // فقط برایِ نمایش — گیرندگان و آغازکننده هنگامِ ارسال دوباره در سرور محاسبه می‌شوند.
                setWfRecipient({
                    resolved: res.resolved,
                    reason: res.reason,
                    users: res.users || [],
                    startable: !!res.startable,
                    message: res.message ?? null,
                    starter: res.starter ?? null,
                    step: res.step ?? null,
                    deferred: !!res.deferred,
                });
            }
        });
    };

    const handleClearWfDefinition = () => {
        setWfDefinitionId(null);
        setWfTemplates([]);
        setWfTemplateId(null);
        resetWfTemplateState();
        setWfRecipient(null);
        setData('RecipientUserIDs', []);
    };

    const handlePickWfTemplate = (id: number | null) => {
        setWfTemplateId(id);
        resetWfTemplateState();
    };

    /**
     * Resolveِ اولیهٔ Tokenهایِ غیرِFORM (USER/SYSTEM؛ مثلِ نامِ درخواست‌دهنده) بلافاصله پس از انتخابِ قالب —
     * مستقل از مقدارِ FORM (مثلِ تاریخ). Tokenهایِ FORM فقط منتظرِ ورودیِ کاربر می‌مانند.
     * رندرِ نهایی (All-or-Nothing) همیشه در سرور و هنگامِ ثبتِ نامه انجام می‌شود.
     */
    useEffect(() => {
        if (!isWorkflowMessage || !wfTemplateId) return;

        let cancelled = false;
        setWfRenderLoading(true);
        wfApi(`/workflow/templates/${wfTemplateId}/resolve-preview`, 'POST', {}).then((res) => {
            if (cancelled) return;
            setWfRenderLoading(false);
            if (res.ok && res.success) {
                setWfResolvedNonForm(res.resolved || {});
                setWfUnresolvedCaptions((res.unresolved || []).map((u: { caption: string }) => u.caption));
            }
        });

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isWorkflowMessage, wfTemplateId]);

    const handleWorkflowSubmit = async () => {
        if (!wfTemplateId || !wfDefinitionId || !wfSelectedDefinition) {
            setWfError('انتخابِ فرایند و قالب الزامی است.');
            return;
        }
        if (wfFormFields.some((f) => !wfFormValues[f.SourceKey])) {
            setWfError('مقدارِ همهٔ پارامترهایِ قالب را داخلِ متن وارد کنید.');
            return;
        }
        if (wfUnresolvedCaptions.length > 0) {
            setWfError(`مقدارِ «${wfUnresolvedCaptions.join('، ')}» برایِ شما قابلِ‌تعیین نیست؛ با مدیرِ سیستم تماس بگیرید.`);
            return;
        }
        if (wfHasUnresolvedNonForm) {
            setWfError('در حالِ تکمیلِ خودکارِ بخشی از متنِ نامه؛ چند لحظه صبر کنید.');
            return;
        }
        if (!data.msgPriorityID) {
            setWfError('انتخابِ اولویت الزامی است.');
            return;
        }
        if (!wfRecipient?.startable) {
            setWfError(wfRecipient?.message || 'گیرندهٔ این فرایند هنوز قابلِ‌تعیین نیست؛ امکانِ ارسال وجود ندارد.');
            return;
        }

        setWfSubmitting(true);
        setWfError(null);

        // ثبتِ نامه + شروعِ Workflow در یک درخواست/Transaction. آغازکننده و گیرندگان عمداً ارسال
        // نمی‌شوند: سرور آن‌ها را از Auth و Assignmentِ واقعیِ Stepِ اول دوباره محاسبه می‌کند.
        const res = await wfApi('/workflow/letters', 'POST', {
            definitionId: wfDefinitionId,
            letterTemplateId: wfTemplateId,
            formValues: wfFormValues,
            // موضوع/متنِ نهاییِ ساخته‌شده از Segmentها (شاملِ هر ویرایشِ دستیِ کاربر) — اگر خالی نباشند
            // به‌جایِ خروجیِ Renderِ سرور ذخیره می‌شوند (همان رفتارِ قبلی).
            Subject: data.Subject,
            MessageText: data.MessageText,
            msgPriorityID: data.msgPriorityID,
            CopyUserIDs: data.CopyUserIDs,
            CopyDescription: data.CopyDescription || undefined,
            DueDate: data.DueDate || undefined,
        });
        setWfSubmitting(false);

        if (!res.ok || !res.success) {
            setWfError(res.message || 'ثبتِ نامه ناموفق بود.');
            return;
        }

        router.visit(`/messages/${res.messageId}`);
    };

    useEffect(() => {
        if (flash?.success) showNotification('success', flash.success);
        if (flash?.error) showNotification('error', flash.error);
    }, [flash]);

    const showNotification = (type: NotificationType, message: string) => {
        setNotification({ open: true, type, message });
    };

    const closeNotification = () => {
        setNotification((prev) => ({ ...prev, open: false }));
    };

    const selectedType = messageTypes?.find(
        (t: MessageType) => t.MessageTypeID === data.MessageTypeID
    );
    const isTask = selectedType?.MessageTypeName === 'وظیفه';

    /* ---------------- اولویت‌ها ---------------- */
    const priorityList: MsgPriority[] = priorities || [];
    const maxSortOrder = Math.max(1, ...priorityList.map((p) => p.SortOrder));

    const priorityOptions = priorityList.map((p: MsgPriority) => ({
        value: p.msgPriorityID,
        label: p.Name,
        title: p.Description || p.Name,
        __sortOrder: p.SortOrder,
    }));

    const selectedPriority = priorityList.find(
        (p: MsgPriority) => p.msgPriorityID === data.msgPriorityID
    );

    const userOptions = (targets || []).map((t: TargetUser) => ({
        value: t.UserID,
        label: (
            <Space>
                <Text>{t.FullName}</Text>
                {t.IsManager === 1 || t.IsManager === true ? (
                    <Tag icon={<CrownOutlined />} color="gold" style={{ borderRadius: 6, marginInlineEnd: 0 }}>
                        مدیر
                    </Tag>
                ) : null}
                {t.UnitName ? <Text type="secondary" style={{ fontSize: 12 }}>{t.UnitName}</Text> : null}
            </Space>
        ),
    }));

    const unitOptions = (taskUnits || []).map((t: TaskUnit) => ({
        value: t.UnitID,
        label: `${t.UnitName} (${t.ManagerName || 'بدون مدیر'})`,
        disabled: !t.ManagerUserID,
    }));

    const handleUnitChange = (unitId: number) => {
        const unit = (taskUnits || []).find((t: TaskUnit) => t.UnitID === unitId);
        if (unit?.ManagerUserID) {
            setData('RecipientUserIDs', [unit.ManagerUserID]);
        }
    };

    const handleTypeChange = (typeId: number) => {
        const leavingWorkflow = isWorkflowMessage && typeId !== WORKFLOW_MESSAGE_TYPE;

        setData('MessageTypeID', typeId);
        setData('RecipientUserIDs', []);

        if (typeId === WORKFLOW_MESSAGE_TYPE) {
            setData('DueDate', null);
            return;
        }

        if (leavingWorkflow) {
            // بازگشت از «پیامِ فرایندی» به یک نوعِ پیامِ عادی — هر حالتِ باقی‌ماندهٔ فرایند پاک شود.
            setWfDefinitionId(null);
            setWfTemplates([]);
            setWfTemplateId(null);
            resetWfTemplateState();
            setWfRecipient(null);
            setWfError(null);
            setData('Subject', '');
            setData('MessageText', '');
            form.setFieldsValue({ Subject: '', MessageText: '' });
        }

        const nextType = messageTypes?.find((t: MessageType) => t.MessageTypeID === typeId);
        if (nextType?.MessageTypeName !== 'وظیفه') {
            setData('DueDate', null);
        }
    };

    const beforeUpload = () => false;

    const handleUploadChange = ({ fileList: newList }: any) => {
        setFileList(newList);
        const files = newList
            .map((f: any) => f.originFileObj)
            .filter(Boolean);
        setData('attachments', files);
    };

    const handleSubmit = () => {
        form.validateFields().then(() => {
            const files = fileList
                .map((f: any) => f.originFileObj)
                .filter(Boolean);
            setData('attachments', files);

            post('/messages', {
                preserveScroll: true,
                forceFormData: true,
                onSuccess: () => {
                    setFileList([]);
                    reset();
                    form.resetFields();
                },
            });
        });
    };

    /** جعبهٔ Inline که متنِ قالب را نشان می‌دهد و به‌جایِ هر Token، دقیقاً همان‌جا کنترلِ واردکردنِ مقدار را می‌گذارد. */
    const renderTemplateBox = (
        segments: TemplateSegment[],
        overrides: Record<number, string>,
        setOverrides: React.Dispatch<React.SetStateAction<Record<number, string>>>,
        minHeight: number
    ) => (
        <div
            style={{
                display: 'flex',
                flexWrap: 'wrap',
                alignItems: 'center',
                gap: 6,
                padding: '10px 12px',
                border: '1px solid #d9d9d9',
                borderRadius: 8,
                minHeight,
                background: '#fff',
                lineHeight: 2.4,
            }}
        >
            {segments.map((seg, idx) => {
                if (seg.type === 'text') {
                    const value = overrides[idx] ?? seg.value;
                    // فقط تکه‌هایِ متنِ اصلیِ خالی (بینِ دو Tokenِ پشتِ‌هم) رندر نمی‌شوند؛ اگر
                    // کاربر خودش محتوایِ یک تکه را کامل پاک کند، فیلد باید همچنان قابلِ‌بازگشت بماند.
                    if (value === '' && overrides[idx] === undefined) return null;
                    return (
                        <input
                            key={idx}
                            value={value}
                            onChange={(e: React.ChangeEvent<HTMLInputElement>) =>
                                setOverrides((s) => ({ ...s, [idx]: e.target.value }))
                            }
                            style={{
                                border: 'none',
                                outline: 'none',
                                background: 'transparent',
                                font: 'inherit',
                                color: 'inherit',
                                width: `${Math.max(value.length, 1) + 1}ch`,
                                minWidth: 8,
                            }}
                        />
                    );
                }

                const param = paramByCode(seg.code);
                if (!param) {
                    return (
                        <Tag key={idx} color="red">{`{{${seg.code}}}`}</Tag>
                    );
                }

                if (param.SourceType !== 'FORM') {
                    return (
                        <Tag key={idx} color="default" style={{ borderRadius: 6 }}>
                            {wfResolvedNonForm[seg.code] ?? param.Caption}
                        </Tag>
                    );
                }

                if (param.DataType === 'INTEGER' || param.DataType === 'DECIMAL') {
                    return (
                        <InputNumber
                            key={idx}
                            size="small"
                            placeholder={param.Caption}
                            style={{ width: 130 }}
                            value={wfFormValues[param.SourceKey] ? Number(wfFormValues[param.SourceKey]) : undefined}
                            onChange={(v) => setWfFormValues((s) => ({ ...s, [param.SourceKey]: v == null ? '' : String(v) }))}
                            formatter={(v) => formatNumberInput(v)}
                            parser={(v) => Number(parseNumberInput(v))}
                        />
                    );
                }

                if (param.DataType === 'DATE') {
                    return (
                        <span key={idx} style={{ display: 'inline-block', width: 150, verticalAlign: 'middle' }}>
                            <PersianDateInput
                                size="small"
                                value={wfFormValues[param.SourceKey] || null}
                                onChange={(v) => setWfFormValues((s) => ({ ...s, [param.SourceKey]: v || '' }))}
                            />
                        </span>
                    );
                }

                if (param.DataType === 'TIME') {
                    return (
                        <span key={idx} style={{ display: 'inline-block', width: 110, verticalAlign: 'middle' }}>
                            <TimePicker
                                size="small"
                                format="HH:mm"
                                style={{ width: '100%' }}
                                value={wfFormValues[param.SourceKey] ? dayjs(wfFormValues[param.SourceKey], 'HH:mm') : null}
                                onChange={(v) => setWfFormValues((s) => ({ ...s, [param.SourceKey]: v ? v.format('HH:mm') : '' }))}
                            />
                        </span>
                    );
                }

                return (
                    <Input
                        key={idx}
                        size="small"
                        placeholder={param.Caption}
                        style={{ width: 140 }}
                        value={wfFormValues[param.SourceKey] || ''}
                        onChange={(e) => setWfFormValues((s) => ({ ...s, [param.SourceKey]: e.target.value }))}
                    />
                );
            })}
        </div>
    );

    return (
        <MainLayout>
            <PageHeader
                icon={<MessageOutlined />}
                title="ارسال پیام جدید"
                subtitle="ارسال پیام به همکاران و مدیران"
                backHref="/messages"
                backLabel="بازگشت به کارتابل"
            />

            <Card style={STYLES.card}>
                <Form form={form} layout="vertical" requiredMark>
                    <Row gutter={16}>
                        <Col xs={24} md={8}>
                            <Form.Item
                                label="نوع پیام"
                                name="MessageTypeID"
                                rules={[{ required: true, message: 'انتخاب نوع پیام الزامی است' }]}
                                validateStatus={errors.MessageTypeID ? 'error' : ''}
                                help={errors.MessageTypeID}
                            >
                                <Select
                                    size="large"
                                    placeholder="انتخاب نوع پیام"
                                    value={data.MessageTypeID ?? undefined}
                                    onChange={handleTypeChange}
                                    options={[
                                        ...(messageTypes || []).map((t: MessageType) => ({
                                            value: t.MessageTypeID,
                                            label: t.MessageTypeName,
                                        })),
                                        { value: WORKFLOW_MESSAGE_TYPE, label: 'پیامِ فرایندی' },
                                    ]}
                                />
                            </Form.Item>
                        </Col>

                        {/* ---------- اولویت پیام ---------- */}
                        <Col xs={24} md={8}>
                            <Form.Item
                                label={
                                    <Space size={6}>
                                        <ThunderboltOutlined style={{ color: THEME.primary }} />
                                        <span>اولویت پیام</span>
                                    </Space>
                                }
                                name="msgPriorityID"
                                rules={[{ required: true, message: 'انتخاب اولویت الزامی است' }]}
                                validateStatus={errors.msgPriorityID ? 'error' : ''}
                                help={errors.msgPriorityID}
                                extra={
                                    selectedPriority?.Description ? (
                                        <Text type="secondary" style={{ fontSize: 11 }}>
                                            {selectedPriority.Description}
                                        </Text>
                                    ) : null
                                }
                            >
                                <Select
                                    size="large"
                                    placeholder="انتخاب اولویت"
                                    value={data.msgPriorityID ?? undefined}
                                    onChange={(value: number) => setData('msgPriorityID', value)}
                                    options={priorityOptions}
                                    optionFilterProp="label"
                                    showSearch
                                    optionRender={(option) => {
                                        const palette = getPriorityPalette(
                                            (option.data as any).__sortOrder,
                                            maxSortOrder
                                        );
                                        return (
                                            <Space size={8}>
                                                <span
                                                    style={{
                                                        width: 10,
                                                        height: 10,
                                                        borderRadius: '50%',
                                                        background: palette.gradient,
                                                        display: 'inline-block',
                                                    }}
                                                />
                                                <span style={{ color: palette.color, fontWeight: 600 }}>
                                                    {option.label}
                                                </span>
                                            </Space>
                                        );
                                    }}
                                />
                            </Form.Item>
                        </Col>

                        {/* ---------- فرایند + قالبِ نامه — فقط در حالتِ «پیامِ فرایندی»، به‌عنوانِ فیلدهایِ عادیِ همین فرم ---------- */}
                        {isWorkflowMessage ? (
                            <Col xs={24} md={8}>
                                <Form.Item
                                    label={
                                        <Space size={6}>
                                            <ApartmentOutlined style={{ color: THEME.primary }} />
                                            <span>فرایند</span>
                                        </Space>
                                    }
                                    required
                                >
                                    <Select
                                        size="large"
                                        allowClear
                                        placeholder="انتخابِ فرایند..."
                                        value={wfDefinitionId ?? undefined}
                                        onChange={(v) => (v ? handlePickWfDefinition(v) : handleClearWfDefinition())}
                                        options={wfDefinitions.map((d) => ({
                                            value: d.DefinitionID,
                                            label: `${d.Name} (${d.Code})`,
                                        }))}
                                    />
                                </Form.Item>
                            </Col>
                        ) : null}

                        {isWorkflowMessage ? (
                            <Col xs={24} md={8}>
                                <Form.Item
                                    label={
                                        <Space size={6}>
                                            <FileTextOutlined style={{ color: THEME.primary }} />
                                            <span>قالبِ نامه</span>
                                        </Space>
                                    }
                                    required
                                >
                                    {wfTemplatesLoading ? (
                                        <Spin size="small" />
                                    ) : (
                                        <Select
                                            size="large"
                                            allowClear
                                            disabled={!wfDefinitionId}
                                            placeholder={wfDefinitionId ? 'انتخابِ قالب...' : 'ابتدا فرایند را انتخاب کنید'}
                                            value={wfTemplateId ?? undefined}
                                            onChange={(v) => handlePickWfTemplate(v ?? null)}
                                            options={wfTemplates.map((t) => ({
                                                value: t.LetterTemplateID,
                                                label: `${t.Name} (${t.Code})`,
                                            }))}
                                        />
                                    )}
                                </Form.Item>
                            </Col>
                        ) : null}

                        {isWorkflowMessage && wfError ? (
                            <Col span={24}>
                                <Alert
                                    type="error"
                                    showIcon
                                    message={wfError}
                                    closable
                                    onClose={() => setWfError(null)}
                                    style={{ marginBottom: 8, borderRadius: 8 }}
                                />
                            </Col>
                        ) : null}

                        {isWorkflowMessage ? null : isTask ? (
                            <Col xs={24} md={8}>
                                <Form.Item
                                    label="واحد (مدیر آن به‌صورت خودکار انتخاب می‌شود)"
                                    name="TaskUnitID"
                                    rules={[{ required: true, message: 'انتخاب واحد الزامی است' }]}
                                >
                                    <Select
                                        size="large"
                                        placeholder="جستجو و انتخاب واحد..."
                                        options={unitOptions}
                                        onChange={handleUnitChange}
                                        showSearch
                                        optionFilterProp="label"
                                    />
                                </Form.Item>
                                {data.RecipientUserIDs.length > 0 ? (
                                    <Alert
                                        type="info"
                                        showIcon
                                        message="مدیر واحد به‌عنوان گیرنده انتخاب شد"
                                        style={{ marginBottom: 16, borderRadius: 8 }}
                                    />
                                ) : null}
                            </Col>
                        ) : (
                            <Col xs={24} md={8}>
                                <Form.Item label="گیرنده‌ها" required>
                                    <Radio.Group
                                        value={data.RecipientType}
                                        onChange={(e) => setData('RecipientType', e.target.value)}
                                    >
                                        <Space direction="vertical">
                                            <Radio value={2}>
                                                <TeamOutlined /> همه کاربران
                                            </Radio>
                                            <Radio value={3}>
                                                <CrownOutlined /> همه مدیران
                                            </Radio>
                                            <Radio value={1}>
                                                <UserOutlined /> کاربر یا کاربران مشخص
                                            </Radio>
                                        </Space>
                                    </Radio.Group>
                                </Form.Item>
                            </Col>
                        )}

                        {isTask && !isWorkflowMessage ? (
                            <Col xs={24} md={8}>
                                <Form.Item label="مهلت اجرا (اختیاری)">
                                    <PersianDateInput
                                        value={data.DueDate}
                                        onChange={(v) => setData('DueDate', v)}
                                        placeholder="بدون مهلت"
                                    />
                                </Form.Item>
                            </Col>
                        ) : null}

                        {!isTask && !isWorkflowMessage && data.RecipientType === 1 ? (
                            <Col span={24}>
                                <Form.Item
                                    label="انتخاب کاربران"
                                    name="RecipientUsers"
                                    rules={[{ required: true, message: 'حداقل یک گیرنده انتخاب کنید' }]}
                                >
                                    <Select
                                        mode="multiple"
                                        size="large"
                                        placeholder="جستجو و انتخاب کاربران..."
                                        value={data.RecipientUserIDs}
                                        onChange={(values: number[]) => setData('RecipientUserIDs', values)}
                                        options={userOptions}
                                        optionFilterProp="label"
                                        showSearch
                                        style={{ width: '100%' }}
                                    />
                                </Form.Item>
                            </Col>
                        ) : null}

                        {isWorkflowMessage && wfRenderLoading ? (
                            <Col span={24} style={{ marginBottom: 4 }}>
                                <Space size={6}>
                                    <Spin size="small" />
                                    <Text type="secondary" style={{ fontSize: 12 }}>در حالِ تکمیلِ خودکارِ بخشی از متنِ نامه...</Text>
                                </Space>
                            </Col>
                        ) : null}

                        {isWorkflowMessage ? (
                            <>
                                <Col span={24}>
                                    <div style={{ marginBottom: 4 }}>
                                        <Text strong>موضوع</Text> <Text type="danger">*</Text>
                                    </div>
                                    {wfSelectedTemplate ? (
                                        renderTemplateBox(wfSubjectSegments, wfSubjectTextOverrides, setWfSubjectTextOverrides, 46)
                                    ) : (
                                        <Alert
                                            type="info"
                                            showIcon
                                            message="ابتدا فرایند و قالبِ نامه را انتخاب کنید."
                                            style={{ borderRadius: 8 }}
                                        />
                                    )}
                                    {wfSelectedTemplate ? (
                                        <Text type="secondary" style={{ fontSize: 11, display: 'block', marginTop: 4 }}>
                                            جاهایِ خالی، پارامترهایِ قالب‌اند — مقدارشان را همان‌جا وارد کنید؛ بقیهٔ متن هم قابلِ‌ویرایشِ دستی است.
                                        </Text>
                                    ) : null}
                                </Col>

                                <Col span={24}>
                                    <div style={{ marginBottom: 4 }}>
                                        <Text strong>متن پیام</Text>
                                    </div>
                                    {wfSelectedTemplate ? (
                                        renderTemplateBox(wfBodySegments, wfBodyTextOverrides, setWfBodyTextOverrides, 120)
                                    ) : (
                                        <Alert
                                            type="info"
                                            showIcon
                                            message="ابتدا فرایند و قالبِ نامه را انتخاب کنید."
                                            style={{ borderRadius: 8 }}
                                        />
                                    )}
                                </Col>
                            </>
                        ) : (
                            <>
                                <Col span={24}>
                                    <Form.Item
                                        label="موضوع"
                                        name="Subject"
                                        rules={[
                                            { required: true, message: 'موضوع الزامی است' },
                                            { max: 500, message: 'حداکثر 500 کاراکتر' },
                                        ]}
                                        validateStatus={errors.Subject ? 'error' : ''}
                                        help={errors.Subject}
                                    >
                                        <Input
                                            size="large"
                                            placeholder="موضوع پیام..."
                                            value={data.Subject}
                                            onChange={(e) => setData('Subject', e.target.value)}
                                        />
                                    </Form.Item>
                                </Col>

                                <Col span={24}>
                                    <Form.Item label="متن پیام" name="MessageText">
                                        <Input.TextArea
                                            rows={6}
                                            placeholder="متن پیام..."
                                            value={data.MessageText}
                                            onChange={(e) => setData('MessageText', e.target.value)}
                                            showCount
                                            maxLength={5000}
                                        />
                                    </Form.Item>
                                </Col>
                            </>
                        )}

                        {/* ---------- درخواست‌دهنده و گیرنده (Read-Only، از Workflow Start Context) ---------- */}
                        {isWorkflowMessage ? (
                            <Col span={24}>
                                <Form.Item
                                    label={
                                        <Space size={6}>
                                            <LockOutlined style={{ color: THEME.primary }} />
                                            <span>درخواست‌دهنده</span>
                                        </Space>
                                    }
                                >
                                    {wfRecipientLoading ? (
                                        <Spin size="small" />
                                    ) : wfRecipient?.starter ? (
                                        <Space wrap>
                                            <Tag icon={<UserOutlined />} color="geekblue" style={{ borderRadius: 6 }}>
                                                {wfRecipient.starter.fullName || `کاربرِ #${wfRecipient.starter.userId}`}
                                            </Tag>
                                            {wfRecipient.starter.positionName ? <Text type="secondary">{wfRecipient.starter.positionName}</Text> : null}
                                            {wfRecipient.starter.unitName ? <Text type="secondary">— {wfRecipient.starter.unitName}</Text> : null}
                                        </Space>
                                    ) : (
                                        <Text type="secondary">{wfDefinitionId ? 'در حالِ محاسبه...' : 'ابتدا فرایند را انتخاب کنید.'}</Text>
                                    )}
                                </Form.Item>
                                <Form.Item
                                    label={
                                        <Space size={6}>
                                            <LockOutlined style={{ color: THEME.primary }} />
                                            <span>گیرنده</span>
                                        </Space>
                                    }
                                >
                                    {wfRecipientLoading ? (
                                        <Spin size="small" />
                                    ) : wfRecipient?.startable && wfRecipient.users.length > 0 ? (
                                        <Space direction="vertical" size={4}>
                                            <Space wrap>
                                                {wfRecipient.users.map((u) => (
                                                    <Tag key={u.userId} icon={<UserOutlined />} color="blue" style={{ borderRadius: 6 }}>
                                                        {u.fullName || `کاربرِ #${u.userId}`}
                                                    </Tag>
                                                ))}
                                            </Space>
                                            {wfRecipient.users.length > 1 && wfRecipient.step ? (
                                                <Text type="secondary" style={{ fontSize: 12 }}>
                                                    {wfRecipient.step.assignPolicy === 'N_OF_M'
                                                        ? `حداقل ${wfRecipient.step.requiredApprovals != null ? columnHelpers.formatNumber(wfRecipient.step.requiredApprovals) : ''} نفر از گیرندگان باید تأیید کنند.`
                                                        : ASSIGN_POLICY_HINT[wfRecipient.step.assignPolicy] ?? ''}
                                                </Text>
                                            ) : null}
                                        </Space>
                                    ) : (
                                        <Alert
                                            // Deferred (وابسته به CONDITION) خطا نیست — فقط یعنی گیرنده بعدِ بررسیِ شرط
                                            // در سرور مشخص می‌شود؛ Submit را مسدود نمی‌کند.
                                            type={!wfRecipient ? 'info' : wfRecipient.deferred ? 'info' : wfRecipient.startable ? 'info' : 'error'}
                                            showIcon
                                            style={{ borderRadius: 8 }}
                                            message={
                                                wfDefinitionId
                                                    ? wfRecipient
                                                        ? wfRecipient.message ||
                                                          (wfRecipient.reason ? RECIPIENT_REASON_LABEL[wfRecipient.reason] : null) ||
                                                          'گیرندهٔ این فرایند قابلِ‌تعیین نیست؛ امکانِ ارسال وجود ندارد.'
                                                        : 'در حالِ محاسبهٔ گیرنده...'
                                                    : 'ابتدا فرایند را انتخاب کنید.'
                                            }
                                        />
                                    )}
                                </Form.Item>
                            </Col>
                        ) : null}

                        <Col span={24}>
                            <Form.Item label="رونوشت (اختیاری)" name="CopyUsers">
                                <Select
                                    mode="multiple"
                                    size="large"
                                    placeholder="انتخاب کاربران رونوشت..."
                                    value={data.CopyUserIDs}
                                    onChange={(values: number[]) => setData('CopyUserIDs', values)}
                                    options={userOptions}
                                    optionFilterProp="label"
                                    showSearch
                                    style={{ width: '100%' }}
                                    prefix={<CopyOutlined />}
                                />
                            </Form.Item>
                        </Col>

                        <Col span={24}>
                            <Form.Item label="توضیح رونوشت (اختیاری)" name="CopyDescription">
                                <Input.TextArea
                                    rows={2}
                                    placeholder="توضیح رونوشت..."
                                    value={data.CopyDescription}
                                    onChange={(e) => setData('CopyDescription', e.target.value)}
                                    maxLength={1000}
                                    showCount
                                />
                            </Form.Item>
                        </Col>

                        {isWorkflowMessage ? null : (
                            <Col span={24}>
                                <Form.Item label="ضمیمه (اختیاری)">
                                    <Upload
                                        beforeUpload={beforeUpload}
                                        fileList={fileList}
                                        onChange={handleUploadChange}
                                        multiple
                                    >
                                        <Button icon={<UploadOutlined />}>انتخاب فایل</Button>
                                    </Upload>
                                    {fileList.length > 0 ? (
                                        <Text type="secondary" style={{ fontSize: 12 }}>
                                            <PaperClipOutlined /> {fileList.length} فایل انتخاب شده
                                        </Text>
                                    ) : null}
                                </Form.Item>
                            </Col>
                        )}
                    </Row>

                    <Row justify="end" style={{ marginTop: 8 }}>
                        <Button
                            type="primary"
                            icon={<SendOutlined />}
                            size="large"
                            loading={isWorkflowMessage ? wfSubmitting : processing}
                            disabled={
                                isWorkflowMessage &&
                                (!wfTemplateId ||
                                    !wfRecipient?.startable ||
                                    // بدونِ CONDITION (deferred=false) باید Previewِ گیرنده حتماً پر باشد؛
                                    // با CONDITION (deferred=true) گیرنده عمداً در Preview خالی است و همین
                                    // درست است — سرور خودش در لحظهٔ Submit تعیینش می‌کند.
                                    (!wfRecipient.deferred && wfRecipient.users.length === 0) ||
                                    !data.Subject ||
                                    wfFormFields.some((f) => !wfFormValues[f.SourceKey]) ||
                                    wfHasUnresolvedNonForm)
                            }
                            onClick={isWorkflowMessage ? handleWorkflowSubmit : handleSubmit}
                            style={STYLES.primaryButton}
                        >
                            ارسال پیام
                        </Button>
                    </Row>
                </Form>
            </Card>

            <NotificationModal
                open={notification.open}
                type={notification.type}
                message={notification.message}
                onClose={closeNotification}
            />
        </MainLayout>
    );
}
