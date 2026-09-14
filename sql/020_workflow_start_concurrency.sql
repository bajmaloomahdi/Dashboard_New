/* ==========================================================================
   پچ 020 | Workflow — سخت‌سازیِ هم‌زمانیِ Start

   مسئله:
     مسیرِ Start از الگوی check-then-act استفاده می‌کند
     (WorkflowEngine::start → sp_Wf_HasActiveInstance بیرون از تراکنش، سپس
      sp_Wf_StartInstance با INSERT بی‌قیدوشرط). دو درخواستِ هم‌زمان برای همان
      (DefinitionID, EntityType, EntityID) می‌توانند هر دو از pre-check رد شوند و
      دو ردیفِ Status='RUNNING' بسازند.

   راهکار (این پچ):
     یک UNIQUE FILTERED INDEX که ثابتِ «حداکثر یک RUNNING به‌ازای هر موجودیت» را
     در سطحِ دیتابیس تضمین می‌کند. دومین INSERTِ رقیب با خطای 2601 رد می‌شود؛
     لایهٔ PHP آن را به WorkflowStateException (HTTP 409) نگاشت می‌کند — همان
     معنایِ pre-checkِ فعلی.

   دامنه:
     • فقط CREATE INDEX. هیچ ستون/FK/CHECK/constraint دیگری تغییر نمی‌کند.
     • sp_Wf_HasActiveInstance و sp_Wf_StartInstance تغییر نمی‌کنند.
     • predicate = Status = N'RUNNING'  → رفتار‌حفظ (نمونهٔ SUSPENDED مانعِ Start جدید
       نیست، دقیقاً مثلِ pre-checkِ فعلی).
     • idempotent: اگر ایندکس با همین نام وجود دارد، دوباره ساخته نمی‌شود.

   سازگاری:
     روی Dashboard_DB (compat 160) آزمایش شد: CREATE و DMLِ بعدی حتی با
     ARITHABORT=OFF کانکشنِ Laravel بدونِ خطا کار می‌کنند. SET optionهای موردنیازِ
     filtered index در همین بچ صریحاً تنظیم می‌شوند تا CREATE قطعی باشد.
   ========================================================================== */

SET ANSI_NULLS ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET ARITHABORT ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET QUOTED_IDENTIFIER ON;
SET NUMERIC_ROUNDABORT OFF;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'UX_WorkflowInstances_Running_Entity'
      AND object_id = OBJECT_ID(N'dbo.WorkflowInstances')
)
BEGIN
    CREATE UNIQUE INDEX UX_WorkflowInstances_Running_Entity
        ON dbo.WorkflowInstances (DefinitionID, EntityType, EntityID)
        WHERE Status = N'RUNNING';
END
GO

/* ==================== پایان پچ 020 ==================== */
