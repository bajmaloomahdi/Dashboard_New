/**
 * منطقِ خالصِ Version Editor — تبدیلِ Contractِ Backend (GET .../graph: PascalCase،
 * ارجاع با StepID/ActionID عددی) به Stateِ داخلیِ Code-based، تبدیلِ برعکس برایِ
 * Save (PUT .../graph)، Cascadeهایِ Rename/Delete، و Linter.
 *
 * هیچ فراخوانیِ شبکه‌ای اینجا نیست — فقط تبدیلِ داده و قواعدِ محلی.
 */

/**
 * CONDITION از قبل در CHECK Constraintِ DB مجاز بود (بررسیِ زنده تأیید شد)، فقط تا امروز
 * در UI/Runtime پیاده نشده بود. با Visual Process Designer به‌عنوانِ نوعِ چهارمِ قابلِ‌انتخاب
 * اضافه می‌شود — اجرایِ Runtimeِ آن (ارزیابیِ شرط) طبقِ دستورِ صریح در همین فاز پیاده نمی‌شود.
 */
export type StepType = 'START' | 'USER_TASK' | 'APPROVAL' | 'END' | 'CONDITION';
export type ActionKind = 'APPROVE' | 'REJECT' | 'RETURN' | 'COMPLETE' | 'CUSTOM';
export type AssigneeType =
    | 'USER' | 'ROLE' | 'POSITION' | 'UNIT' | 'UNIT_MANAGER' | 'DIRECT_MANAGER' | 'INITIATOR' | 'ENTITY_OWNER';
export type AssignPolicy = 'ANY' | 'ALL' | 'N_OF_M';

export const STEP_TYPES: { value: StepType; label: string }[] = [
    { value: 'START', label: 'شروع (START)' },
    { value: 'USER_TASK', label: 'وظیفهٔ کاربر (USER_TASK)' },
    { value: 'APPROVAL', label: 'تأیید (APPROVAL)' },
    { value: 'END', label: 'پایان (END)' },
];

export const ACTION_KINDS: { value: ActionKind; label: string }[] = [
    { value: 'APPROVE', label: 'تأیید (APPROVE)' },
    { value: 'REJECT', label: 'رد (REJECT)' },
    { value: 'RETURN', label: 'عودت (RETURN)' },
    { value: 'COMPLETE', label: 'تکمیل (COMPLETE)' },
    { value: 'CUSTOM', label: 'سفارشی (CUSTOM)' },
];

export const ASSIGN_POLICIES: { value: AssignPolicy; label: string }[] = [
    { value: 'ANY', label: 'هر یک (ANY)' },
    { value: 'ALL', label: 'همه (ALL)' },
    { value: 'N_OF_M', label: 'حداقلِ N نفر (N_OF_M)' },
];

export const ASSIGNEE_TYPES: { value: AssigneeType; label: string; needsTarget: boolean }[] = [
    { value: 'USER', label: 'کاربرِ مشخص', needsTarget: true },
    { value: 'ROLE', label: 'نقش', needsTarget: true },
    { value: 'POSITION', label: 'سمت', needsTarget: true },
    { value: 'UNIT', label: 'واحدِ سازمانی', needsTarget: true },
    { value: 'UNIT_MANAGER', label: 'مدیرِ واحد', needsTarget: true },
    { value: 'DIRECT_MANAGER', label: 'مدیرِ مستقیمِ آغازگر', needsTarget: false },
    { value: 'INITIATOR', label: 'آغازگرِ فرایند', needsTarget: false },
    { value: 'ENTITY_OWNER', label: 'مالکِ موجودیت', needsTarget: false },
];

export interface StepDraft {
    code: string;
    name: string;
    stepType: StepType;
    assignPolicy: AssignPolicy;
    requiredApprovals: number | null;
    allowForward: boolean;
    forwardMax: number | null;
    allowDelegation: boolean;
    sortOrder: number;
    /** موقعیتِ Nodeِ متناظر رویِ Canvasِ Visual Designer (Nullable در DB) */
    positionX: number | null;
    positionY: number | null;
    /** توضیحِ اختیاریِ Step (Nullable در DB) */
    description: string | null;
    /** مهلتِ انجام بر حسبِ ساعت (Nullable — فعلاً فقط Persist می‌شود، در Runtime استفاده نمی‌شود) */
    dueDurationHours: number | null;
}

export interface ActionDraft {
    stepCode: string;
    code: string;
    kind: ActionKind;
    label: string;
    icon: string | null;
    style: string | null;
    requiresComment: boolean;
    requiresConfirm: boolean;
    confirmMessage: string | null;
    permissionCode: string | null;
    sortOrder: number;
}

export interface AssignmentDraft {
    stepCode: string;
    assigneeType: AssigneeType;
    refId: number | null;
    refExpression: null;
    sortOrder: number;
    /** true = جانشین (Backup)، false = انجام‌دهندهٔ اصلی */
    isBackup: boolean;
}

export interface TransitionDraft {
    code: string;
    fromStepCode: string;
    toStepCode: string;
    triggerActionCode: string | null;
    priority: number;
    isDefault: boolean;
    label: string | null;
    /** متنِ شرط (Nullable — فعلاً فقط Persist می‌شود، Runtime آن را Evaluate نمی‌کند) */
    conditionExpression: string | null;
}

export interface GraphDraft {
    steps: StepDraft[];
    actions: ActionDraft[];
    assignments: AssignmentDraft[];
    transitions: TransitionDraft[];
}

function toBool(v: unknown): boolean {
    if (typeof v === 'boolean') return v;
    if (typeof v === 'number') return v === 1;
    if (typeof v === 'string') return v === '1' || v.toLowerCase() === 'true';
    return false;
}

/** GET /workflow/versions/{id}/graph → GraphDraft (تنها نقطهٔ تبدیلِ ID→Code) */
export function graphFromServer(raw: { steps: any[]; actions: any[]; assignments: any[]; transitions: any[] }): GraphDraft {
    const stepCodeById = new Map<number, string>();
    (raw.steps || []).forEach((s) => stepCodeById.set(Number(s.StepID), s.Code));

    const actionCodeById = new Map<number, string>();
    (raw.actions || []).forEach((a) => actionCodeById.set(Number(a.ActionID), a.Code));

    const steps: StepDraft[] = (raw.steps || []).map((s) => ({
        code: s.Code,
        name: s.Name,
        stepType: s.StepType,
        assignPolicy: (s.AssignPolicy ?? 'ANY') as AssignPolicy,
        requiredApprovals: s.RequiredApprovals ?? null,
        allowForward: toBool(s.AllowForward),
        forwardMax: s.ForwardMax ?? null,
        allowDelegation: toBool(s.AllowDelegation),
        sortOrder: s.SortOrder ?? 0,
        positionX: s.PositionX ?? null,
        positionY: s.PositionY ?? null,
        description: s.Description ?? null,
        dueDurationHours: s.DueDurationHours ?? null,
    }));

    const actions: ActionDraft[] = (raw.actions || []).map((a) => ({
        stepCode: stepCodeById.get(Number(a.StepID)) ?? '',
        code: a.Code,
        kind: a.Kind,
        label: a.Label,
        icon: a.Icon ?? null,
        style: a.Style ?? null,
        requiresComment: toBool(a.RequiresComment),
        requiresConfirm: toBool(a.RequiresConfirm),
        confirmMessage: a.ConfirmMessage ?? null,
        permissionCode: a.PermissionCode ?? null,
        sortOrder: a.SortOrder ?? 0,
    }));

    const assignments: AssignmentDraft[] = (raw.assignments || []).map((a) => ({
        stepCode: stepCodeById.get(Number(a.StepID)) ?? '',
        assigneeType: a.AssigneeType,
        refId: a.RefID ?? null,
        refExpression: null,
        sortOrder: a.SortOrder ?? 0,
        isBackup: toBool(a.IsBackup),
    }));

    const transitions: TransitionDraft[] = (raw.transitions || []).map((t) => ({
        code: t.Code,
        fromStepCode: stepCodeById.get(Number(t.FromStepID)) ?? '',
        toStepCode: stepCodeById.get(Number(t.ToStepID)) ?? '',
        triggerActionCode: t.TriggerActionID != null ? actionCodeById.get(Number(t.TriggerActionID)) ?? null : null,
        priority: t.Priority ?? 100,
        isDefault: toBool(t.IsDefault),
        label: t.Label ?? null,
        conditionExpression: t.ConditionExpression ?? null,
    }));

    return { steps, actions, assignments, transitions };
}

/** GraphDraft → بدنهٔ دقیقِ PUT /workflow/versions/{id}/graph (Full Replacement) */
export function graphToPayload(g: GraphDraft) {
    return {
        steps: g.steps.map((s) => ({ ...s })),
        actions: g.actions.map((a) => ({ ...a })),
        assignments: g.assignments.map((a) => ({ ...a })),
        transitions: g.transitions.map((t) => ({ ...t })),
    };
}

/** امضایِ پایدار برایِ تشخیصِ Dirty (مقایسهٔ همان شکلی که Save می‌فرستد) */
export function graphSignature(g: GraphDraft): string {
    return JSON.stringify(graphToPayload(g));
}

export function emptyGraph(): GraphDraft {
    return { steps: [], actions: [], assignments: [], transitions: [] };
}

/* ============================ Cascade عملیات‌ها ============================ */

/** Renameِ کدِ یک Step — همهٔ Referenceهایِ وابسته را Atomic اصلاح می‌کند (تصمیمِ ۹) */
export function renameStepCode(g: GraphDraft, oldCode: string, newCode: string): GraphDraft {
    if (oldCode === newCode) return g;
    return {
        steps: g.steps.map((s) => (s.code === oldCode ? { ...s, code: newCode } : s)),
        actions: g.actions.map((a) => (a.stepCode === oldCode ? { ...a, stepCode: newCode } : a)),
        assignments: g.assignments.map((a) => (a.stepCode === oldCode ? { ...a, stepCode: newCode } : a)),
        transitions: g.transitions.map((t) => ({
            ...t,
            fromStepCode: t.fromStepCode === oldCode ? newCode : t.fromStepCode,
            toStepCode: t.toStepCode === oldCode ? newCode : t.toStepCode,
        })),
    };
}

/** حذفِ یک Step — Action/Assignmentِ خودش و هر Transitionِ متصل به آن هم حذف می‌شود */
export function removeStep(g: GraphDraft, code: string): GraphDraft {
    return {
        steps: g.steps.filter((s) => s.code !== code),
        actions: g.actions.filter((a) => a.stepCode !== code),
        assignments: g.assignments.filter((a) => a.stepCode !== code),
        transitions: g.transitions.filter((t) => t.fromStepCode !== code && t.toStepCode !== code),
    };
}

/** حذفِ یک Action — Transitionِ Triggerشده با آن حذف نمی‌شود، فقط triggerActionCode آن null می‌شود (تصمیمِ درخواست) */
export function removeAction(g: GraphDraft, stepCode: string, code: string): GraphDraft {
    return {
        ...g,
        actions: g.actions.filter((a) => !(a.stepCode === stepCode && a.code === code)),
        transitions: g.transitions.map((t) =>
            t.fromStepCode === stepCode && t.triggerActionCode === code ? { ...t, triggerActionCode: null } : t
        ),
    };
}

/* ============================ Linter ============================ */

export interface LintIssue {
    message: string;
}

export interface LintResult {
    blocking: LintIssue[];
    advisory: LintIssue[];
}

function duplicates(values: string[]): string[] {
    const seen = new Set<string>();
    const dup = new Set<string>();
    values.forEach((v) => {
        if (!v) return;
        if (seen.has(v)) dup.add(v);
        else seen.add(v);
    });
    return Array.from(dup);
}

/**
 * Linterِ سمتِ Frontend — Blocking فقط برایِ ریسکِ گم‌شدنِ بی‌صدایِ داده
 * (Codeِ تکراری / Referenceِ یتیم)؛ Advisory دقیقاً هم‌ارزِ قواعدِ
 * WorkflowDefinitionService::validate() در Backend (تکرار، نه اختراع).
 */
export function lintGraph(g: GraphDraft): LintResult {
    const blocking: LintIssue[] = [];
    const advisory: LintIssue[] = [];

    const stepCodeSet = new Set(g.steps.map((s) => s.code));

    duplicates(g.steps.map((s) => s.code)).forEach((c) => blocking.push({ message: `کدِ Stepِ «${c}» تکراری است.` }));
    duplicates(g.transitions.map((t) => t.code)).forEach((c) => blocking.push({ message: `کدِ Transitionِ «${c}» تکراری است.` }));

    const actionKeyCount = new Map<string, number>();
    g.actions.forEach((a) => {
        const key = `${a.stepCode}::${a.code}`;
        actionKeyCount.set(key, (actionKeyCount.get(key) ?? 0) + 1);
    });
    actionKeyCount.forEach((count, key) => {
        if (count > 1) {
            const [stepCode, code] = key.split('::');
            blocking.push({ message: `کدِ Actionِ «${code}» در Stepِ «${stepCode}» تکراری است.` });
        }
    });

    g.actions.forEach((a) => {
        if (!stepCodeSet.has(a.stepCode)) blocking.push({ message: `Actionِ «${a.code}» به Stepِ نامعتبرِ «${a.stepCode}» اشاره می‌کند.` });
    });
    g.assignments.forEach((a) => {
        if (!stepCodeSet.has(a.stepCode)) blocking.push({ message: `تخصیصِ نوعِ «${a.assigneeType}» به Stepِ نامعتبرِ «${a.stepCode}» اشاره می‌کند.` });
    });
    g.transitions.forEach((t) => {
        if (!stepCodeSet.has(t.fromStepCode)) blocking.push({ message: `گذارِ «${t.code}» از Stepِ نامعتبرِ «${t.fromStepCode}» شروع می‌شود.` });
        if (!stepCodeSet.has(t.toStepCode)) blocking.push({ message: `گذارِ «${t.code}» به Stepِ نامعتبرِ «${t.toStepCode}» می‌رود.` });
        if (t.triggerActionCode) {
            const exists = g.actions.some((a) => a.stepCode === t.fromStepCode && a.code === t.triggerActionCode);
            if (!exists) blocking.push({ message: `Trigger Actionِ گذارِ «${t.code}» («${t.triggerActionCode}») در Stepِ مبدأ یافت نشد.` });
        }
    });

    // Advisory — هم‌ارزِ WorkflowDefinitionService::validate()
    const starts = g.steps.filter((s) => s.stepType === 'START');
    const ends = g.steps.filter((s) => s.stepType === 'END');
    if (g.steps.length === 0) advisory.push({ message: 'نسخه هیچ مرحله‌ای ندارد.' });
    if (starts.length === 0) advisory.push({ message: 'مرحلهٔ شروع (START) وجود ندارد.' });
    if (starts.length > 1) advisory.push({ message: 'بیش از یک مرحلهٔ شروع (START) تعریف شده است.' });
    if (ends.length === 0) advisory.push({ message: 'حداقل یک مرحلهٔ پایان (END) لازم است.' });

    const outByStep = new Map<string, number>();
    const inByStep = new Map<string, number>();
    g.transitions.forEach((t) => {
        outByStep.set(t.fromStepCode, (outByStep.get(t.fromStepCode) ?? 0) + 1);
        inByStep.set(t.toStepCode, (inByStep.get(t.toStepCode) ?? 0) + 1);
    });

    g.steps.forEach((s) => {
        const label = s.name || s.code;
        if (s.stepType !== 'END' && !outByStep.get(s.code)) advisory.push({ message: `مرحلهٔ «${label}» هیچ گذارِ خروجی ندارد.` });
        if (s.stepType !== 'START' && !inByStep.get(s.code)) advisory.push({ message: `مرحلهٔ «${label}» از هیچ مسیری قابلِ دسترسی نیست.` });
        if (s.stepType === 'START' && inByStep.get(s.code)) advisory.push({ message: 'مرحلهٔ شروع نباید گذارِ ورودی داشته باشد.' });
        if (s.stepType === 'END' && outByStep.get(s.code)) advisory.push({ message: `مرحلهٔ پایانِ «${label}» نباید گذارِ خروجی داشته باشد.` });
        if ((s.stepType === 'USER_TASK' || s.stepType === 'APPROVAL') && !g.assignments.some((a) => a.stepCode === s.code)) {
            advisory.push({ message: `مرحلهٔ «${label}» هیچ انجام‌دهنده‌ای ندارد.` });
        }
        if (s.assignPolicy === 'N_OF_M' && !(s.requiredApprovals && s.requiredApprovals >= 1)) {
            advisory.push({ message: `مرحلهٔ «${label}» با سیاستِ N_OF_M نیازمندِ «تعدادِ تأییدِ لازم» است.` });
        }
    });

    // Action↔Transition (تصمیمِ ۱۵) — Advisory
    g.actions.forEach((a) => {
        const hasTrigger = g.transitions.some((t) => t.fromStepCode === a.stepCode && t.triggerActionCode === a.code);
        if (!hasTrigger) advisory.push({ message: `Actionِ «${a.label || a.code}» در Stepِ «${a.stepCode}» هیچ گذارِ Triggerشده‌ای ندارد.` });
    });

    return { blocking, advisory };
}
