/* ==========================================================================
   پچ 017 | Workflow ↔ Message Integration  —  حذفِ WorkflowTasks

   هدف: تسکِ فرایند دیگر یک موجودیتِ مستقل نیست؛ آیتمِ کارتابل = یک ردیفِ
   dbo.Messages (MessageTypeID = «وظیفه») + n ردیفِ dbo.MessageDetails (یکی به‌ازای
   هر assignee) که خودبه‌خود در sp_GetMessages دیده می‌شود — دقیقاً مثلِ Taskهای
   عادی و Taskهای پروژه. وضعیتِ Runtime در WorkflowStepInstances +
   WorkflowTaskAssignees + WorkflowHistory می‌ماند.

   قوانین رعایت‌شده:
   - همهٔ جدول‌های Workflow صفر ردیف دارند ⇒ هیچ Data Migration.
   - هیچ FKِ خارج از Workflow به WorkflowTasks وابسته نیست (بررسی شد).
   - تنها تغییرِ SPِ غیرِ Workflow: sp_GetMessages — افزایشیِ محض و scope‌شده به
     پیام‌هایی که به یک WorkflowStepInstance لینک‌اند (امروز = صفر پیام).
   - هیچ جدولِ Task جایگزینی ساخته نمی‌شود.
   ========================================================================== */

SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;
GO

/* =====================================================================
   بخش ۱ — تغییرِ Schema  (WorkflowTasks صفر ردیف ⇒ بدونِ migration)
   ===================================================================== */

-- 1-الف) ستون‌های Runtime که قبلاً روی WorkflowTasks بودند → WorkflowStepInstances
IF COL_LENGTH('dbo.WorkflowStepInstances', 'MessageID') IS NULL
BEGIN
    ALTER TABLE dbo.WorkflowStepInstances ADD
        MessageID          INT           NULL,   -- پلِ اتصال به کارتابل (Messages.MessageID) — فقط USER_TASK/APPROVAL
        AssignPolicy       NVARCHAR(10)  NULL,   -- snapshot از WorkflowSteps
        RequiredApprovals  INT           NULL,
        ReceivedApprovals  INT           NOT NULL CONSTRAINT DF_WfStepInst_RecvAppr DEFAULT (0),
        ReceivedRejections INT           NOT NULL CONSTRAINT DF_WfStepInst_RecvRej  DEFAULT (0),
        FirstOpenedAt      DATETIME2(3)  NULL;
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = 'CK_WfStepInst_AssignPolicy')
    ALTER TABLE dbo.WorkflowStepInstances WITH CHECK
        ADD CONSTRAINT CK_WfStepInst_AssignPolicy
        CHECK (AssignPolicy IS NULL OR AssignPolicy IN (N'ANY', N'ALL', N'N_OF_M'));
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WorkflowStepInstances_Message')
    CREATE INDEX IX_WorkflowStepInstances_Message
        ON dbo.WorkflowStepInstances (MessageID) WHERE MessageID IS NOT NULL;
GO

-- 1-ب) WorkflowTaskAssignees — کلید از TaskID به StepInstanceID
IF COL_LENGTH('dbo.WorkflowTaskAssignees', 'TaskID') IS NOT NULL
BEGIN
    IF EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_WfTaskAssignees_Task')
        ALTER TABLE dbo.WorkflowTaskAssignees DROP CONSTRAINT FK_WfTaskAssignees_Task;

    IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_WorkflowTaskAssignees_Task_User_Active')
        DROP INDEX UX_WorkflowTaskAssignees_Task_User_Active ON dbo.WorkflowTaskAssignees;
    IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WorkflowTaskAssignees_User_Active')
        DROP INDEX IX_WorkflowTaskAssignees_User_Active ON dbo.WorkflowTaskAssignees;

    EXEC sp_rename 'dbo.WorkflowTaskAssignees.TaskID', 'StepInstanceID', 'COLUMN';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_WfTaskAssignees_StepInstance')
    ALTER TABLE dbo.WorkflowTaskAssignees WITH CHECK
        ADD CONSTRAINT FK_WfTaskAssignees_StepInstance
        FOREIGN KEY (StepInstanceID) REFERENCES dbo.WorkflowStepInstances (StepInstanceID);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_WorkflowTaskAssignees_Step_User_Active')
    CREATE UNIQUE INDEX UX_WorkflowTaskAssignees_Step_User_Active
        ON dbo.WorkflowTaskAssignees (StepInstanceID, UserID) WHERE IsActive = 1;
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WorkflowTaskAssignees_User_Active')
    CREATE INDEX IX_WorkflowTaskAssignees_User_Active
        ON dbo.WorkflowTaskAssignees (UserID) INCLUDE (StepInstanceID) WHERE IsActive = 1;
GO

-- 1-ج) WorkflowHistory — ستونِ TaskID دیگر معنایی ندارد → MessageID (بدونِ FK، مثلِ EntityID)
IF COL_LENGTH('dbo.WorkflowHistory', 'TaskID') IS NOT NULL
BEGIN
    IF EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_WfHistory_Task')
        ALTER TABLE dbo.WorkflowHistory DROP CONSTRAINT FK_WfHistory_Task;
    IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WorkflowHistory_Task')
        DROP INDEX IX_WorkflowHistory_Task ON dbo.WorkflowHistory;

    EXEC sp_rename 'dbo.WorkflowHistory.TaskID', 'MessageID', 'COLUMN';
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WorkflowHistory_Message')
    CREATE INDEX IX_WorkflowHistory_Message ON dbo.WorkflowHistory (MessageID, OccurredAt);
GO


/* =====================================================================
   بخش ۲ — حذفِ WorkflowTasks
   ===================================================================== */
IF OBJECT_ID('dbo.WorkflowTasks', 'U') IS NOT NULL
BEGIN
    IF EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_WfTasks_ParentTask')
        ALTER TABLE dbo.WorkflowTasks DROP CONSTRAINT FK_WfTasks_ParentTask;

    IF (SELECT COUNT(*) FROM dbo.WorkflowTasks) > 0
        THROW 50017, N'WorkflowTasks خالی نیست — DROP لغو شد.', 1;

    DROP TABLE dbo.WorkflowTasks;
END
GO


/* =====================================================================
   بخش ۳ — SP جدید: ساختِ «وظیفهٔ فرایند» به‌صورتِ Message + MessageDetails
   ===================================================================== */

/* چرا نمی‌توان sp_InsertMessage / sp_InsertProjectTask را reuse کرد:
   - sp_InsertMessage: «گیرندهٔ وظیفه باید مدیرِ واحد باشد» + دقیقاً ۱ گیرنده +
     قوانینِ org فرستنده/گیرنده — با انتساب‌های role/position/unit/initiator و
     چند assignee هم‌زمانِ Workflow ناسازگار است.
   - sp_InsertProjectTask: مختصِ عضویتِ پروژه.
   این SP همان جداولِ Messages / MessageDetails / UserNotifications را می‌نویسد،
   با قراردادِ Workflow (assigneeها از AssignmentResolver از قبل نهایی شده‌اند). */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_CreateTaskMessage
    @Subject       NVARCHAR(500),
    @MessageText   NVARCHAR(MAX)  = NULL,
    @msgPriorityID INT            = NULL,
    @DueDate       DATE           = NULL,
    @SenderUserID  INT,
    @AssigneesJson NVARCHAR(MAX),          -- [{ "userId": 5 }, ...]
    @CreateUser    INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @TaskTypeID INT = (SELECT MessageTypeID FROM dbo.MessageTypes WHERE MessageTypeName = N'وظیفه' AND IsActive = 1);
    IF @TaskTypeID IS NULL
    BEGIN SELECT 0 AS Success, N'نوعِ پیامِ «وظیفه» یافت نشد.' AS Message, CAST(NULL AS INT) AS MessageID, N'' AS MessageNumber; RETURN; END

    IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @SenderUserID)
    BEGIN SELECT 0 AS Success, N'فرستندهٔ وظیفه نامعتبر است.' AS Message, CAST(NULL AS INT) AS MessageID, N'' AS MessageNumber; RETURN; END

    IF NULLIF(LTRIM(RTRIM(@Subject)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'عنوانِ وظیفه الزامی است.' AS Message, CAST(NULL AS INT) AS MessageID, N'' AS MessageNumber; RETURN; END

    -- اولویت: در نبود/نامعتبری → پایین‌ترین اولویتِ فعال
    IF @msgPriorityID IS NULL OR NOT EXISTS (SELECT 1 FROM dbo.msgPriorities WHERE msgPriorityID = @msgPriorityID AND IsActive = 1)
        SELECT TOP 1 @msgPriorityID = msgPriorityID FROM dbo.msgPriorities WHERE IsActive = 1 ORDER BY SortOrder ASC;

    DECLARE @DefaultStatusID INT = (SELECT MessageStatusID FROM dbo.MessageStatuses WHERE MessageStatusName = N'ارسال شده' AND IsActive = 1);
    IF @DefaultStatusID IS NULL SET @DefaultStatusID = (SELECT MIN(MessageStatusID) FROM dbo.MessageStatuses);

    DECLARE @Assignees TABLE (UserID INT PRIMARY KEY);
    IF ISJSON(@AssigneesJson) = 1
        INSERT INTO @Assignees (UserID)
        SELECT DISTINCT j.userId
        FROM OPENJSON(@AssigneesJson) WITH (userId INT N'$.userId') j
        WHERE j.userId IS NOT NULL
          AND EXISTS (SELECT 1 FROM dbo.Users u WHERE u.UserID = j.userId AND u.IsActive = 1);

    IF NOT EXISTS (SELECT 1 FROM @Assignees)
    BEGIN SELECT 0 AS Success, N'هیچ انجام‌دهندهٔ معتبری برای این مرحله نیست.' AS Message, CAST(NULL AS INT) AS MessageID, N'' AS MessageNumber; RETURN; END

    DECLARE @Year SMALLINT = dbo.fn_JalaliYear(GETDATE());
    DECLARE @Format NVARCHAR(100) = (SELECT NumberFormat FROM dbo.MessageTypes WHERE MessageTypeID = @TaskTypeID);
    IF NULLIF(@Format, N'') IS NULL SET @Format = N'MSG-{YEAR}-{SEQ:6}';

    DECLARE @MessageID INT, @MessageNumber NVARCHAR(100) = N'';

    BEGIN TRY
        BEGIN TRAN;

        INSERT INTO dbo.Messages (RowGuid, MessageTypeID, msgPriorityID, SenderUserID, Subject, MessageText, CreateDate, CreateUser, DueDate)
        VALUES (NEWID(), @TaskTypeID, @msgPriorityID, @SenderUserID, @Subject, @MessageText, GETDATE(), @CreateUser, @DueDate);
        SET @MessageID = SCOPE_IDENTITY();

        -- شماره‌گذاری — همان شمارندهٔ MessageNumberCounters و همان fn_FormatMessageNumber موجود
        IF NOT EXISTS (SELECT 1 FROM dbo.MessageNumberCounters WHERE MessageTypeID = @TaskTypeID AND Year = @Year)
            INSERT INTO dbo.MessageNumberCounters (MessageTypeID, Year, LastNumber) VALUES (@TaskTypeID, @Year, 0);

        MERGE dbo.MessageNumberCounters WITH (HOLDLOCK) AS T
        USING (VALUES (@TaskTypeID, @Year)) AS S (MessageTypeID, Year)
            ON T.MessageTypeID = S.MessageTypeID AND T.Year = S.Year
        WHEN MATCHED THEN UPDATE SET LastNumber = T.LastNumber + 1
        WHEN NOT MATCHED THEN INSERT (MessageTypeID, Year, LastNumber) VALUES (@TaskTypeID, @Year, 1);

        DECLARE @Serial INT = (SELECT LastNumber FROM dbo.MessageNumberCounters WHERE MessageTypeID = @TaskTypeID AND Year = @Year);
        SET @MessageNumber = dbo.fn_FormatMessageNumber(@Format, @Year, @Serial);
        UPDATE dbo.Messages SET MessageNumber = @MessageNumber WHERE MessageID = @MessageID;

        -- یک ردیفِ MessageDetails به‌ازای هر assignee  (← همین باعثِ دیده‌شدن در کارتابلِ موجود می‌شود)
        INSERT INTO dbo.MessageDetails (RowGuid, MessageID, FromUserID, ToUserID, MessageStatusID, CreateDate, CreateUser)
        SELECT NEWID(), @MessageID, @SenderUserID, a.UserID, @DefaultStatusID, GETDATE(), @CreateUser
        FROM @Assignees a;

        INSERT INTO dbo.UserNotifications (UserID, MessageID, CreateDate, IsRead)
        SELECT a.UserID, @MessageID, GETDATE(), 0 FROM @Assignees a;

        COMMIT;
        SELECT 1 AS Success, N'وظیفهٔ فرایند ایجاد شد. شماره: ' + @MessageNumber AS Message, @MessageID AS MessageID, @MessageNumber AS MessageNumber;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK;
        SELECT 0 AS Success, N'خطا در ایجاد وظیفهٔ فرایند: ' + ERROR_MESSAGE() AS Message, CAST(NULL AS INT) AS MessageID, N'' AS MessageNumber;
    END CATCH
END
GO

/* اتصالِ MessageID + snapshotِ سیاست به StepInstance (پس از ساختِ Message) */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_AttachStepMessage
    @StepInstanceID    BIGINT,
    @MessageID         INT,
    @AssignPolicy      NVARCHAR(10) = N'ANY',
    @RequiredApprovals INT = NULL,
    @UserID            INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE dbo.WorkflowStepInstances
    SET MessageID = @MessageID,
        AssignPolicy = ISNULL(@AssignPolicy, N'ANY'),
        RequiredApprovals = @RequiredApprovals,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE StepInstanceID = @StepInstanceID;
    SELECT CASE WHEN @@ROWCOUNT > 0 THEN 1 ELSE 0 END AS Success, N'اتصال انجام شد.' AS Message;
END
GO


/* =====================================================================
   بخش ۴ — بازنویسیِ SPهای تسک برای کلیدِ StepInstanceID
   ===================================================================== */

CREATE OR ALTER PROCEDURE dbo.sp_Wf_InsertTaskAssignee
    @StepInstanceID BIGINT,
    @UserID         INT,
    @SourceType     NVARCHAR(30),
    @SourceRefID    INT = NULL,
    @ActorUserID    INT = NULL
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @UserID AND IsActive = 1)
    BEGIN SELECT 0 AS Success, N'کاربر نامعتبر یا غیرفعال است.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.WorkflowTaskAssignees WHERE StepInstanceID = @StepInstanceID AND UserID = @UserID AND IsActive = 1)
    BEGIN SELECT 1 AS Success, N'کاربر از قبل انجام‌دهنده‌ی این مرحله است.' AS Message; RETURN; END

    INSERT INTO dbo.WorkflowTaskAssignees (StepInstanceID, UserID, SourceType, SourceRefID, IsActive, Date_InsertFirst, UserID_InsertFirst)
    VALUES (@StepInstanceID, @UserID, @SourceType, @SourceRefID, 1, SYSDATETIME(), @ActorUserID);

    SELECT 1 AS Success, N'انجام‌دهنده افزوده شد.' AS Message, CAST(SCOPE_IDENTITY() AS BIGINT) AS TaskAssigneeID;
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_Wf_MarkStepFirstOpened
    @StepInstanceID BIGINT,
    @UserID         INT
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE dbo.WorkflowStepInstances
    SET FirstOpenedAt = SYSDATETIME(), Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE StepInstanceID = @StepInstanceID AND FirstOpenedAt IS NULL AND Status = N'ACTIVE';
    SELECT CASE WHEN @@ROWCOUNT > 0 THEN 1 ELSE 0 END AS Changed;
END
GO

/* ثبتِ تصمیمِ یک assignee (idempotent: فقط اگر Decision خالی) + شمارنده‌های StepInstance */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_SetAssigneeDecision
    @StepInstanceID BIGINT,
    @UserID         INT,
    @Decision       NVARCHAR(20),   -- APPROVED | REJECTED | RETURNED
    @ActionCode     NVARCHAR(64),
    @Comment        NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    UPDATE dbo.WorkflowTaskAssignees
    SET Decision = @Decision, ActionCode = @ActionCode, ActedAt = SYSDATETIME(), Comment = @Comment,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE StepInstanceID = @StepInstanceID AND UserID = @UserID AND IsActive = 1 AND Decision IS NULL;

    IF @@ROWCOUNT = 0
    BEGIN SELECT 0 AS Success, N'شما قبلاً روی این مرحله اقدام کرده‌اید یا انجام‌دهنده‌ی آن نیستید.' AS Message; RETURN; END

    UPDATE dbo.WorkflowStepInstances
    SET ReceivedApprovals  = ReceivedApprovals  + CASE WHEN @Decision = N'APPROVED' THEN 1 ELSE 0 END,
        ReceivedRejections = ReceivedRejections + CASE WHEN @Decision = N'REJECTED' THEN 1 ELSE 0 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE StepInstanceID = @StepInstanceID;

    SELECT 1 AS Success, N'تصمیم ثبت شد.' AS Message,
           si.AssignPolicy, si.RequiredApprovals, si.ReceivedApprovals, si.ReceivedRejections,
           (SELECT COUNT(*) FROM dbo.WorkflowTaskAssignees a WHERE a.StepInstanceID = @StepInstanceID AND a.IsActive = 1) AS ActiveAssigneeCount,
           (SELECT COUNT(*) FROM dbo.WorkflowTaskAssignees a WHERE a.StepInstanceID = @StepInstanceID AND a.IsActive = 1 AND a.Decision IS NOT NULL) AS ActedCount
    FROM dbo.WorkflowStepInstances si WHERE si.StepInstanceID = @StepInstanceID;
END
GO

/* بستنِ «تسکِ مرحله»: StepInstance → COMPLETED (CAS با RowVersion + Status) و
   بستنِ ردیف‌های بازِ MessageDetails تا از کارتابل خارج شود (رفتارِ عینِ Taskهای موجود). */
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

    DECLARE @cur NVARCHAR(20) = (SELECT Status FROM dbo.WorkflowStepInstances WHERE StepInstanceID = @StepInstanceID);
    DECLARE @MessageID INT   = (SELECT MessageID FROM dbo.WorkflowStepInstances WHERE StepInstanceID = @StepInstanceID);

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

    -- بستنِ کارتابلِ همهٔ assigneeها (تصمیم‌گرفته یا نگرفته) — رفتارِ پایانی مثلِ Taskهای موجود
    DECLARE @DoneStatusID INT = (SELECT MessageStatusID FROM dbo.MessageStatuses WHERE MessageStatusName = N'انجام شده');
    IF @MessageID IS NOT NULL AND @DoneStatusID IS NOT NULL
        UPDATE md
        SET md.MessageStatusID = @DoneStatusID, md.CreateDate = GETDATE(), md.CreateUser = @ActorUserID
        FROM dbo.MessageDetails md
        JOIN dbo.MessageStatuses ms ON ms.MessageStatusID = md.MessageStatusID
        WHERE md.MessageID = @MessageID
          AND ms.MessageStatusName NOT IN (N'انجام شده', N'انجام نخواهد شد');

    SELECT 1 AS Success, CAST(0 AS BIT) AS Concurrency, N'مرحله بسته شد.' AS Message;
END
GO

/* تاریخچه — @TaskID → @MessageID */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_InsertHistory
    @EntityType     NVARCHAR(64),
    @EntityID       BIGINT,
    @EventCode      NVARCHAR(50),
    @InstanceID     BIGINT = NULL,
    @StepInstanceID BIGINT = NULL,
    @MessageID      BIGINT = NULL,
    @ActorUserID    INT = NULL,
    @ActorType      NVARCHAR(10) = N'USER',
    @Summary        NVARCHAR(500) = NULL,
    @OldValueJson   NVARCHAR(MAX) = NULL,
    @NewValueJson   NVARCHAR(MAX) = NULL,
    @DetailJson     NVARCHAR(MAX) = NULL,
    @IpAddress      NVARCHAR(45) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    INSERT INTO dbo.WorkflowHistory
        (InstanceID, StepInstanceID, MessageID, EntityType, EntityID, EventCode, ActorUserID, ActorType, OccurredAt,
         Summary, OldValueJson, NewValueJson, DetailJson, IpAddress)
    VALUES
        (@InstanceID, @StepInstanceID, @MessageID, @EntityType, @EntityID, @EventCode, @ActorUserID, ISNULL(@ActorType, N'USER'), SYSDATETIME(),
         @Summary, @OldValueJson, @NewValueJson, @DetailJson, @IpAddress);
    SELECT 1 AS Success, CAST(SCOPE_IDENTITY() AS BIGINT) AS HistoryID;
END
GO


/* =====================================================================
   بخش ۵ — Query: جزئیاتِ تسکِ مرحله (بر پایهٔ MessageID) + Instance
   ===================================================================== */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetStepTaskDetail
    @MessageID INT
AS
BEGIN
    SET NOCOUNT ON;

    -- سرآیند: Message + StepInstance + Step + Instance + Definition
    SELECT
        m.MessageID, m.MessageNumber, m.Subject, m.MessageText, m.DueDate, m.CreateDate,
        m.msgPriorityID AS PriorityID, mp.Name AS PriorityName,
        m.SenderUserID, snd.FullName AS SenderName,
        si.StepInstanceID, si.StepCode, si.StepType, si.Status AS StepStatus,
        si.AssignPolicy, si.RequiredApprovals, si.ReceivedApprovals, si.ReceivedRejections,
        si.FirstOpenedAt, si.CompletedAt, si.OutcomeActionCode, si.RowVersion,
        s.StepID, s.Name AS StepName, s.AllowForward, s.AllowDelegation,
        i.InstanceID, i.InstanceNumber, i.Status AS InstanceStatus, i.VersionID,
        i.EntityType, i.EntityID, i.StartedByUserID, isu.FullName AS StartedByName,
        d.DefinitionID, d.Code AS DefinitionCode, d.Name AS DefinitionName
    FROM dbo.WorkflowStepInstances si
    JOIN dbo.Messages m               ON m.MessageID = si.MessageID
    JOIN dbo.WorkflowInstances i      ON i.InstanceID = si.InstanceID
    JOIN dbo.WorkflowSteps s          ON s.StepID = si.StepID
    JOIN dbo.WorkflowDefinitions d    ON d.DefinitionID = i.DefinitionID
    LEFT JOIN dbo.msgPriorities mp    ON mp.msgPriorityID = m.msgPriorityID
    LEFT JOIN dbo.Users snd           ON snd.UserID = m.SenderUserID
    LEFT JOIN dbo.Users isu           ON isu.UserID = i.StartedByUserID
    WHERE si.MessageID = @MessageID;

    -- انجام‌دهنده‌ها + وضعیتِ کارتابلِ شخصیِ هرکدام
    SELECT a.TaskAssigneeID, a.StepInstanceID, a.UserID, u.FullName, a.SourceType, a.SourceRefID, a.IsActive,
           a.Decision, a.ActionCode, a.ActedAt, a.Comment,
           md.MessageStatusID AS PersonalStatusID, ms.MessageStatusName AS PersonalStatusName
    FROM dbo.WorkflowTaskAssignees a
    JOIN dbo.WorkflowStepInstances si ON si.StepInstanceID = a.StepInstanceID
    JOIN dbo.Users u                  ON u.UserID = a.UserID
    LEFT JOIN dbo.MessageDetails md   ON md.MessageID = si.MessageID AND md.ToUserID = a.UserID
    LEFT JOIN dbo.MessageStatuses ms  ON ms.MessageStatusID = md.MessageStatusID
    WHERE si.MessageID = @MessageID
    ORDER BY a.IsActive DESC, a.TaskAssigneeID;

    -- Actionهای مرحله
    SELECT act.ActionID, act.Code, act.Kind, act.Label, act.Icon, act.Style,
           act.RequiresComment, act.RequiresConfirm, act.ConfirmMessage, act.PermissionCode, act.SortOrder
    FROM dbo.WorkflowStepActions act
    JOIN dbo.WorkflowStepInstances si ON si.StepID = act.StepID
    WHERE si.MessageID = @MessageID
    ORDER BY act.SortOrder, act.ActionID;

    -- تاریخچهٔ مرتبط
    SELECT h.HistoryID, h.EventCode, h.ActorType, h.OccurredAt, h.Summary, h.DetailJson, h.ActorUserID, u.FullName AS ActorName
    FROM dbo.WorkflowHistory h
    LEFT JOIN dbo.Users u ON u.UserID = h.ActorUserID
    WHERE h.MessageID = @MessageID
       OR h.StepInstanceID IN (SELECT StepInstanceID FROM dbo.WorkflowStepInstances WHERE MessageID = @MessageID)
    ORDER BY h.OccurredAt, h.HistoryID;
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetInstance
    @InstanceID BIGINT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT i.InstanceID, i.InstanceNumber, i.DefinitionID, i.VersionID, i.EntityType, i.EntityID, i.Status,
           i.StartedByUserID, su.FullName AS StartedByName, i.StartedAt, i.CompletedAt, i.TransitionCount,
           d.Code AS DefinitionCode, d.Name AS DefinitionName, v.VersionNo
    FROM dbo.WorkflowInstances i
    JOIN dbo.WorkflowDefinitions d ON d.DefinitionID = i.DefinitionID
    JOIN dbo.WorkflowVersions v ON v.VersionID = i.VersionID
    LEFT JOIN dbo.Users su ON su.UserID = i.StartedByUserID
    WHERE i.InstanceID = @InstanceID;

    SELECT si.StepInstanceID, si.StepCode, si.StepType, si.Status, si.EnteredAt, si.CompletedAt,
           si.OutcomeActionCode, si.IterationNo, si.MessageID, si.AssignPolicy,
           si.ReceivedApprovals, si.ReceivedRejections
    FROM dbo.WorkflowStepInstances si
    WHERE si.InstanceID = @InstanceID
    ORDER BY si.StepInstanceID;

    -- «تسک‌ها» = پیام‌های کارتابلیِ لینک‌شده به مراحلِ این Instance
    SELECT si.StepInstanceID, m.MessageID, m.MessageNumber, m.Subject AS Title, si.Status,
           m.CreateDate AS CreatedAt, s.Name AS StepName,
           (SELECT STRING_AGG(u.FullName, N'، ') FROM dbo.WorkflowTaskAssignees a JOIN dbo.Users u ON u.UserID = a.UserID
             WHERE a.StepInstanceID = si.StepInstanceID AND a.IsActive = 1) AS AssigneeNames,
           (SELECT COUNT(*) FROM dbo.MessageDetails md JOIN dbo.MessageStatuses ms ON ms.MessageStatusID = md.MessageStatusID
             WHERE md.MessageID = m.MessageID AND ms.MessageStatusName NOT IN (N'انجام شده', N'انجام نخواهد شد')) AS OpenRecipientCount
    FROM dbo.WorkflowStepInstances si
    JOIN dbo.Messages m ON m.MessageID = si.MessageID
    JOIN dbo.WorkflowSteps s ON s.StepID = si.StepID
    WHERE si.InstanceID = @InstanceID AND si.MessageID IS NOT NULL
    ORDER BY si.StepInstanceID;
END
GO


/* =====================================================================
   بخش ۶ — بازنویسیِ sp_Wf_CancelInstance (بستنِ کارتابلِ Messages به‌جای WorkflowTasks)
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

    DECLARE @DoneStatusID INT = (SELECT MessageStatusID FROM dbo.MessageStatuses WHERE MessageStatusName = N'انجام شده');
    DECLARE @Closed TABLE (MessageID INT PRIMARY KEY);

    -- بستنِ کارتابلِ پیام‌های مراحلِ فعال
    IF @DoneStatusID IS NOT NULL
        UPDATE md
        SET md.MessageStatusID = @DoneStatusID, md.CreateDate = SYSDATETIME(), md.CreateUser = @ActorUserID
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

/* تاریخچهٔ Instance — ستونِ TaskID → MessageID */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetInstanceHistory
    @InstanceID BIGINT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT h.HistoryID, h.EventCode, h.ActorType, h.OccurredAt, h.Summary, h.DetailJson,
           h.MessageID, h.StepInstanceID, h.ActorUserID, u.FullName AS ActorName
    FROM dbo.WorkflowHistory h
    LEFT JOIN dbo.Users u ON u.UserID = h.ActorUserID
    WHERE h.InstanceID = @InstanceID
    ORDER BY h.OccurredAt, h.HistoryID;
END
GO


/* =====================================================================
   بخش ۷ — حذفِ SPهایی که فقط برای WorkflowTasks بودند
   ===================================================================== */
IF OBJECT_ID('dbo.sp_Wf_InsertTask', 'P')             IS NOT NULL DROP PROCEDURE dbo.sp_Wf_InsertTask;
IF OBJECT_ID('dbo.sp_Wf_GetMyTasks', 'P')             IS NOT NULL DROP PROCEDURE dbo.sp_Wf_GetMyTasks;
IF OBJECT_ID('dbo.sp_Wf_MarkTaskFirstOpened', 'P')    IS NOT NULL DROP PROCEDURE dbo.sp_Wf_MarkTaskFirstOpened;
IF OBJECT_ID('dbo.sp_Wf_SetTaskAssigneeDecision', 'P') IS NOT NULL DROP PROCEDURE dbo.sp_Wf_SetTaskAssigneeDecision;
IF OBJECT_ID('dbo.sp_Wf_CompleteTask', 'P')           IS NOT NULL DROP PROCEDURE dbo.sp_Wf_CompleteTask;
IF OBJECT_ID('dbo.sp_Wf_GetTaskDetail', 'P')          IS NOT NULL DROP PROCEDURE dbo.sp_Wf_GetTaskDetail;
GO


/* =====================================================================
   بخش ۸ — sp_GetMessages : تغییرِ افزایشیِ scope‌شده (تنها SPِ غیرِ Workflow)

   افزوده‌ها:
     • ستونِ IsWfTask در CTE (branch A و B)
     • یک WHEN جدید در سه CASEِ «InInbox» فقط برای وظیفه‌های Workflow
   رفتارِ همهٔ ۲۲ پیامِ موجود (که هیچ‌کدام IsWfTask=1 نیستند) دست‌نخورده می‌ماند.
   وظیفهٔ Workflow برای هر assignee تا وقتی وضعیتِ *شخصیِ* او (MessageDetails خودش)
   پایانی نشده در کارتابل می‌ماند — چون مدلِ «تک‌مالکیِ آخرین‌گیرنده» برای چند
   assignee هم‌زمانِ ANY/ALL/N_OF_M کار نمی‌کند.
   ===================================================================== */
CREATE OR ALTER PROCEDURE [dbo].[sp_GetMessages]
    @UserID           INT,
    @Mode             TINYINT,              -- 1=دریافتی  2=ارسالی
    @IsArchive        BIT           = 0,
    @SearchText       NVARCHAR(200) = NULL,
    @MessageTypeID    INT           = NULL,
    @MessageStatusID  INT           = NULL,
    @FromDate         DATE          = NULL,
    @ToDate           DATE          = NULL,
    @msgPriorityID    INT           = NULL
AS
BEGIN
    SET NOCOUNT ON;

    IF @Mode = 1
    BEGIN
        ;WITH Base AS
        (
            -- الف) گیرنده اصلی
            SELECT
                M.MessageID, M.MessageNumber, M.Subject, M.MessageText, M.MessageTypeID, MT.MessageTypeName,
                M.msgPriorityID, MP.Name AS PriorityName, MP.SortOrder AS PrioritySortOrder,
                M.SenderUserID, LTRIM(CONCAT(SU.FirstName, N' ', SU.LastName)) AS SenderName,
                M.CreateDate, M.DueDate, MS.MessageStatusID, MS.MessageStatusName,
                0 AS IsCopy,
                CASE WHEN EXISTS (SELECT 1 FROM dbo.UserNotifications UN WHERE UN.UserID = @UserID AND UN.MessageID = M.MessageID AND UN.IsRead = 1) THEN 1 ELSE 0 END AS IsRead,
                CASE WHEN MT.MessageTypeName = N'وظیفه' THEN 1 ELSE 0 END AS IsTask,
                CASE WHEN EXISTS (SELECT 1 FROM dbo.WorkflowStepInstances WSI WHERE WSI.MessageID = M.MessageID) THEN 1 ELSE 0 END AS IsWfTask,
                LastD.ToUserID AS LastToUserID, LastD.StatusName AS LastStatusName
            FROM dbo.Messages M
            JOIN dbo.MessageTypes MT    ON MT.MessageTypeID = M.MessageTypeID
            LEFT JOIN dbo.msgPriorities MP ON MP.msgPriorityID = M.msgPriorityID
            LEFT JOIN dbo.Users SU      ON SU.UserID = M.SenderUserID
            JOIN dbo.MessageDetails MD  ON MD.MessageID = M.MessageID AND MD.ToUserID = @UserID
            JOIN dbo.MessageStatuses MS ON MS.MessageStatusID = MD.MessageStatusID
            OUTER APPLY (
                SELECT TOP 1 MD2.ToUserID, MS2.MessageStatusName AS StatusName
                FROM dbo.MessageDetails MD2
                JOIN dbo.MessageStatuses MS2 ON MS2.MessageStatusID = MD2.MessageStatusID
                WHERE MD2.MessageID = M.MessageID
                ORDER BY MD2.CreateDate DESC, MD2.MessageDetailID DESC
            ) LastD

            UNION ALL

            -- ب) رونوشت‌گیرنده (بدونِ ردیفِ گیرندگیِ اصلی)
            SELECT
                M.MessageID, M.MessageNumber, M.Subject, M.MessageText, M.MessageTypeID, MT.MessageTypeName,
                M.msgPriorityID, MP.Name AS PriorityName, MP.SortOrder AS PrioritySortOrder,
                M.SenderUserID, LTRIM(CONCAT(SU.FirstName, N' ', SU.LastName)) AS SenderName,
                M.CreateDate, M.DueDate, ISNULL(MS.MessageStatusID, 1) AS MessageStatusID,
                ISNULL(MS.MessageStatusName, N'ارسال شده') AS MessageStatusName,
                1 AS IsCopy,
                CASE WHEN EXISTS (SELECT 1 FROM dbo.UserNotifications UN WHERE UN.UserID = @UserID AND UN.MessageID = M.MessageID AND UN.IsRead = 1) THEN 1 ELSE 0 END AS IsRead,
                CASE WHEN MT.MessageTypeName = N'وظیفه' THEN 1 ELSE 0 END AS IsTask,
                CASE WHEN EXISTS (SELECT 1 FROM dbo.WorkflowStepInstances WSI WHERE WSI.MessageID = M.MessageID) THEN 1 ELSE 0 END AS IsWfTask,
                LastD.ToUserID AS LastToUserID, LastD.StatusName AS LastStatusName
            FROM dbo.Messages M
            JOIN dbo.MessageTypes MT  ON MT.MessageTypeID = M.MessageTypeID
            LEFT JOIN dbo.msgPriorities MP ON MP.msgPriorityID = M.msgPriorityID
            LEFT JOIN dbo.Users SU    ON SU.UserID = M.SenderUserID
            JOIN dbo.MessageCopies MC ON MC.MessageID = M.MessageID AND MC.UserID = @UserID
            LEFT JOIN dbo.MessageStatuses MS ON MS.MessageStatusID = 1
            OUTER APPLY (
                SELECT TOP 1 MD2.ToUserID, MS2.MessageStatusName AS StatusName
                FROM dbo.MessageDetails MD2
                JOIN dbo.MessageStatuses MS2 ON MS2.MessageStatusID = MD2.MessageStatusID
                WHERE MD2.MessageID = M.MessageID
                ORDER BY MD2.CreateDate DESC, MD2.MessageDetailID DESC
            ) LastD
            WHERE NOT EXISTS (SELECT 1 FROM dbo.MessageDetails MD2 WHERE MD2.MessageID = M.MessageID AND MD2.ToUserID = @UserID)
        )
        SELECT
            MessageID, MessageNumber, Subject, MessageText, MessageTypeID, MessageTypeName,
            msgPriorityID, PriorityName, PrioritySortOrder, SenderUserID, SenderName,
            CreateDate, DueDate, MessageStatusID, MessageStatusName, IsCopy, IsRead,
            CASE
                WHEN IsTask = 1 AND IsWfTask = 1 AND ISNULL(MessageStatusName, N'') NOT IN (N'انجام شده', N'انجام نخواهد شد') THEN 1
                WHEN IsTask = 1 AND IsWfTask = 0 AND LastToUserID = @UserID AND ISNULL(LastStatusName, N'') NOT IN (N'انجام شده', N'انجام نخواهد شد') THEN 1
                WHEN IsTask = 0 AND IsRead = 0 THEN 1
                ELSE 0
            END AS InInbox
        FROM Base
        WHERE
            ((@IsArchive = 0 AND (CASE
                WHEN IsTask = 1 AND IsWfTask = 1 AND ISNULL(MessageStatusName, N'') NOT IN (N'انجام شده', N'انجام نخواهد شد') THEN 1
                WHEN IsTask = 1 AND IsWfTask = 0 AND LastToUserID = @UserID AND ISNULL(LastStatusName, N'') NOT IN (N'انجام شده', N'انجام نخواهد شد') THEN 1
                WHEN IsTask = 0 AND IsRead = 0 THEN 1
                ELSE 0 END) = 1)
             OR
             (@IsArchive = 1 AND (CASE
                WHEN IsTask = 1 AND IsWfTask = 1 AND ISNULL(MessageStatusName, N'') NOT IN (N'انجام شده', N'انجام نخواهد شد') THEN 1
                WHEN IsTask = 1 AND IsWfTask = 0 AND LastToUserID = @UserID AND ISNULL(LastStatusName, N'') NOT IN (N'انجام شده', N'انجام نخواهد شد') THEN 1
                WHEN IsTask = 0 AND IsRead = 0 THEN 1
                ELSE 0 END) = 0))
          AND (@SearchText IS NULL OR Subject LIKE N'%' + @SearchText + N'%' OR MessageNumber LIKE N'%' + @SearchText + N'%')
          AND (@MessageTypeID   IS NULL OR MessageTypeID   = @MessageTypeID)
          AND (@MessageStatusID IS NULL OR MessageStatusID = @MessageStatusID)
          AND (@msgPriorityID   IS NULL OR msgPriorityID   = @msgPriorityID)
          AND (@FromDate IS NULL OR CAST(CreateDate AS DATE) >= @FromDate)
          AND (@ToDate   IS NULL OR CAST(CreateDate AS DATE) <= @ToDate)
        ORDER BY
            CASE WHEN PrioritySortOrder IS NULL THEN 1 ELSE 0 END,
            PrioritySortOrder DESC, CreateDate DESC;
    END
    ELSE
    BEGIN
        -- ارسالی — بدونِ تغییر
        SELECT
            M.MessageID, M.MessageNumber, M.Subject, M.MessageText, M.MessageTypeID, MT.MessageTypeName,
            M.msgPriorityID, MP.Name AS PriorityName, MP.SortOrder AS PrioritySortOrder,
            M.SenderUserID, LTRIM(CONCAT(SU.FirstName, N' ', SU.LastName)) AS SenderName,
            M.CreateDate, M.DueDate,
            ISNULL(LastStatus.MessageStatusID, 1) AS MessageStatusID,
            ISNULL(LastStatus.MessageStatusName, N'ارسال شده') AS MessageStatusName,
            0 AS IsCopy, 1 AS IsRead
        FROM dbo.Messages M
        JOIN dbo.MessageTypes MT  ON MT.MessageTypeID = M.MessageTypeID
        LEFT JOIN dbo.msgPriorities MP ON MP.msgPriorityID = M.msgPriorityID
        LEFT JOIN dbo.Users SU    ON SU.UserID = M.SenderUserID
        OUTER APPLY (
            SELECT TOP 1 MS2.MessageStatusID, MS2.MessageStatusName
            FROM dbo.MessageDetails MD
            JOIN dbo.MessageStatuses MS2 ON MS2.MessageStatusID = MD.MessageStatusID
            WHERE MD.MessageID = M.MessageID
            ORDER BY MD.CreateDate DESC, MD.MessageDetailID DESC
        ) LastStatus
        WHERE M.SenderUserID = @UserID
          AND (@SearchText IS NULL OR M.Subject LIKE N'%' + @SearchText + N'%' OR M.MessageNumber LIKE N'%' + @SearchText + N'%')
          AND (@MessageTypeID   IS NULL OR M.MessageTypeID   = @MessageTypeID)
          AND (@MessageStatusID IS NULL OR LastStatus.MessageStatusID = @MessageStatusID)
          AND (@msgPriorityID   IS NULL OR M.msgPriorityID   = @msgPriorityID)
          AND (@FromDate IS NULL OR CAST(M.CreateDate AS DATE) >= @FromDate)
          AND (@ToDate   IS NULL OR CAST(M.CreateDate AS DATE) <= @ToDate)
        ORDER BY
            CASE WHEN MP.SortOrder IS NULL THEN 1 ELSE 0 END,
            MP.SortOrder DESC, M.CreateDate DESC;
    END
END
GO

/* ==================== پایان پچ 017 ==================== */

/* [MANUAL DATA SEED] — منوی «تسک‌های من» دیگر به کارتابلِ دوم اشاره نکند */
IF EXISTS (SELECT 1 FROM dbo.Menu WHERE MenuCode = N'WF_TASKS' AND Url <> N'/messages')
    UPDATE dbo.Menu SET Url = N'/messages' WHERE MenuCode = N'WF_TASKS';
GO
