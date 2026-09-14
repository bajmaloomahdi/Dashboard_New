/* ==========================================================================
   پچ 019 | Workflow — Forward & Delegation  (Step 7.2)

   تصمیم‌های تأییدشده:
   - دو SP جدید: sp_Wf_ReassignStepTask (FORWARD|DELEGATE) و sp_Wf_RevokeDelegation
   - هیچ جدول/ستون/Index/Constraint جدید. هیچ تغییرِ MessageStatuses.
   - MessageID و StepInstanceID ثابت می‌مانند. هیچ child task. هیچ MessageID دوم.
   - هر Forward/Delegation یک رکوردِ *جدیدِ* WorkflowTaskAssignees می‌سازد
     (رکوردِ غیرفعالِ قبلی reuse یا بازفعال نمی‌شود). تنها ضمانت:
       UX (StepInstanceID, UserID) WHERE IsActive=1  →  یک رکوردِ فعال per کاربر.
   - وضعیتِ کارتابل: assignee قبلی → MessageStatusID=6 ، دارندهٔ جدید → 1.
   - وضعیتِ حقیقیِ Workflow فقط در WorkflowStepInstances / WorkflowTaskAssignees /
     WorkflowHistory می‌ماند.
   - همچنین: sp_Wf_CancelInstance رکوردهای assigneeِ فعالِ مراحلِ فعال را IsActive=0
     می‌کند (بدونِ دست‌زدن به Decision) ، و sp_Wf_GetStepTaskDetail نامِ
     forwarder/delegator را هم برمی‌گرداند (بدونِ شکستنِ قرارداد).
   ========================================================================== */

SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;
GO

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

    SELECT @StepInstanceID = si.StepInstanceID, @InstanceID = si.InstanceID, @StepID = si.StepID,
           @StepStatus = si.Status, @InstanceStatus = i.Status,
           @EntityType = i.EntityType, @EntityID = i.EntityID
    FROM dbo.WorkflowStepInstances si
    JOIN dbo.WorkflowInstances i ON i.InstanceID = si.InstanceID
    WHERE si.MessageID = @MessageID;

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

    SELECT @StepInstanceID = si.StepInstanceID, @InstanceID = si.InstanceID,
           @StepStatus = si.Status, @InstanceStatus = i.Status,
           @EntityType = i.EntityType, @EntityID = i.EntityID
    FROM dbo.WorkflowStepInstances si
    JOIN dbo.WorkflowInstances i ON i.InstanceID = si.InstanceID
    WHERE si.MessageID = @MessageID;

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


/* =====================================================================
   sp_Wf_CancelInstance  —  علاوه بر بستنِ کارتابل، assigneeهای فعال را غیرفعال کن
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

    -- assigneeهای فعالِ مراحلِ فعال → IsActive=0  (بدونِ دست‌زدن به Decision)
    UPDATE a
    SET a.IsActive = 0, a.Date_LastUpdate = SYSDATETIME(), a.UserID_LastUpdate = @ActorUserID
    FROM dbo.WorkflowTaskAssignees a
    JOIN dbo.WorkflowStepInstances si ON si.StepInstanceID = a.StepInstanceID
    WHERE si.InstanceID = @InstanceID AND si.Status = N'ACTIVE' AND a.IsActive = 1;

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


/* =====================================================================
   sp_Wf_GetStepTaskDetail  —  + نامِ forwarder/delegator (SourceRefName)
   ===================================================================== */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetStepTaskDetail
    @MessageID INT
AS
BEGIN
    SET NOCOUNT ON;

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
    WHERE si.MessageID = @MessageID;

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

/* ==================== پایان پچ 019 ==================== */
