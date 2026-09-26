/* ==========================================================================
   پچ خودکار شماره: 036 | نام: condition_engine_time_support_and_history_fix
   تاریخ: 2026-09-19 10:28:07 | شامل 13 دستور SQL
   ========================================================================== */

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

-- [ALTER_TABLE] روی TABLE: TemplateParameters
ALTER TABLE dbo.TemplateParameters DROP CONSTRAINT CK_TemplateParameters_DataType
GO

-- [ALTER_TABLE] روی TABLE: TemplateParameters
ALTER TABLE dbo.TemplateParameters
    ADD CONSTRAINT CK_TemplateParameters_DataType
    CHECK ([DataType]=N'DECIMAL' OR [DataType]=N'INTEGER' OR [DataType]=N'DATE' OR [DataType]=N'TIME' OR [DataType]=N'STRING')
GO

-- [ALTER_TABLE] روی TABLE: WorkflowConditionFields
ALTER TABLE dbo.WorkflowConditionFields DROP CONSTRAINT UQ_WfConditionFields_Def_Code
GO

-- [DROP_INDEX] روی INDEX: IX_WorkflowConditionFields_DefinitionID
DROP INDEX IX_WorkflowConditionFields_DefinitionID ON dbo.WorkflowConditionFields
GO

-- [ALTER_TABLE] روی TABLE: WorkflowConditionFields
ALTER TABLE dbo.WorkflowConditionFields DROP CONSTRAINT FK_WfConditionFields_Definition
GO

-- [ALTER_TABLE] روی TABLE: WorkflowConditionFields
ALTER TABLE dbo.WorkflowConditionFields DROP COLUMN DefinitionID
GO

-- [ALTER_TABLE] روی TABLE: WorkflowConditionFields
ALTER TABLE dbo.WorkflowConditionFields ADD CONSTRAINT UQ_WfConditionFields_Code UNIQUE (Code)
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_GetConditionFields
CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetConditionFields
    @IncludeInactive  BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    SELECT FieldID, Code, DisplayName, DataType, SourceType, SourceKey,
           AllowedValuesJson, SortOrder, IsActive
    FROM dbo.WorkflowConditionFields
    WHERE (@IncludeInactive = 1 OR IsActive = 1)
    ORDER BY SortOrder, Code;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_SaveConditionField
/* Global Registry (Phase 2) — Code اکنون سراسری یکتاست (نه به‌ازای هر Definition).
   Guardِ Immutabilityِ Code اکنون در سطحِ کلِ سیستم بررسی می‌شود (نه فقط یک Definition)،
   چون همان Code می‌تواند در RuleJsonِ چند Definitionِ مختلف استفاده شده باشد. */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_SaveConditionField
    @FieldID            INT = NULL,   -- NULL = ایجاد
    @Code                NVARCHAR(50),
    @DisplayName         NVARCHAR(200),
    @DataType            NVARCHAR(20),
    @SourceType          NVARCHAR(20),
    @SourceKey           NVARCHAR(100),
    @AllowedValuesJson   NVARCHAR(MAX) = NULL,
    @SortOrder           INT = 0,
    @UserID              INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@Code)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'کد فیلد الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نام نمایشی الزامی است.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.WorkflowConditionFields
               WHERE Code = @Code AND (@FieldID IS NULL OR FieldID <> @FieldID))
    BEGIN SELECT 0 AS Success, N'این کد قبلاً استفاده شده است.' AS Message; RETURN; END

    IF @FieldID IS NULL
    BEGIN
        INSERT INTO dbo.WorkflowConditionFields
            (Code, DisplayName, DataType, SourceType, SourceKey, AllowedValuesJson, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES
            (@Code, @DisplayName, @DataType, @SourceType, @SourceKey, @AllowedValuesJson, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'فیلد ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS FieldID;
    END
    ELSE
    BEGIN
        DECLARE @OldCode NVARCHAR(50);
        SELECT @OldCode = Code FROM dbo.WorkflowConditionFields WHERE FieldID = @FieldID;

        IF @OldCode IS NULL
        BEGIN SELECT 0 AS Success, N'فیلد یافت نشد.' AS Message; RETURN; END

        -- Guardِ Immutabilityِ Code — سراسری (بدونِ فیلترِ DefinitionID): هر Codeای که در
        -- RuleJsonِ هر Transitionی، در هر Definition/Version/Statusی (حتی Archived)
        -- استفاده شده باشد، دیگر قابلِ تغییرِ نام نیست.
        IF @OldCode <> @Code
        BEGIN
            IF EXISTS (
                SELECT 1
                FROM dbo.WorkflowTransitions t
                CROSS APPLY dbo.fn_Wf_RuleJsonFieldCodes(t.RuleJson) f
                WHERE t.RuleJson IS NOT NULL
                  AND f.FieldCode = @OldCode
            )
            BEGIN
                SELECT 0 AS Success, N'این فیلد در یک یا چند Rule استفاده شده است؛ Code آن قابل تغییر نیست.' AS Message;
                RETURN;
            END
        END

        UPDATE dbo.WorkflowConditionFields
        SET Code = @Code, DisplayName = @DisplayName, DataType = @DataType, SourceType = @SourceType,
            SourceKey = @SourceKey, AllowedValuesJson = @AllowedValuesJson, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE FieldID = @FieldID;

        SELECT 1 AS Success, N'فیلد ویرایش شد.' AS Message, @FieldID AS FieldID;
    END
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_ToggleConditionFieldActive
CREATE OR ALTER PROCEDURE dbo.sp_Wf_ToggleConditionFieldActive
    @FieldID INT,
    @UserID  INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.WorkflowConditionFields WHERE FieldID = @FieldID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'فیلد یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.WorkflowConditionFields
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(),
        UserID_LastUpdate = @UserID
    WHERE FieldID = @FieldID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'فیلد غیرفعال شد.' ELSE N'فیلد فعال شد.' END AS Message;
END
GO

-- [ALTER_TABLE] روی TABLE: WorkflowConditionFields
ALTER TABLE dbo.WorkflowConditionFields DROP CONSTRAINT CK_WfConditionFields_DataType
GO

-- [ALTER_TABLE] روی TABLE: WorkflowConditionFields
ALTER TABLE dbo.WorkflowConditionFields
    ADD CONSTRAINT CK_WfConditionFields_DataType
    CHECK ([DataType]=N'UNIT' OR [DataType]=N'USER' OR [DataType]=N'SELECT' OR [DataType]=N'BOOLEAN' OR [DataType]=N'DATE' OR [DataType]=N'TIME' OR [DataType]=N'DECIMAL' OR [DataType]=N'INTEGER' OR [DataType]=N'STRING')
GO

