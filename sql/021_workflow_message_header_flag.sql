/* ==========================================================================
   پچ 021 | Workflow — پرچمِ IsWfTask روی sp_GetMessageHeader

   مسئله (Gap 1 — Frontend Phase 1 / WorkflowTaskCard):
     Messages/Show.tsx فقط `isTask` (= نوعِ پیام «وظیفه») را دارد و نمی‌تواند
     بینِ وظیفهٔ عادی/پروژه و وظیفهٔ Workflow تفکیک کند. نتیجه: برایِ هر تسکِ
     غیرِ Workflow هم یک GET اضافی به /workflow/messages/{id} زده می‌شود
     (که ۴۰۴ می‌گیرد) و کارتِ قدیمیِ «تغییرِ وضعیت» برایِ تسکِ Workflow هم
     همچنان نمایش داده می‌شود.

   راهکار (همان الگویِ IsWfTask که پچ 017 به sp_GetMessages اضافه کرد،
   این‌بار رویِ sp_GetMessageHeader — تنها SPِ پشتِ صفحهٔ تک‌پیام):
     یک ستونِ افزایشیِ IsWfTask با دقیقاً همان منطق:
       EXISTS (SELECT 1 FROM dbo.WorkflowStepInstances WHERE MessageID = M.MessageID)

   دامنه:
     • فقط CREATE OR ALTER — هیچ ستون/جدول/Constraintِ جدید.
     • خروجیِ فعلیِ SP برایِ همهٔ ۲۲ پیامِ موجود بدونِ تغییر می‌ماند (فقط یک
       ستونِ جدید اضافه می‌شود؛ هیچ‌کدام IsWfTask=1 نیستند چون هیچ Instanceِ
       Workflowای هنوز اجرا نشده — پچ 014..020 صفر ردیفِ Runtime دارند).
     • Runtime/Message-Architecture/WorkflowTaskAssignees/Forward/Delegation/
       SPهایِ آن‌ها دست‌نخورده.
   ========================================================================== */

SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;
GO

CREATE OR ALTER PROCEDURE [dbo].[sp_GetMessageHeader]
    @MessageID INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        M.MessageID,
        M.Subject,
        M.MessageText,
        M.DueDate,
        M.MessageTypeID,
        MT.MessageTypeName,
        M.SenderUserID,
        LTRIM(CONCAT(SU.FirstName, N' ', SU.LastName)) AS SenderName,
        M.CreateDate,
        M.CreateUser,
        CASE WHEN EXISTS (
            SELECT 1 FROM dbo.WorkflowStepInstances WSI WHERE WSI.MessageID = M.MessageID
        ) THEN 1 ELSE 0 END AS IsWfTask
    FROM dbo.Messages M
    JOIN dbo.MessageTypes MT ON MT.MessageTypeID = M.MessageTypeID
    LEFT JOIN dbo.Users SU   ON SU.UserID = M.SenderUserID
    WHERE M.MessageID = @MessageID;
END
GO

/* ==================== پایان پچ 021 ==================== */
