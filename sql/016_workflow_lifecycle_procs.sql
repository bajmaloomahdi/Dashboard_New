/* ==========================================================================
   پچ 016 | Workflow Engine — Step 6 — چرخهٔ حیاتِ Instance (Cancel / Suspend / Resume)

   قوانین:
   - فقط SPهای جدید با پیشوندِ sp_Wf_*. هیچ SP/Function/View/Table موجودی تغییر نمی‌کند.
   - هیچ Schema Change / ستون / Index / Constraint جدید.
   - State Guard با «Compare-And-Swap روی Status» (UPDATE ... WHERE Status IN (...)):
       @@ROWCOUNT = 0  →  Success=0, Conflict=1  →  در PHP به WorkflowStateException (HTTP 409)
   - وضعیت‌های مجاز (طبقِ Final Design بخش‌های ۳.۱۳ و ۳.۲):
       CANCEL :  RUNNING | SUSPENDED  →  CANCELLED   (+ بستنِ تسک‌های باز + SKIP مراحلِ فعال)
       SUSPEND:  RUNNING              →  SUSPENDED   (تسک‌ها دست‌نخورده)
       RESUME :  SUSPENDED            →  RUNNING     (تسک‌ها دست‌نخورده)
       COMPLETED / CANCELLED / FAILED  →  هیچ‌کدام (State Conflict)
   - Reason در PHP داخلِ WorkflowHistory.Summary/DetailJson ذخیره می‌شود (بدونِ ستونِ جدید).
   ========================================================================== */

SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;
GO

/* =====================================================================
   لغوِ Instance  —  RUNNING | SUSPENDED  →  CANCELLED
   نتیجهٔ ۱: Success / Conflict / Message / ClosedTaskCount / SkippedStepCount
   نتیجهٔ ۲: فهرستِ TaskIDهای بسته‌شده  (فقط در حالتِ موفق)
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

    -- CAS روی وضعیت
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

    -- بستنِ تسک‌های باز
    DECLARE @Closed TABLE (TaskID BIGINT PRIMARY KEY);

    UPDATE dbo.WorkflowTasks
    SET Status = N'CANCELLED', CompletedAt = SYSDATETIME(), CompletedByUserID = @ActorUserID,
        CompletionActionCode = N'INSTANCE_CANCELLED',
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ActorUserID
    OUTPUT INSERTED.TaskID INTO @Closed (TaskID)
    WHERE InstanceID = @InstanceID AND Status IN (N'PENDING', N'IN_PROGRESS');

    DECLARE @ClosedCount INT = @@ROWCOUNT;

    -- SKIP کردنِ مراحلِ فعال
    UPDATE dbo.WorkflowStepInstances
    SET Status = N'SKIPPED', CompletedAt = SYSDATETIME(),
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ActorUserID
    WHERE InstanceID = @InstanceID AND Status = N'ACTIVE';

    DECLARE @SkippedCount INT = @@ROWCOUNT;

    SELECT 1 AS Success, CAST(0 AS BIT) AS Conflict, N'فرایند لغو شد.' AS Message,
           @ClosedCount AS ClosedTaskCount, @SkippedCount AS SkippedStepCount, @cur AS CurrentStatus;

    SELECT TaskID FROM @Closed ORDER BY TaskID;
END
GO


/* =====================================================================
   تعلیقِ Instance  —  RUNNING  →  SUSPENDED  (تسک‌ها دست‌نخورده)
   ===================================================================== */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_SuspendInstance
    @InstanceID  BIGINT,
    @ActorUserID INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @cur NVARCHAR(20) = (SELECT Status FROM dbo.WorkflowInstances WHERE InstanceID = @InstanceID);

    IF @cur IS NULL
    BEGIN
        SELECT 0 AS Success, CAST(0 AS BIT) AS Conflict, N'نمونهٔ فرایند یافت نشد.' AS Message, N'' AS CurrentStatus;
        RETURN;
    END

    UPDATE dbo.WorkflowInstances
    SET Status = N'SUSPENDED', Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ActorUserID
    WHERE InstanceID = @InstanceID AND Status = N'RUNNING';

    IF @@ROWCOUNT = 0
    BEGIN
        SELECT 0 AS Success, CAST(1 AS BIT) AS Conflict,
               N'فقط فرایندِ در حالِ اجرا قابلِ تعلیق است (وضعیتِ فعلی: «' + @cur + N'»).' AS Message,
               @cur AS CurrentStatus;
        RETURN;
    END

    SELECT 1 AS Success, CAST(0 AS BIT) AS Conflict, N'فرایند تعلیق شد.' AS Message, @cur AS CurrentStatus;
END
GO


/* =====================================================================
   ازسرگیریِ Instance  —  SUSPENDED  →  RUNNING  (تسک‌ها دست‌نخورده)
   ===================================================================== */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_ResumeInstance
    @InstanceID  BIGINT,
    @ActorUserID INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @cur NVARCHAR(20) = (SELECT Status FROM dbo.WorkflowInstances WHERE InstanceID = @InstanceID);

    IF @cur IS NULL
    BEGIN
        SELECT 0 AS Success, CAST(0 AS BIT) AS Conflict, N'نمونهٔ فرایند یافت نشد.' AS Message, N'' AS CurrentStatus;
        RETURN;
    END

    UPDATE dbo.WorkflowInstances
    SET Status = N'RUNNING', Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ActorUserID
    WHERE InstanceID = @InstanceID AND Status = N'SUSPENDED';

    IF @@ROWCOUNT = 0
    BEGIN
        SELECT 0 AS Success, CAST(1 AS BIT) AS Conflict,
               N'فقط فرایندِ معلق قابلِ ازسرگیری است (وضعیتِ فعلی: «' + @cur + N'»).' AS Message,
               @cur AS CurrentStatus;
        RETURN;
    END

    SELECT 1 AS Success, CAST(0 AS BIT) AS Conflict, N'فرایند ازسر گرفته شد.' AS Message, @cur AS CurrentStatus;
END
GO

/* ==================== پایان پچ 016 ==================== */
