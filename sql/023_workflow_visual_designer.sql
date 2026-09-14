/* ==========================================================================
   پچ خودکار شماره: 023 | نام: workflow_visual_designer
   تاریخ: 2026-09-14 10:42:32 | شامل 3 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: WorkflowSteps
ALTER TABLE dbo.WorkflowSteps ADD
    PositionX   INT NULL,
    PositionY   INT NULL,
    Description NVARCHAR(1000) NULL
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_GetVersionSteps
/* Ø§ÙØ²ÙˆØ¯Ù†Ù Ù‡Ù…ÛŒÙ† Ø³Ù‡ ÙÛŒÙ„Ø¯ Ø¨Ù‡ Ø®Ø±ÙˆØ¬ÛŒÙ GetVersionSteps â€” Ø¨Ø¯ÙˆÙ†Ù ØªØºÛŒÛŒØ±Ù Ù¾Ø§Ø±Ø§Ù…ØªØ± ÛŒØ§ Ø­Ø°ÙÙ ÙÛŒÙ„Ø¯Ù Ø¯ÛŒÚ¯Ø± */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetVersionSteps
    @VersionID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT StepID, VersionID, Code, Name, StepType, AssignPolicy, RequiredApprovals,
           AllowForward, ForwardMax, AllowDelegation, SortOrder, PositionX, PositionY, Description
    FROM dbo.WorkflowSteps WHERE VersionID = @VersionID ORDER BY SortOrder, StepID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_SaveVersionGraph
/* Ø§ÙØ²ÙˆØ¯Ù†Ù Ù‡Ù…ÛŒÙ† Ø³Ù‡ ÙÛŒÙ„Ø¯ Ø¨Ù‡ SaveVersionGraph â€” ÙÙ‚Ø· Ø¨Ø®Ø´Ù INSERTÙ Steps ØªØºÛŒÛŒØ± Ú©Ø±Ø¯Ø›
   Status GuardØŒ Ù¾Ø§Ú©â€ŒØ³Ø§Ø²ÛŒØŒ Actions/Assignments/Transitions Ùˆ Ø¨ÛŒâ€ŒØ§Ø¹ØªØ¨Ø§Ø±Ø³Ø§Ø²ÛŒÙ Validation Ø¯Ø³Øªâ€ŒÙ†Ø®ÙˆØ±Ø¯Ù‡â€ŒØ§Ù†Ø¯. */
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
    BEGIN SELECT 0 AS Success, N'Ù†Ø³Ø®Ù‡ ÛŒØ§ÙØª Ù†Ø´Ø¯.' AS Message; RETURN; END
    IF @Status <> N'DRAFT'
    BEGIN SELECT 0 AS Success, N'ÙÙ‚Ø· Ù†Ø³Ø®Ù‡Ù” Ù¾ÛŒØ´â€ŒÙ†ÙˆÛŒØ³ (DRAFT) Ù‚Ø§Ø¨Ù„ ÙˆÛŒØ±Ø§ÛŒØ´ Ø§Ø³Øª.' AS Message; RETURN; END

    IF ISJSON(@StepsJson) = 0 OR ISJSON(@ActionsJson) = 0 OR ISJSON(@AssignmentsJson) = 0 OR ISJSON(@TransitionsJson) = 0
    BEGIN SELECT 0 AS Success, N'Ø³Ø§Ø®ØªØ§Ø± JSON Ù†Ø§Ù…Ø¹ØªØ¨Ø± Ø§Ø³Øª.' AS Message; RETURN; END

    -- Ù¾Ø§Ú©â€ŒØ³Ø§Ø²ÛŒ (Ø±Ø¹Ø§ÛŒØª ØªØ±ØªÛŒØ¨ FK)
    DELETE FROM dbo.WorkflowTransitions       WHERE VersionID = @VersionID;
    DELETE FROM dbo.WorkflowStepAssignments   WHERE VersionID = @VersionID;
    DELETE FROM dbo.WorkflowStepActions       WHERE VersionID = @VersionID;
    DELETE FROM dbo.WorkflowSteps             WHERE VersionID = @VersionID;

    -- Steps
    INSERT INTO dbo.WorkflowSteps (VersionID, Code, Name, StepType, AssignPolicy, RequiredApprovals, AllowForward, ForwardMax, AllowDelegation, SortOrder, PositionX, PositionY, Description, Date_InsertFirst, UserID_InsertFirst)
    SELECT @VersionID,
           j.Code, j.Name, j.StepType,
           ISNULL(j.AssignPolicy, N'ANY'), j.RequiredApprovals,
           ISNULL(j.AllowForward, 0), j.ForwardMax, ISNULL(j.AllowDelegation, 1),
           ISNULL(j.SortOrder, 0), j.PositionX, j.PositionY, j.Description, SYSDATETIME(), @UserID
    FROM OPENJSON(@StepsJson) WITH (
        Code NVARCHAR(64) N'$.code', Name NVARCHAR(200) N'$.name', StepType NVARCHAR(30) N'$.stepType',
        AssignPolicy NVARCHAR(10) N'$.assignPolicy', RequiredApprovals INT N'$.requiredApprovals',
        AllowForward BIT N'$.allowForward', ForwardMax INT N'$.forwardMax', AllowDelegation BIT N'$.allowDelegation',
        SortOrder INT N'$.sortOrder', PositionX INT N'$.positionX', PositionY INT N'$.positionY',
        Description NVARCHAR(1000) N'$.description'
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
    INSERT INTO dbo.WorkflowStepAssignments (VersionID, StepID, AssigneeType, RefID, RefExpression, SortOrder, Date_InsertFirst, UserID_InsertFirst)
    SELECT @VersionID, s.StepID, j.AssigneeType, j.RefID, j.RefExpression, ISNULL(j.SortOrder, 0), SYSDATETIME(), @UserID
    FROM OPENJSON(@AssignmentsJson) WITH (
        StepCode NVARCHAR(64) N'$.stepCode', AssigneeType NVARCHAR(30) N'$.assigneeType',
        RefID INT N'$.refId', RefExpression NVARCHAR(400) N'$.refExpression', SortOrder INT N'$.sortOrder'
    ) j
    JOIN dbo.WorkflowSteps s ON s.VersionID = @VersionID AND s.Code = j.StepCode;

    -- Transitions
    INSERT INTO dbo.WorkflowTransitions (VersionID, Code, FromStepID, ToStepID, TriggerActionID, Priority, IsDefault, Label, Date_InsertFirst, UserID_InsertFirst)
    SELECT @VersionID, j.Code, fs.StepID, ts.StepID, ta.ActionID, ISNULL(j.Priority, 100), ISNULL(j.IsDefault, 0), j.Label, SYSDATETIME(), @UserID
    FROM OPENJSON(@TransitionsJson) WITH (
        Code NVARCHAR(64) N'$.code', FromStepCode NVARCHAR(64) N'$.fromStepCode', ToStepCode NVARCHAR(64) N'$.toStepCode',
        TriggerActionCode NVARCHAR(64) N'$.triggerActionCode', Priority INT N'$.priority', IsDefault BIT N'$.isDefault',
        Label NVARCHAR(100) N'$.label'
    ) j
    JOIN dbo.WorkflowSteps fs ON fs.VersionID = @VersionID AND fs.Code = j.FromStepCode
    JOIN dbo.WorkflowSteps ts ON ts.VersionID = @VersionID AND ts.Code = j.ToStepCode
    LEFT JOIN dbo.WorkflowStepActions ta ON ta.StepID = fs.StepID AND ta.Code = j.TriggerActionCode;

    -- Ø¹Ù„Ø§Ù…Øªâ€ŒÚ¯Ø°Ø§Ø±ÛŒÙ Ù†ÛŒØ§Ø²Ù Ø§Ø¹ØªØ¨Ø§Ø±Ø³Ù†Ø¬ÛŒÙ Ù…Ø¬Ø¯Ø¯ Ù¾Ø³ Ø§Ø² Ù‡Ø± Ø°Ø®ÛŒØ±Ù‡
    UPDATE dbo.WorkflowVersions SET ValidationResultJson = NULL, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE VersionID = @VersionID;

    SELECT 1 AS Success, N'Ú¯Ø±Ø§Ù Ù†Ø³Ø®Ù‡ Ø°Ø®ÛŒØ±Ù‡ Ø´Ø¯.' AS Message;
END
GO

