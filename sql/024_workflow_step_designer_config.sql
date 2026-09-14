/* ==========================================================================
   پچ خودکار شماره: 024 | نام: workflow_step_designer_config
   تاریخ: 2026-09-14 12:33:44 | شامل 7 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: WorkflowStepAssignments
ALTER TABLE dbo.WorkflowStepAssignments ADD IsBackup BIT NOT NULL CONSTRAINT DF_WorkflowStepAssignments_IsBackup DEFAULT (0)
GO

-- [ALTER_TABLE] روی TABLE: WorkflowSteps
ALTER TABLE dbo.WorkflowSteps ADD DueDurationHours INT NULL
GO

-- [ALTER_TABLE] روی TABLE: WorkflowTransitions
ALTER TABLE dbo.WorkflowTransitions ADD ConditionExpression NVARCHAR(500) NULL
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_GetVersionSteps
/* 2) sp_Wf_GetVersionSteps — افزودنِ DueDurationHours به SELECT */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetVersionSteps
    @VersionID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT StepID, VersionID, Code, Name, StepType, AssignPolicy, RequiredApprovals,
           AllowForward, ForwardMax, AllowDelegation, SortOrder, PositionX, PositionY, Description,
           DueDurationHours
    FROM dbo.WorkflowSteps WHERE VersionID = @VersionID ORDER BY SortOrder, StepID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_GetVersionStepAssignments
/* 3) sp_Wf_GetVersionStepAssignments — افزودنِ IsBackup به SELECT */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetVersionStepAssignments
    @VersionID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT StepAssignmentID, StepID, AssigneeType, RefID, RefExpression, SortOrder, IsBackup
    FROM dbo.WorkflowStepAssignments WHERE VersionID = @VersionID ORDER BY StepID, SortOrder, StepAssignmentID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_GetVersionTransitions
/* 4) sp_Wf_GetVersionTransitions — افزودنِ ConditionExpression به SELECT */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetVersionTransitions
    @VersionID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TransitionID, Code, FromStepID, ToStepID, TriggerActionID, Priority, IsDefault, Label, ConditionExpression
    FROM dbo.WorkflowTransitions WHERE VersionID = @VersionID ORDER BY FromStepID, Priority, TransitionID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_SaveVersionGraph
/* 5) sp_Wf_SaveVersionGraph — افزودنِ DueDurationHours/IsBackup/ConditionExpression به بخش‌های
   Steps/Assignments/Transitions؛ Status Guard، پاک‌سازی، JOINها و بقیهٔ منطق دست‌نخورده. */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_SaveVersionGraph
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

    -- پاک‌سازی (رعایت ترتیب FK)
    DELETE FROM dbo.WorkflowTransitions       WHERE VersionID = @VersionID;
    DELETE FROM dbo.WorkflowStepAssignments   WHERE VersionID = @VersionID;
    DELETE FROM dbo.WorkflowStepActions       WHERE VersionID = @VersionID;
    DELETE FROM dbo.WorkflowSteps             WHERE VersionID = @VersionID;

    -- Steps
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

    -- Actions
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

    -- Assignments
    INSERT INTO dbo.WorkflowStepAssignments (VersionID, StepID, AssigneeType, RefID, RefExpression, SortOrder, IsBackup, Date_InsertFirst, UserID_InsertFirst)
    SELECT @VersionID, s.StepID, j.AssigneeType, j.RefID, j.RefExpression, ISNULL(j.SortOrder, 0), ISNULL(j.IsBackup, 0), SYSDATETIME(), @UserID
    FROM OPENJSON(@AssignmentsJson) WITH (
        StepCode NVARCHAR(64) N'$.stepCode', AssigneeType NVARCHAR(30) N'$.assigneeType',
        RefID INT N'$.refId', RefExpression NVARCHAR(400) N'$.refExpression', SortOrder INT N'$.sortOrder',
        IsBackup BIT N'$.isBackup'
    ) j
    JOIN dbo.WorkflowSteps s ON s.VersionID = @VersionID AND s.Code = j.StepCode;

    -- Transitions
    INSERT INTO dbo.WorkflowTransitions (VersionID, Code, FromStepID, ToStepID, TriggerActionID, Priority, IsDefault, Label, ConditionExpression, Date_InsertFirst, UserID_InsertFirst)
    SELECT @VersionID, j.Code, fs.StepID, ts.StepID, ta.ActionID, ISNULL(j.Priority, 100), ISNULL(j.IsDefault, 0), j.Label, j.ConditionExpression, SYSDATETIME(), @UserID
    FROM OPENJSON(@TransitionsJson) WITH (
        Code NVARCHAR(64) N'$.code', FromStepCode NVARCHAR(64) N'$.fromStepCode', ToStepCode NVARCHAR(64) N'$.toStepCode',
        TriggerActionCode NVARCHAR(64) N'$.triggerActionCode', Priority INT N'$.priority', IsDefault BIT N'$.isDefault',
        Label NVARCHAR(100) N'$.label', ConditionExpression NVARCHAR(500) N'$.conditionExpression'
    ) j
    JOIN dbo.WorkflowSteps fs ON fs.VersionID = @VersionID AND fs.Code = j.FromStepCode
    JOIN dbo.WorkflowSteps ts ON ts.VersionID = @VersionID AND ts.Code = j.ToStepCode
    LEFT JOIN dbo.WorkflowStepActions ta ON ta.StepID = fs.StepID AND ta.Code = j.TriggerActionCode;

    -- علامت‌گذاریِ نیازِ اعتبارسنجیِ مجدد پس از هر ذخیره
    UPDATE dbo.WorkflowVersions SET ValidationResultJson = NULL, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE VersionID = @VersionID;

    SELECT 1 AS Success, N'گراف نسخه ذخیره شد.' AS Message;
END
GO

