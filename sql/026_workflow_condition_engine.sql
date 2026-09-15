/* ==========================================================================
   پچ خودکار شماره: 026 | نام: workflow_condition_engine
   تاریخ: 2026-09-14 19:39:40 | شامل 14 دستور SQL
   ========================================================================== */

-- هم‌الگو با patch 015: یک‌بار در ابتدایِ اسکریپت، برایِ کلِ Sessionِ اجرا (تا پایانِ
-- فایل) اعمال می‌شود — بدونِ این‌، CREATE/ALTER PROCEDURE با uses_quoted_identifier=0
-- ذخیره می‌شوند و بعداً DML رویِ جدول‌هایِ دارایِ CHECKِ ISJSON (مثلِ WorkflowTransitions)
-- را با خطایِ «SET options have incorrect settings» رد می‌کنند.
SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;
GO

-- [CREATE_TABLE] روی TABLE: WorkflowConditionFields
CREATE TABLE dbo.WorkflowConditionFields ( FieldID INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_WorkflowConditionFields PRIMARY KEY, DefinitionID INT NOT NULL CONSTRAINT FK_WfConditionFields_Definition REFERENCES dbo.WorkflowDefinitions (DefinitionID), Code NVARCHAR(50) NOT NULL, DisplayName NVARCHAR(200) NOT NULL, DataType NVARCHAR(20) NOT NULL, SourceType NVARCHAR(20) NOT NULL, SourceKey NVARCHAR(100) NOT NULL, AllowedValuesJson NVARCHAR(MAX) NULL, SortOrder INT NOT NULL CONSTRAINT DF_WfConditionFields_SortOrder DEFAULT (0), IsActive BIT NOT NULL CONSTRAINT DF_WfConditionFields_IsActive DEFAULT (1), RowGuid UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_WfConditionFields_RowGuid DEFAULT (NEWID()), Date_InsertFirst DATETIME2 NOT NULL CONSTRAINT DF_WfConditionFields_DateInsert DEFAULT (SYSDATETIME()), UserID_InsertFirst INT NULL, Date_LastUpdate DATETIME2 NULL, UserID_LastUpdate INT NULL, CONSTRAINT UQ_WfConditionFields_Def_Code UNIQUE (DefinitionID, Code), CONSTRAINT CK_WfConditionFields_DataType CHECK (DataType IN (N'STRING', N'INTEGER', N'DECIMAL', N'DATE', N'BOOLEAN', N'SELECT', N'USER', N'UNIT')), CONSTRAINT CK_WfConditionFields_SourceType CHECK (SourceType IN (N'START_CONTEXT')), CONSTRAINT CK_WfConditionFields_AllowedValuesJson CHECK (AllowedValuesJson IS NULL OR ISJSON(AllowedValuesJson) = 1) )
GO

-- [CREATE_INDEX] روی INDEX: IX_WorkflowConditionFields_DefinitionID
CREATE INDEX IX_WorkflowConditionFields_DefinitionID ON dbo.WorkflowConditionFields (DefinitionID)
GO

-- [ALTER_TABLE] روی TABLE: WorkflowTransitions
ALTER TABLE dbo.WorkflowTransitions ADD RuleJson NVARCHAR(MAX) NULL
GO

-- [ALTER_TABLE] روی TABLE: WorkflowTransitions
ALTER TABLE dbo.WorkflowTransitions ADD CONSTRAINT CK_WfTransitions_RuleJson CHECK (RuleJson IS NULL OR ISJSON(RuleJson) = 1)
GO

-- [ALTER_TABLE] روی TABLE: WorkflowInstances
ALTER TABLE dbo.WorkflowInstances ADD ContextJson NVARCHAR(MAX) NULL
GO

-- [ALTER_TABLE] روی TABLE: WorkflowInstances
ALTER TABLE dbo.WorkflowInstances ADD CONSTRAINT CK_WfInstances_ContextJson CHECK (ContextJson IS NULL OR ISJSON(ContextJson) = 1)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Wf_GetConditionFields
CREATE PROCEDURE dbo.sp_Wf_GetConditionFields
    @DefinitionID     INT,
    @IncludeInactive  BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    SELECT FieldID, DefinitionID, Code, DisplayName, DataType, SourceType, SourceKey,
           AllowedValuesJson, SortOrder, IsActive
    FROM dbo.WorkflowConditionFields
    WHERE DefinitionID = @DefinitionID
      AND (@IncludeInactive = 1 OR IsActive = 1)
    ORDER BY SortOrder, Code;
END
GO

-- [CREATE_FUNCTION] روی FUNCTION: fn_Wf_RuleJsonFieldCodes
-- استخراجِ همهٔ Codeهایِ فیلدِ ارجاع‌شده (Property «field» در گره‌هایِ CONDITION) از
-- یک RuleJson — با یک Recursive CTE + OPENJSON، مستقل از Whitespace/Formatting و
-- بدونِ برخورد با مقادیرِ دیگر (مثلِ value.data) که تصادفاً همین متن را داشته باشند.
-- سقفِ عمقِ ۶ (هم‌راستا با MAX_DEPTH=5 در ConditionRuleValidatorِ PHP) از بازگشتِ
-- بی‌پایان جلوگیری می‌کند.
CREATE FUNCTION dbo.fn_Wf_RuleJsonFieldCodes (@RuleJson NVARCHAR(MAX))
RETURNS TABLE
AS
RETURN (
    WITH Nodes AS (
        SELECT JSON_VALUE(@RuleJson, '$.type') AS NodeType,
               JSON_VALUE(@RuleJson, '$.field') AS FieldCode,
               JSON_QUERY(@RuleJson, '$.children') AS ChildrenJson,
               1 AS Depth
        UNION ALL
        SELECT JSON_VALUE(c.[value], '$.type'),
               JSON_VALUE(c.[value], '$.field'),
               JSON_QUERY(c.[value], '$.children'),
               n.Depth + 1
        FROM Nodes n
        CROSS APPLY OPENJSON(n.ChildrenJson) c
        WHERE n.ChildrenJson IS NOT NULL AND n.Depth < 6
    )
    SELECT FieldCode FROM Nodes WHERE NodeType = N'CONDITION' AND FieldCode IS NOT NULL
)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Wf_SaveConditionField
CREATE PROCEDURE dbo.sp_Wf_SaveConditionField
    @FieldID            INT = NULL,
    @DefinitionID        INT,
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
    IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowDefinitions WHERE DefinitionID = @DefinitionID)
    BEGIN SELECT 0 AS Success, N'فرایند یافت نشد.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.WorkflowConditionFields
               WHERE DefinitionID = @DefinitionID AND Code = @Code AND (@FieldID IS NULL OR FieldID <> @FieldID))
    BEGIN SELECT 0 AS Success, N'این کد در همین فرایند قبلاً استفاده شده است.' AS Message; RETURN; END

    IF @FieldID IS NULL
    BEGIN
        INSERT INTO dbo.WorkflowConditionFields
            (DefinitionID, Code, DisplayName, DataType, SourceType, SourceKey, AllowedValuesJson, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES
            (@DefinitionID, @Code, @DisplayName, @DataType, @SourceType, @SourceKey, @AllowedValuesJson, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'فیلد ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS FieldID;
    END
    ELSE
    BEGIN
        DECLARE @OldCode NVARCHAR(50);
        SELECT @OldCode = Code FROM dbo.WorkflowConditionFields WHERE FieldID = @FieldID AND DefinitionID = @DefinitionID;

        IF @OldCode IS NULL
        BEGIN SELECT 0 AS Success, N'فیلد یافت نشد.' AS Message; RETURN; END

        -- Guardِ Immutabilityِ Code در سطحِ DB — JSON-aware (نه Textِ خام): با
        -- dbo.fn_Wf_RuleJsonFieldCodes واقعاً درختِ RuleJson پیمایش می‌شود و فقط
        -- Propertyِ «field» در گره‌هایِ CONDITION استخراج می‌شود — مستقل از Whitespace/
        -- Formatting، بدونِ برخورد با مقادیرِ دیگر، و شاملِ همهٔ نسخه‌ها (DRAFT/ACTIVE/
        -- ARCHIVED) بدونِ فیلترِ Status.
        IF @OldCode <> @Code
        BEGIN
            IF EXISTS (
                SELECT 1
                FROM dbo.WorkflowTransitions t
                JOIN dbo.WorkflowSteps s ON s.StepID = t.FromStepID
                JOIN dbo.WorkflowVersions v ON v.VersionID = s.VersionID
                CROSS APPLY dbo.fn_Wf_RuleJsonFieldCodes(t.RuleJson) f
                WHERE v.DefinitionID = @DefinitionID
                  AND t.RuleJson IS NOT NULL
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

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Wf_ToggleConditionFieldActive
CREATE PROCEDURE dbo.sp_Wf_ToggleConditionFieldActive
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

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Wf_GetDefinitionRuleJsons
CREATE PROCEDURE dbo.sp_Wf_GetDefinitionRuleJsons
    @DefinitionID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT t.TransitionID, t.VersionID, t.RuleJson
    FROM dbo.WorkflowTransitions t
    JOIN dbo.WorkflowSteps s ON s.StepID = t.FromStepID
    JOIN dbo.WorkflowVersions v ON v.VersionID = s.VersionID
    WHERE v.DefinitionID = @DefinitionID AND t.RuleJson IS NOT NULL;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Wf_GetInstanceContext
CREATE PROCEDURE dbo.sp_Wf_GetInstanceContext
    @InstanceID BIGINT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT ContextJson FROM dbo.WorkflowInstances WHERE InstanceID = @InstanceID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_SaveVersionGraph
ALTER PROCEDURE dbo.sp_Wf_SaveVersionGraph
    @VersionID       INT,
    @StepsJson       NVARCHAR(MAX),
    @ActionsJson     NVARCHAR(MAX) = N'[]',
    @AssignmentsJson NVARCHAR(MAX) = N'[]',
    @TransitionsJson NVARCHAR(MAX) = N'[]',
    @UserID          INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Status NVARCHAR(20);
    SELECT @Status = Status FROM dbo.WorkflowVersions WHERE VersionID = @VersionID;
    IF @Status IS NULL
    BEGIN SELECT 0 AS Success, N'نسخه یافت نشد.' AS Message; RETURN; END
    IF @Status <> N'DRAFT'
    BEGIN SELECT 0 AS Success, N'فقط نسخهٔ پیش‌نویس (DRAFT) قابل ویرایش است.' AS Message; RETURN; END

    IF ISJSON(@StepsJson) = 0 OR ISJSON(@ActionsJson) = 0 OR ISJSON(@AssignmentsJson) = 0 OR ISJSON(@TransitionsJson) = 0
    BEGIN SELECT 0 AS Success, N'ساختار JSON نامعتبر است.' AS Message; RETURN; END

    DELETE FROM dbo.WorkflowTransitions       WHERE VersionID = @VersionID;
    DELETE FROM dbo.WorkflowStepAssignments   WHERE VersionID = @VersionID;
    DELETE FROM dbo.WorkflowStepActions       WHERE VersionID = @VersionID;
    DELETE FROM dbo.WorkflowSteps             WHERE VersionID = @VersionID;

    INSERT INTO dbo.WorkflowSteps (VersionID, Code, Name, StepType, AssignPolicy, RequiredApprovals, AllowForward, ForwardMax, AllowDelegation, SortOrder, PositionX, PositionY, Description, DueDurationHours, Date_InsertFirst, UserID_InsertFirst)
    SELECT @VersionID,
           j.Code, j.Name, j.StepType,
           ISNULL(j.AssignPolicy, N'ANY'), j.RequiredApprovals,
           ISNULL(j.AllowForward, 0), j.ForwardMax, ISNULL(j.AllowDelegation, 1),
           ISNULL(j.SortOrder, 0), j.PositionX, j.PositionY, j.Description, j.DueDurationHours, SYSDATETIME(), @UserID
    FROM OPENJSON(@StepsJson) WITH (
        Code NVARCHAR(64) N'$.code', Name NVARCHAR(200) N'$.name', StepType NVARCHAR(30) N'$.stepType',
        AssignPolicy NVARCHAR(10) N'$.assignPolicy', RequiredApprovals INT N'$.requiredApprovals',
        AllowForward BIT N'$.allowForward', ForwardMax INT N'$.forwardMax', AllowDelegation BIT N'$.allowDelegation',
        SortOrder INT N'$.sortOrder', PositionX INT N'$.positionX', PositionY INT N'$.positionY',
        Description NVARCHAR(1000) N'$.description', DueDurationHours INT N'$.dueDurationHours'
    ) j;

    INSERT INTO dbo.WorkflowStepActions (VersionID, StepID, Code, Kind, Label, Icon, Style, RequiresComment, RequiresConfirm, ConfirmMessage, PermissionCode, SortOrder, Date_InsertFirst, UserID_InsertFirst)
    SELECT @VersionID, s.StepID, j.Code, ISNULL(j.Kind, N'CUSTOM'), j.Label, j.Icon, j.Style,
           ISNULL(j.RequiresComment, 0), ISNULL(j.RequiresConfirm, 0), j.ConfirmMessage, j.PermissionCode,
           ISNULL(j.SortOrder, 0), SYSDATETIME(), @UserID
    FROM OPENJSON(@ActionsJson) WITH (
        StepCode NVARCHAR(64) N'$.stepCode', Code NVARCHAR(64) N'$.code', Kind NVARCHAR(20) N'$.kind',
        Label NVARCHAR(100) N'$.label', Icon NVARCHAR(50) N'$.icon', Style NVARCHAR(20) N'$.style',
        RequiresComment BIT N'$.requiresComment', RequiresConfirm BIT N'$.requiresConfirm',
        ConfirmMessage NVARCHAR(300) N'$.confirmMessage', PermissionCode NVARCHAR(100) N'$.permissionCode',
        SortOrder INT N'$.sortOrder'
    ) j
    JOIN dbo.WorkflowSteps s ON s.VersionID = @VersionID AND s.Code = j.StepCode;

    INSERT INTO dbo.WorkflowStepAssignments (VersionID, StepID, AssigneeType, RefID, RefExpression, SortOrder, IsBackup, Date_InsertFirst, UserID_InsertFirst)
    SELECT @VersionID, s.StepID, j.AssigneeType, j.RefID, j.RefExpression, ISNULL(j.SortOrder, 0), ISNULL(j.IsBackup, 0), SYSDATETIME(), @UserID
    FROM OPENJSON(@AssignmentsJson) WITH (
        StepCode NVARCHAR(64) N'$.stepCode', AssigneeType NVARCHAR(30) N'$.assigneeType',
        RefID INT N'$.refId', RefExpression NVARCHAR(400) N'$.refExpression', SortOrder INT N'$.sortOrder',
        IsBackup BIT N'$.isBackup'
    ) j
    JOIN dbo.WorkflowSteps s ON s.VersionID = @VersionID AND s.Code = j.StepCode;

    INSERT INTO dbo.WorkflowTransitions (VersionID, Code, FromStepID, ToStepID, TriggerActionID, Priority, IsDefault, Label, ConditionExpression, RuleJson, Date_InsertFirst, UserID_InsertFirst)
    SELECT @VersionID, j.Code, fs.StepID, ts.StepID, ta.ActionID, ISNULL(j.Priority, 100), ISNULL(j.IsDefault, 0), j.Label, j.ConditionExpression, j.RuleJson, SYSDATETIME(), @UserID
    FROM OPENJSON(@TransitionsJson) WITH (
        Code NVARCHAR(64) N'$.code', FromStepCode NVARCHAR(64) N'$.fromStepCode', ToStepCode NVARCHAR(64) N'$.toStepCode',
        TriggerActionCode NVARCHAR(64) N'$.triggerActionCode', Priority INT N'$.priority', IsDefault BIT N'$.isDefault',
        Label NVARCHAR(100) N'$.label', ConditionExpression NVARCHAR(500) N'$.conditionExpression',
        RuleJson NVARCHAR(MAX) N'$.ruleJson' AS JSON
    ) j
    JOIN dbo.WorkflowSteps fs ON fs.VersionID = @VersionID AND fs.Code = j.FromStepCode
    JOIN dbo.WorkflowSteps ts ON ts.VersionID = @VersionID AND ts.Code = j.ToStepCode
    LEFT JOIN dbo.WorkflowStepActions ta ON ta.StepID = fs.StepID AND ta.Code = j.TriggerActionCode;

    UPDATE dbo.WorkflowVersions SET ValidationResultJson = NULL, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE VersionID = @VersionID;

    SELECT 1 AS Success, N'گراف ذخیره شد.' AS Message;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_GetVersionTransitions
ALTER PROCEDURE dbo.sp_Wf_GetVersionTransitions
    @VersionID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TransitionID, Code, FromStepID, ToStepID, TriggerActionID, Priority, IsDefault, Label, ConditionExpression, RuleJson
    FROM dbo.WorkflowTransitions WHERE VersionID = @VersionID ORDER BY FromStepID, Priority, TransitionID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_StartInstance
ALTER PROCEDURE dbo.sp_Wf_StartInstance
    @DefinitionID     INT,
    @VersionID        INT,
    @EntityType       NVARCHAR(64),
    @EntityID         BIGINT,
    @StartedByUserID  INT           = NULL,
    @InstanceNumber   NVARCHAR(50),
    @ContextJson      NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    INSERT INTO dbo.WorkflowInstances
        (InstanceNumber, DefinitionID, VersionID, EntityType, EntityID, Status, StartedByUserID, StartedAt, ContextJson, Date_InsertFirst, UserID_InsertFirst)
    VALUES
        (@InstanceNumber, @DefinitionID, @VersionID, @EntityType, @EntityID, N'RUNNING', @StartedByUserID, SYSDATETIME(), @ContextJson, SYSDATETIME(), @StartedByUserID);

    SELECT 1 AS Success, N'نمونهٔ فرایند ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS BIGINT) AS InstanceID;
END
GO

/* [DATA SEED] Permissionِ جدید — Idempotent؛ این بخش توسطِ Triggerِ DDL ردیابی نمی‌شود
   (چون INSERT است نه تغییرِ Schema)، پس دستی به پچ اضافه شد تا اجرایِ این پچ روی هر
   محیطِ دیگری کاملاً خودکفا باشد (هم‌الگو با patch 025). */
IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE PermissionCode = N'WORKFLOW_MANAGE_CONDITION_FIELDS')
    INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive)
    VALUES (N'WORKFLOW_MANAGE_CONDITION_FIELDS', N'مدیریتِ فیلدهایِ شرط', N'Workflow', N'ایجاد/ویرایش/فعال‌سازیِ فیلدهایِ قابلِ‌استفاده در Rule Builderِ فرایندها', 11, 1);
GO

-- اعطایِ خودکارِ Permissionِ جدید به نقشِ «مدیرِ سیستم» (RoleID=1) — هم‌الگو با patch 025
IF EXISTS (SELECT 1 FROM dbo.Roles WHERE RoleID = 1)
BEGIN
    INSERT INTO dbo.RolePermissions (RoleID, PermissionID, CanAccess, IsActive)
    SELECT 1, p.PermissionID, 1, 1
    FROM dbo.Permissions p
    WHERE p.PermissionCode = N'WORKFLOW_MANAGE_CONDITION_FIELDS'
      AND NOT EXISTS (SELECT 1 FROM dbo.RolePermissions rp WHERE rp.RoleID = 1 AND rp.PermissionID = p.PermissionID);
END
GO
