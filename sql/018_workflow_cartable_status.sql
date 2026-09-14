/* ==========================================================================
   پچ 018 | Workflow — تفکیکِ وضعیتِ کارتابل (MessageDetails) از وضعیتِ Workflow

   تصمیمِ نهاییِ کاربر (گزینهٔ الف):
     • برای خارج‌کردنِ «تسکِ مرحله»ی رد/عودت/لغو‌شده از کارتابل، از MessageStatusID = 6
       (N'انجام نخواهد شد') استفاده می‌شود — نه 4 (N'انجام شده').
     • dbo.MessageStatuses هیچ تغییری نمی‌کند (ID=6 همچنان IsActive=0).
     • هیچ SP غیرِ Workflow تغییر نمی‌کند (sp_GetMessages / sp_GetUserMessagePriorityStats /
       sp_CheckMessageCommentPermission دست‌نخورده — هر سه از قبل «انجام نخواهد شد» را
       از کارتابل/شمارش/کامنت خارج می‌کنند).
     • Frontend تغییر نمی‌کند («انجام نخواهد شد» از قبل رنگِ error دارد).

   قانونِ per-assignee (برای همهٔ سیاست‌ها ANY / ALL / N_OF_M):
     MessageStatusID = 4  ⇔  (assignee.Decision = 'APPROVED')  AND  (Disposition ∈ {APPROVED, COMPLETED})
     در غیرِ این‌صورت  →  6

   نکته: assigneeِ اقدام‌کننده (@ActorUserID) در لحظهٔ اجرای این SP هنوز Decision‌اش در
   WorkflowTaskAssignees نوشته نشده (engine اول completeStepTask، بعد recordDecision).
   چون جهتِ اقدامِ او همیشه با Disposition یکی است، وضعیتِ او مستقیماً از Disposition
   استنتاج می‌شود.

   وضعیتِ حقیقیِ REJECTED / RETURNED / CANCELLED فقط در WorkflowInstances /
   WorkflowStepInstances / WorkflowTaskAssignees.Decision / WorkflowHistory می‌ماند.
   ========================================================================== */

SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;
GO

/* =====================================================================
   بستنِ «تسکِ مرحله» — StepInstance CAS + وضعیتِ کارتابلِ per-assignee
   ===================================================================== */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_CompleteStepTask
    @StepInstanceID      BIGINT,
    @ExpectedRowVersion  NVARCHAR(34) = NULL,
    @OutcomeActionCode   NVARCHAR(64),
    @OutcomeTransitionID INT = NULL,
    @ActorUserID         INT,
    @Disposition         NVARCHAR(20)   -- APPROVED | REJECTED | RETURNED | COMPLETED
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @rv BINARY(8) = NULL;
    BEGIN TRY
        IF @ExpectedRowVersion IS NOT NULL AND LEN(@ExpectedRowVersion) > 0
            SET @rv = CONVERT(BINARY(8), @ExpectedRowVersion, 1);
    END TRY BEGIN CATCH SET @rv = NULL; END CATCH

    DECLARE @cur       NVARCHAR(20) = (SELECT Status    FROM dbo.WorkflowStepInstances WHERE StepInstanceID = @StepInstanceID);
    DECLARE @MessageID INT          = (SELECT MessageID FROM dbo.WorkflowStepInstances WHERE StepInstanceID = @StepInstanceID);

    -- CAS: RowVersion + Status = ACTIVE
    UPDATE dbo.WorkflowStepInstances
    SET Status = N'COMPLETED', CompletedAt = SYSDATETIME(),
        OutcomeActionCode = @OutcomeActionCode, OutcomeTransitionID = @OutcomeTransitionID,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ActorUserID
    WHERE StepInstanceID = @StepInstanceID
      AND Status = N'ACTIVE'
      AND (@rv IS NULL OR RowVersion = @rv);

    IF @@ROWCOUNT = 0
    BEGIN
        SELECT 0 AS Success, CAST(1 AS BIT) AS Concurrency,
               CASE WHEN @cur IS NULL THEN N'مرحله یافت نشد.'
                    WHEN @cur <> N'ACTIVE' THEN N'این مرحله قبلاً بسته شده است.'
                    ELSE N'مرحله هم‌زمان توسط کاربر دیگری تغییر کرده است. صفحه را تازه کنید.' END AS Message,
               ISNULL(@cur, N'') AS CurrentStatus;
        RETURN;
    END

    -- وضعیتِ کارتابلِ هر assignee بر اساسِ تصمیمِ خودش + جهتِ نتیجهٔ مرحله
    DECLARE @DoneID   INT = (SELECT MessageStatusID FROM dbo.MessageStatuses WHERE MessageStatusName = N'انجام شده');
    DECLARE @WontDoID INT = (SELECT MessageStatusID FROM dbo.MessageStatuses WHERE MessageStatusName = N'انجام نخواهد شد');
    DECLARE @Positive BIT = CASE WHEN @Disposition IN (N'APPROVED', N'COMPLETED') THEN 1 ELSE 0 END;

    IF @MessageID IS NOT NULL AND @DoneID IS NOT NULL AND @WontDoID IS NOT NULL
        UPDATE md
        SET md.MessageStatusID =
                CASE
                    WHEN @Positive = 1 AND md.ToUserID = @ActorUserID THEN @DoneID           -- اقدام‌کننده با نتیجهٔ مثبت
                    WHEN @Positive = 1 AND EXISTS (
                            SELECT 1 FROM dbo.WorkflowTaskAssignees a
                            WHERE a.StepInstanceID = @StepInstanceID
                              AND a.UserID = md.ToUserID
                              AND a.Decision = N'APPROVED'
                         ) THEN @DoneID                                                       -- تأییدکنندهٔ قبلی با نتیجهٔ مثبت
                    ELSE @WontDoID                                                            -- رد/عودت/بی‌اثر
                END,
            md.CreateDate = GETDATE(), md.CreateUser = @ActorUserID
        FROM dbo.MessageDetails md
        JOIN dbo.MessageStatuses ms ON ms.MessageStatusID = md.MessageStatusID
        WHERE md.MessageID = @MessageID
          AND ms.MessageStatusName NOT IN (N'انجام شده', N'انجام نخواهد شد');   -- idempotent: ردیف‌های پایانی دست‌نخورده

    SELECT 1 AS Success, CAST(0 AS BIT) AS Concurrency, N'مرحله بسته شد.' AS Message;
END
GO


/* =====================================================================
   لغوِ Instance — کارتابلِ تسک‌های باز → 6 (N'انجام نخواهد شد')  ، نه 4
   ===================================================================== */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_CancelInstance
    @InstanceID  BIGINT,
    @ActorUserID INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @cur NVARCHAR(20) = (SELECT Status FROM dbo.WorkflowInstances WHERE InstanceID = @InstanceID);

    IF @cur IS NULL
    BEGIN
        SELECT 0 AS Success, CAST(0 AS BIT) AS Conflict, N'نمونهٔ فرایند یافت نشد.' AS Message,
               0 AS ClosedTaskCount, 0 AS SkippedStepCount, N'' AS CurrentStatus;
        RETURN;
    END

    UPDATE dbo.WorkflowInstances
    SET Status = N'CANCELLED', CompletedAt = SYSDATETIME(),
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ActorUserID
    WHERE InstanceID = @InstanceID AND Status IN (N'RUNNING', N'SUSPENDED');

    IF @@ROWCOUNT = 0
    BEGIN
        SELECT 0 AS Success, CAST(1 AS BIT) AS Conflict,
               N'فرایند در وضعیتِ «' + @cur + N'» قابلِ لغو نیست.' AS Message,
               0 AS ClosedTaskCount, 0 AS SkippedStepCount, @cur AS CurrentStatus;
        RETURN;
    END

    DECLARE @WontDoID INT = (SELECT MessageStatusID FROM dbo.MessageStatuses WHERE MessageStatusName = N'انجام نخواهد شد');
    DECLARE @Closed TABLE (MessageID INT PRIMARY KEY);

    -- کارتابلِ همهٔ ردیف‌های بازِ تسک‌های مراحلِ فعال → «انجام نخواهد شد» (نه «انجام شده»)
    IF @WontDoID IS NOT NULL
        UPDATE md
        SET md.MessageStatusID = @WontDoID, md.CreateDate = SYSDATETIME(), md.CreateUser = @ActorUserID
        FROM dbo.MessageDetails md
        JOIN dbo.WorkflowStepInstances si ON si.MessageID = md.MessageID
        JOIN dbo.MessageStatuses ms       ON ms.MessageStatusID = md.MessageStatusID
        WHERE si.InstanceID = @InstanceID AND si.Status = N'ACTIVE'
          AND ms.MessageStatusName NOT IN (N'انجام شده', N'انجام نخواهد شد');

    INSERT INTO @Closed (MessageID)
    SELECT DISTINCT si.MessageID
    FROM dbo.WorkflowStepInstances si
    WHERE si.InstanceID = @InstanceID AND si.Status = N'ACTIVE' AND si.MessageID IS NOT NULL;
    DECLARE @ClosedCount INT = @@ROWCOUNT;

    UPDATE dbo.WorkflowStepInstances
    SET Status = N'SKIPPED', CompletedAt = SYSDATETIME(),
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ActorUserID
    WHERE InstanceID = @InstanceID AND Status = N'ACTIVE';
    DECLARE @SkippedCount INT = @@ROWCOUNT;

    SELECT 1 AS Success, CAST(0 AS BIT) AS Conflict, N'فرایند لغو شد.' AS Message,
           @ClosedCount AS ClosedTaskCount, @SkippedCount AS SkippedStepCount, @cur AS CurrentStatus;

    SELECT MessageID FROM @Closed ORDER BY MessageID;
END
GO

/* ==================== پایان پچ 018 ==================== */
