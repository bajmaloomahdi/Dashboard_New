/* ==========================================================================
   پچ خودکار شماره: 067 | نام: workflow_one_message_per_instance
   تاریخ: 2026-09-28 19:03:37 | شامل 4 دستور SQL
   ========================================================================== */

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Wf_AddTaskMessageRecipients
/* ادامهٔ «همان نامهٔ اصلیِ Workflow» رویِ یک Stepِ بعدی — بدونِ ساختِ Messageِ تازه.
   فقط MessageDetails/UserNotificationsِ گیرندگانِ همین Step را اضافه می‌کند؛ ردیفِ
   Messages و Subject/MessageText/SenderUserID دست‌نخورده می‌مانند. ردیف‌هایِ بازِ
   گیرندگانِ Stepِ قبلی پیش‌تر توسطِ sp_Wf_CompleteStepTask بسته شده‌اند؛ اینجا فقط
   جلویِ ردیفِ بازِ تکراری برایِ همان گیرنده (اگر از قبل باز باشد) گرفته می‌شود. */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_AddTaskMessageRecipients
    @MessageID     INT,
    @FromUserID    INT,
    @AssigneesJson NVARCHAR(MAX),          -- [{ "userId": 5 }, ...]
    @CreateUser    INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.Messages WHERE MessageID = @MessageID)
    BEGIN SELECT 0 AS Success, N'پیامِ اصلیِ فرایند یافت نشد.' AS Message; RETURN; END

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
    BEGIN SELECT 0 AS Success, N'هیچ انجام‌دهندهٔ معتبری برای این مرحله نیست.' AS Message; RETURN; END

    BEGIN TRY
        BEGIN TRAN;

        INSERT INTO dbo.MessageDetails (RowGuid, MessageID, FromUserID, ToUserID, MessageStatusID, CreateDate, CreateUser)
        SELECT NEWID(), @MessageID, @FromUserID, a.UserID, @DefaultStatusID, GETDATE(), @CreateUser
        FROM @Assignees a
        WHERE NOT EXISTS (
            SELECT 1 FROM dbo.MessageDetails md
            JOIN dbo.MessageStatuses ms ON ms.MessageStatusID = md.MessageStatusID
            WHERE md.MessageID = @MessageID AND md.ToUserID = a.UserID
              AND ms.MessageStatusName NOT IN (N'انجام شده', N'انجام نخواهد شد')
        );

        INSERT INTO dbo.UserNotifications (UserID, MessageID, CreateDate, IsRead)
        SELECT a.UserID, @MessageID, GETDATE(), 0 FROM @Assignees a
        WHERE NOT EXISTS (
            SELECT 1 FROM dbo.UserNotifications UN
            WHERE UN.UserID = a.UserID AND UN.MessageID = @MessageID AND UN.IsRead = 0
        );

        COMMIT;
        SELECT 1 AS Success, N'انجام‌دهندگانِ مرحلهٔ جدید افزوده شدند.' AS Message;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK;
        SELECT 0 AS Success, N'خطا در افزودنِ انجام‌دهندگان: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_GetStepTaskDetail
/* تاریخچهٔ Instance — ستونِ TaskID → MessageID
   تاریخچهٔ مرتبط اکنون بر اساسِ InstanceID فیلتر می‌شود (نه فقط MessageID/StepInstanceID)
   تا رویدادهایِ سطحِ Instance (INSTANCE_STARTED/TRANSITION_TAKEN/INSTANCE_COMPLETED و...
   که MessageID/StepInstanceID ندارند) هم در نمایِ تسک دیده شوند — دقیقاً همان زنجیره‌ای
   که sp_Wf_GetInstanceHistory برایِ صفحهٔ کاملِ Instance برمی‌گرداند. */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetStepTaskDetail
    @MessageID INT
AS
BEGIN
    SET NOCOUNT ON;

    -- آخرین (جاری‌ترین) StepInstanceِ همین Message — چون در معماریِ «یک Instance = یک Message»
    -- ممکن است چند StepInstance (مربوط به چند Step) اشتراکاً همین MessageID را داشته باشند؛
    -- فقط Stepِ جاری/آخرین باید در این سه Result-Set دیده شود.
    DECLARE @CurrentStepInstanceID BIGINT = (
        SELECT TOP 1 si0.StepInstanceID FROM dbo.WorkflowStepInstances si0
        WHERE si0.MessageID = @MessageID
        ORDER BY si0.StepInstanceID DESC
    );

    SELECT
        m.MessageID, m.MessageNumber, m.Subject, m.MessageText, m.DueDate, m.CreateDate,
        m.msgPriorityID AS PriorityID, mp.Name AS PriorityName,
        m.SenderUserID, snd.FullName AS SenderName,
        si.StepInstanceID, si.StepCode, si.StepType, si.Status AS StepStatus,
        si.AssignPolicy, si.RequiredApprovals, si.ReceivedApprovals, si.ReceivedRejections,
        si.FirstOpenedAt, si.CompletedAt, si.OutcomeActionCode, si.RowVersion,
        s.StepID, s.Name AS StepName, s.AllowForward, s.AllowDelegation, s.ForwardMax,
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
    WHERE si.StepInstanceID = @CurrentStepInstanceID;

    -- انجام‌دهنده‌ها + وضعیتِ کارتابلِ شخصی + نامِ forwarder/delegator
    SELECT a.TaskAssigneeID, a.StepInstanceID, a.UserID, u.FullName, a.SourceType, a.SourceRefID,
           ref.FullName AS SourceRefName, a.IsActive,
           a.Decision, a.ActionCode, a.ActedAt, a.Comment,
           a.Date_InsertFirst AS AssignedAt,
           md.MessageStatusID AS PersonalStatusID, ms.MessageStatusName AS PersonalStatusName
    FROM dbo.WorkflowTaskAssignees a
    JOIN dbo.WorkflowStepInstances si ON si.StepInstanceID = a.StepInstanceID
    JOIN dbo.Users u                  ON u.UserID = a.UserID
    LEFT JOIN dbo.Users ref           ON ref.UserID = a.SourceRefID AND a.SourceType IN (N'FORWARD', N'DELEGATION')
    LEFT JOIN dbo.MessageDetails md   ON md.MessageID = si.MessageID AND md.ToUserID = a.UserID
    LEFT JOIN dbo.MessageStatuses ms  ON ms.MessageStatusID = md.MessageStatusID
    WHERE si.StepInstanceID = @CurrentStepInstanceID
    ORDER BY a.IsActive DESC, a.TaskAssigneeID;

    -- Actionهای مرحله
    SELECT act.ActionID, act.Code, act.Kind, act.Label, act.Icon, act.Style,
           act.RequiresComment, act.RequiresConfirm, act.ConfirmMessage, act.PermissionCode, act.SortOrder
    FROM dbo.WorkflowStepActions act
    JOIN dbo.WorkflowStepInstances si ON si.StepID = act.StepID
    WHERE si.StepInstanceID = @CurrentStepInstanceID
    ORDER BY act.SortOrder, act.ActionID;

    -- تاریخچهٔ کاملِ Instance (نه فقط رویدادهایِ همین Step) — دقیقاً همان زنجیره‌ای که
    -- sp_Wf_GetInstanceHistory برمی‌گرداند، چون رویدادهایِ سطحِ Instance (شروع/گذار/پایان)
    -- MessageID یا StepInstanceID ندارند.
    DECLARE @InstanceID BIGINT = (
        SELECT TOP 1 si2.InstanceID
        FROM dbo.WorkflowStepInstances si2
        WHERE si2.MessageID = @MessageID
    );

    SELECT h.HistoryID, h.EventCode, h.ActorType, h.OccurredAt, h.Summary, h.DetailJson, h.ActorUserID, u.FullName AS ActorName
    FROM dbo.WorkflowHistory h
    LEFT JOIN dbo.Users u ON u.UserID = h.ActorUserID
    WHERE h.InstanceID = @InstanceID
    ORDER BY h.OccurredAt, h.HistoryID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_ReassignStepTask
/* =====================================================================
   sp_Wf_ReassignStepTask  —  FORWARD (دائمی) یا DELEGATE (موقت)
   ===================================================================== */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_ReassignStepTask
    @MessageID           INT,
    @ActorUserID         INT,            -- assigneeِ فعالِ فعلی
    @TargetUserID        INT,            -- دارندهٔ جدید
    @Mode                NVARCHAR(10),   -- 'FORWARD' | 'DELEGATE'
    @Comment             NVARCHAR(1000) = NULL,
    @ExpectedRowVersion  NVARCHAR(34) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Mode NOT IN (N'FORWARD', N'DELEGATE')
    BEGIN SELECT 0 AS Success, CAST(0 AS BIT) AS Conflict, N'حالتِ نامعتبر.' AS Message; RETURN; END

    DECLARE @StepInstanceID BIGINT, @InstanceID BIGINT, @StepID INT,
            @StepStatus NVARCHAR(20), @InstanceStatus NVARCHAR(20),
            @EntityType NVARCHAR(64), @EntityID BIGINT;

    SELECT TOP 1 @StepInstanceID = si.StepInstanceID, @InstanceID = si.InstanceID, @StepID = si.StepID,
           @StepStatus = si.Status, @InstanceStatus = i.Status,
           @EntityType = i.EntityType, @EntityID = i.EntityID
    FROM dbo.WorkflowStepInstances si
    JOIN dbo.WorkflowInstances i ON i.InstanceID = si.InstanceID
    WHERE si.MessageID = @MessageID
    ORDER BY si.StepInstanceID DESC;

    IF @StepInstanceID IS NULL
    BEGIN SELECT 0 AS Success, CAST(0 AS BIT) AS Conflict, N'تسکِ فرایند یافت نشد.' AS Message; RETURN; END
    IF @InstanceStatus <> N'RUNNING'
    BEGIN SELECT 0 AS Success, CAST(1 AS BIT) AS Conflict, N'فرایندِ این تسک در جریان نیست.' AS Message; RETURN; END
    IF @StepStatus <> N'ACTIVE'
    BEGIN SELECT 0 AS Success, CAST(1 AS BIT) AS Conflict, N'این تسک بسته شده است.' AS Message; RETURN; END

    DECLARE @AllowForward BIT, @AllowDelegation BIT, @ForwardMax INT;
    SELECT @AllowForward = s.AllowForward, @AllowDelegation = s.AllowDelegation, @ForwardMax = s.ForwardMax
    FROM dbo.WorkflowSteps s WHERE s.StepID = @StepID;

    IF @Mode = N'FORWARD'  AND ISNULL(@AllowForward, 0) = 0
    BEGIN SELECT 0 AS Success, CAST(0 AS BIT) AS Conflict, N'ارجاع برای این مرحله مجاز نیست.' AS Message; RETURN; END
    IF @Mode = N'DELEGATE' AND ISNULL(@AllowDelegation, 1) = 0
    BEGIN SELECT 0 AS Success, CAST(0 AS BIT) AS Conflict, N'تفویض برای این مرحله مجاز نیست.' AS Message; RETURN; END

    -- Actor باید assigneeِ فعالِ un-decided باشد
    DECLARE @ActorSource NVARCHAR(30) = (
        SELECT SourceType FROM dbo.WorkflowTaskAssignees
        WHERE StepInstanceID = @StepInstanceID AND UserID = @ActorUserID AND IsActive = 1 AND Decision IS NULL
    );
    IF @ActorSource IS NULL
    BEGIN SELECT 0 AS Success, CAST(1 AS BIT) AS Conflict, N'شما انجام‌دهندهٔ فعالِ این تسک نیستید یا قبلاً تصمیم گرفته‌اید.' AS Message; RETURN; END

    -- نماینده (delegate) حق ارجاع/تفویض ندارد
    IF @ActorSource = N'DELEGATION'
    BEGIN SELECT 0 AS Success, CAST(0 AS BIT) AS Conflict, N'نمایندهٔ یک تسک نمی‌تواند آن را ارجاع یا تفویض کند.' AS Message; RETURN; END

    -- مقصد
    IF @TargetUserID = @ActorUserID
    BEGIN SELECT 0 AS Success, CAST(0 AS BIT) AS Conflict, N'نمی‌توانید به خودتان ارجاع/تفویض دهید.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @TargetUserID AND IsActive = 1)
    BEGIN SELECT 0 AS Success, CAST(0 AS BIT) AS Conflict, N'کاربرِ مقصد نامعتبر یا غیرفعال است.' AS Message; RETURN; END
    IF EXISTS (SELECT 1 FROM dbo.WorkflowTaskAssignees WHERE StepInstanceID = @StepInstanceID AND UserID = @TargetUserID AND IsActive = 1)
    BEGIN SELECT 0 AS Success, CAST(0 AS BIT) AS Conflict, N'کاربرِ مقصد از قبل انجام‌دهندهٔ فعالِ این مرحله است.' AS Message; RETURN; END

    -- سقفِ ارجاع
    DECLARE @ForwardCount INT = (SELECT COUNT(*) FROM dbo.WorkflowTaskAssignees WHERE StepInstanceID = @StepInstanceID AND SourceType = N'FORWARD');
    IF @Mode = N'FORWARD' AND @ForwardMax IS NOT NULL AND @ForwardCount >= @ForwardMax
    BEGIN SELECT 0 AS Success, CAST(0 AS BIT) AS Conflict, N'به سقفِ مجازِ ارجاع رسیده‌اید.' AS Message; RETURN; END

    -- RowVersion اختیاری (StepInstance)
    DECLARE @rv BINARY(8) = NULL;
    BEGIN TRY
        IF @ExpectedRowVersion IS NOT NULL AND LEN(@ExpectedRowVersion) > 0
            SET @rv = CONVERT(BINARY(8), @ExpectedRowVersion, 1);
    END TRY BEGIN CATCH SET @rv = NULL; END CATCH
    IF @rv IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.WorkflowStepInstances WHERE StepInstanceID = @StepInstanceID AND RowVersion = @rv)
    BEGIN SELECT 0 AS Success, CAST(1 AS BIT) AS Conflict, N'این تسک هم‌زمان تغییر کرده است. صفحه را تازه کنید.' AS Message; RETURN; END

    DECLARE @WontDoID INT = (SELECT MessageStatusID FROM dbo.MessageStatuses WHERE MessageStatusName = N'انجام نخواهد شد');
    DECLARE @SentID   INT = (SELECT MessageStatusID FROM dbo.MessageStatuses WHERE MessageStatusName = N'ارسال شده');
    DECLARE @NewSourceType NVARCHAR(30) = CASE WHEN @Mode = N'FORWARD' THEN N'FORWARD' ELSE N'DELEGATION' END;

    BEGIN TRY
        BEGIN TRAN;

        -- CAS: غیرفعال‌کردنِ رکوردِ Actor فقط اگر هنوز فعال و بی‌تصمیم است
        UPDATE dbo.WorkflowTaskAssignees
        SET IsActive = 0, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ActorUserID
        WHERE StepInstanceID = @StepInstanceID AND UserID = @ActorUserID AND IsActive = 1 AND Decision IS NULL;

        IF @@ROWCOUNT = 0
        BEGIN
            ROLLBACK;
            SELECT 0 AS Success, CAST(1 AS BIT) AS Conflict, N'وضعیتِ تسک هم‌زمان تغییر کرد.' AS Message;
            RETURN;
        END

        -- رکوردِ assigneeِ جدید (همیشه رکوردِ تازه — تاریخِ قبلی حفظ می‌شود)
        INSERT INTO dbo.WorkflowTaskAssignees (StepInstanceID, UserID, SourceType, SourceRefID, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@StepInstanceID, @TargetUserID, @NewSourceType, @ActorUserID, 1, SYSDATETIME(), @ActorUserID);

        -- کارتابلِ Actor → 6  (Description فقط وقتی @Comment مقدار دارد بازنویسی می‌شود)
        UPDATE md
        SET md.MessageStatusID = @WontDoID,
            md.Description = CASE WHEN @Comment IS NOT NULL THEN @Comment ELSE md.Description END,
            md.CreateDate = GETDATE(), md.CreateUser = @ActorUserID
        FROM dbo.MessageDetails md
        JOIN dbo.MessageStatuses ms ON ms.MessageStatusID = md.MessageStatusID
        WHERE md.MessageID = @MessageID AND md.ToUserID = @ActorUserID
          AND ms.MessageStatusName NOT IN (N'انجام شده', N'انجام نخواهد شد');

        -- ردیفِ کارتابلِ جدید برای مقصد → 1
        INSERT INTO dbo.MessageDetails (RowGuid, MessageID, FromUserID, ToUserID, MessageStatusID, Description, CreateDate, CreateUser)
        VALUES (NEWID(), @MessageID, @ActorUserID, @TargetUserID, @SentID, @Comment, GETDATE(), @ActorUserID);

        INSERT INTO dbo.UserNotifications (UserID, MessageID, CreateDate, IsRead)
        VALUES (@TargetUserID, @MessageID, GETDATE(), 0);

        -- History
        DECLARE @EventCode NVARCHAR(50) = CASE WHEN @Mode = N'FORWARD' THEN N'TASK_FORWARDED' ELSE N'TASK_DELEGATED' END;
        DECLARE @Detail NVARCHAR(MAX) =
            CASE WHEN @Mode = N'FORWARD' THEN
                (SELECT @ActorUserID AS fromUserId, @TargetUserID AS toUserId, @StepInstanceID AS stepInstanceId,
                        @Comment AS reason, (@ForwardCount + 1) AS forwardIndex
                 FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)
            ELSE
                (SELECT @ActorUserID AS delegatorUserId, @TargetUserID AS delegateUserId, @StepInstanceID AS stepInstanceId,
                        @Comment AS reason
                 FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)
            END;

        INSERT INTO dbo.WorkflowHistory (InstanceID, StepInstanceID, MessageID, EntityType, EntityID, EventCode, ActorUserID, ActorType, OccurredAt, Summary, DetailJson)
        VALUES (@InstanceID, @StepInstanceID, @MessageID, @EntityType, @EntityID, @EventCode, @ActorUserID, N'USER', SYSDATETIME(),
                CASE WHEN @Mode = N'FORWARD' THEN N'تسک ارجاع شد' ELSE N'تسک تفویض شد' END + ISNULL(N' — ' + @Comment, N''),
                @Detail);

        COMMIT;
        SELECT 1 AS Success, CAST(0 AS BIT) AS Conflict,
               CASE WHEN @Mode = N'FORWARD' THEN N'تسک ارجاع شد.' ELSE N'تسک تفویض شد.' END AS Message,
               @StepInstanceID AS StepInstanceID, @InstanceID AS InstanceID;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK;
        SELECT 0 AS Success, CAST(0 AS BIT) AS Conflict, N'خطا در ارجاع/تفویض: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_RevokeDelegation
/* =====================================================================
   sp_Wf_RevokeDelegation  —  بازپس‌گیریِ تفویض (فقط اگر نماینده هنوز تصمیم نگرفته)
   ===================================================================== */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_RevokeDelegation
    @MessageID       INT,
    @DelegateUserID  INT,
    @RevokedByUserID INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @StepInstanceID BIGINT, @InstanceID BIGINT, @StepStatus NVARCHAR(20), @InstanceStatus NVARCHAR(20),
            @EntityType NVARCHAR(64), @EntityID BIGINT;

    SELECT TOP 1 @StepInstanceID = si.StepInstanceID, @InstanceID = si.InstanceID,
           @StepStatus = si.Status, @InstanceStatus = i.Status,
           @EntityType = i.EntityType, @EntityID = i.EntityID
    FROM dbo.WorkflowStepInstances si
    JOIN dbo.WorkflowInstances i ON i.InstanceID = si.InstanceID
    WHERE si.MessageID = @MessageID
    ORDER BY si.StepInstanceID DESC;

    IF @StepInstanceID IS NULL
    BEGIN SELECT 0 AS Success, CAST(0 AS BIT) AS Conflict, N'تسکِ فرایند یافت نشد.' AS Message; RETURN; END
    IF @InstanceStatus <> N'RUNNING'
    BEGIN SELECT 0 AS Success, CAST(1 AS BIT) AS Conflict, N'فرایندِ این تسک در جریان نیست.' AS Message; RETURN; END
    IF @StepStatus <> N'ACTIVE'
    BEGIN SELECT 0 AS Success, CAST(1 AS BIT) AS Conflict, N'این تسک بسته شده است.' AS Message; RETURN; END

    DECLARE @DelegatorUserID INT = (
        SELECT SourceRefID FROM dbo.WorkflowTaskAssignees
        WHERE StepInstanceID = @StepInstanceID AND UserID = @DelegateUserID
          AND SourceType = N'DELEGATION' AND IsActive = 1 AND Decision IS NULL
    );

    IF @DelegatorUserID IS NULL
    BEGIN
        IF EXISTS (SELECT 1 FROM dbo.WorkflowTaskAssignees
                   WHERE StepInstanceID = @StepInstanceID AND UserID = @DelegateUserID
                     AND SourceType = N'DELEGATION' AND IsActive = 1 AND Decision IS NOT NULL)
            SELECT 0 AS Success, CAST(1 AS BIT) AS Conflict, N'نماینده قبلاً تصمیم گرفته است؛ باطل‌سازی ممکن نیست.' AS Message;
        ELSE
            SELECT 0 AS Success, CAST(0 AS BIT) AS Conflict, N'تفویضِ فعالی برای این کاربر روی این تسک یافت نشد.' AS Message;
        RETURN;
    END

    DECLARE @WontDoID INT = (SELECT MessageStatusID FROM dbo.MessageStatuses WHERE MessageStatusName = N'انجام نخواهد شد');
    DECLARE @SentID   INT = (SELECT MessageStatusID FROM dbo.MessageStatuses WHERE MessageStatusName = N'ارسال شده');

    BEGIN TRY
        BEGIN TRAN;

        UPDATE dbo.WorkflowTaskAssignees
        SET IsActive = 0, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @RevokedByUserID
        WHERE StepInstanceID = @StepInstanceID AND UserID = @DelegateUserID
          AND SourceType = N'DELEGATION' AND IsActive = 1 AND Decision IS NULL;

        IF @@ROWCOUNT = 0
        BEGIN
            ROLLBACK;
            SELECT 0 AS Success, CAST(1 AS BIT) AS Conflict, N'وضعیتِ تفویض هم‌زمان تغییر کرد.' AS Message;
            RETURN;
        END

        -- بازفعال‌سازیِ واگذارکننده — فقط دقیق‌ترین (جدیدترین) رکوردِ inactiveِ بی‌تصمیمِ او،
        -- تا هیچ‌گاه چند رکورد هم‌زمان IsActive=1 نشوند (نقضِ UX_..._Step_User_Active).
        UPDATE a
        SET a.IsActive = 1, a.Date_LastUpdate = SYSDATETIME(), a.UserID_LastUpdate = @RevokedByUserID
        FROM dbo.WorkflowTaskAssignees a
        WHERE a.TaskAssigneeID = (
            SELECT TOP (1) a2.TaskAssigneeID
            FROM dbo.WorkflowTaskAssignees a2
            WHERE a2.StepInstanceID = @StepInstanceID
              AND a2.UserID = @DelegatorUserID
              AND a2.IsActive = 0
              AND a2.Decision IS NULL
            ORDER BY a2.TaskAssigneeID DESC
        );

        -- کارتابلِ نماینده → 6
        UPDATE md
        SET md.MessageStatusID = @WontDoID, md.CreateDate = GETDATE(), md.CreateUser = @RevokedByUserID
        FROM dbo.MessageDetails md
        JOIN dbo.MessageStatuses ms ON ms.MessageStatusID = md.MessageStatusID
        WHERE md.MessageID = @MessageID AND md.ToUserID = @DelegateUserID
          AND ms.MessageStatusName NOT IN (N'انجام شده', N'انجام نخواهد شد');

        -- آخرین ردیفِ کارتابلِ واگذارکننده (که هنگام تفویض به 6 رفته بود) → 1
        DECLARE @DelegatorMdID INT = (
            SELECT TOP 1 MessageDetailID FROM dbo.MessageDetails
            WHERE MessageID = @MessageID AND ToUserID = @DelegatorUserID
            ORDER BY CreateDate DESC, MessageDetailID DESC
        );
        IF @DelegatorMdID IS NOT NULL
            UPDATE dbo.MessageDetails
            SET MessageStatusID = @SentID, CreateDate = GETDATE(), CreateUser = @RevokedByUserID
            WHERE MessageDetailID = @DelegatorMdID;
        ELSE
            INSERT INTO dbo.MessageDetails (RowGuid, MessageID, FromUserID, ToUserID, MessageStatusID, CreateDate, CreateUser)
            VALUES (NEWID(), @MessageID, @RevokedByUserID, @DelegatorUserID, @SentID, GETDATE(), @RevokedByUserID);

        INSERT INTO dbo.UserNotifications (UserID, MessageID, CreateDate, IsRead)
        VALUES (@DelegatorUserID, @MessageID, GETDATE(), 0);

        DECLARE @Detail NVARCHAR(MAX) = (
            SELECT @DelegatorUserID AS delegatorUserId, @DelegateUserID AS delegateUserId,
                   @RevokedByUserID AS revokedByUserId, @StepInstanceID AS stepInstanceId
            FOR JSON PATH, WITHOUT_ARRAY_WRAPPER
        );

        INSERT INTO dbo.WorkflowHistory (InstanceID, StepInstanceID, MessageID, EntityType, EntityID, EventCode, ActorUserID, ActorType, OccurredAt, Summary, DetailJson)
        VALUES (@InstanceID, @StepInstanceID, @MessageID, @EntityType, @EntityID, N'TASK_DELEGATION_REVOKED', @RevokedByUserID, N'USER', SYSDATETIME(),
                N'تفویض باطل شد', @Detail);

        COMMIT;
        SELECT 1 AS Success, CAST(0 AS BIT) AS Conflict, N'تفویض باطل شد.' AS Message,
               @DelegatorUserID AS DelegatorUserID, @StepInstanceID AS StepInstanceID;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK;
        SELECT 0 AS Success, CAST(0 AS BIT) AS Conflict, N'خطا در باطل‌سازیِ تفویض: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

