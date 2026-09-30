/**
 * فهرستِ EventCodeهایِ قابلِ‌نمایش در Timelineِ اصلیِ Workflow History — Generic، بدونِ
 * هیچ وابستگی به Definition/Code/Workflowِ خاص. History کاملِ خام (همهٔ EventCodeها) همچنان
 * از API/DB می‌آید و تغییری نمی‌کند؛ این فقط یک فیلترِ نمایشیِ سمتِ Frontend است.
 *
 * هر دو مصرف‌کننده (کارتِ «تاریخچهٔ فرایند» در Process/Instances/Show.tsx و بخشِ Historyِ
 * WorkflowTaskCard.tsx) باید از همین یک منبع استفاده کنند تا رفتار ناهماهنگ نشود.
 */
export const WORKFLOW_HISTORY_VISIBLE_EVENT_CODES: ReadonlySet<string> = new Set([
    'INSTANCE_STARTED',
    'STEP_ENTERED',
    'TASK_DECISION',
    'TASK_FORWARDED',
    'TASK_DELEGATED',
    'TASK_DELEGATION_REVOKED',
    'INSTANCE_COMPLETED',
    'INSTANCE_CANCELLED',
    'INSTANCE_SUSPENDED',
    'INSTANCE_RESUMED',
    'INSTANCE_FAILED',
]);

/** فیلترِ Timelineِ اصلی — ترتیبِ زمانیِ ورودی حفظ می‌شود؛ هیچ رویدادی ساخته/حذفِ فیزیکی نمی‌شود. */
export function filterWorkflowHistoryForTimeline<T extends { EventCode: string }>(history: T[]): T[] {
    return history.filter((h) => WORKFLOW_HISTORY_VISIBLE_EVENT_CODES.has(h.EventCode));
}
