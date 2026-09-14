/* ==========================================================================
   پچ خودکار شماره: 022 | نام: workflow_runtime_ux
   تاریخ: 2026-09-12 16:54:20 | شامل 2 دستور SQL
   ========================================================================== */

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_GetInstance
/* Gap 1: Ø§ÙØ²ÙˆØ¯Ù†Ù RequiredApprovals Ø¨Ù‡ Result-SetÙ Ø¯ÙˆÙ…Ù sp_Wf_GetInstance â€” Ø¨Ø¯ÙˆÙ†Ù ØªØºÛŒÛŒØ±Ù Ù¾Ø§Ø±Ø§Ù…ØªØ± ÛŒØ§ Ø³Ø§ÛŒØ±Ù Result-SetÙ‡Ø§ */
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
           si.RequiredApprovals, si.ReceivedApprovals, si.ReceivedRejections
    FROM dbo.WorkflowStepInstances si
    WHERE si.InstanceID = @InstanceID
    ORDER BY si.StepInstanceID;

    -- Â«ØªØ³Ú©â€ŒÙ‡Ø§Â» = Ù¾ÛŒØ§Ù…â€ŒÙ‡Ø§ÛŒÙ Ú©Ø§Ø±ØªØ§Ø¨Ù„ÛŒÙ Ù…ØªØµÙ„ Ø¨Ù‡ Ù‡Ø± Step Ø§Ø² Ø§ÛŒÙ† Instance
    SELECT si.StepInstanceID, m.MessageID, m.MessageNumber, m.Subject AS Title, si.Status,
           m.CreateDate AS CreatedAt, s.Name AS StepName,
           (SELECT STRING_AGG(u.FullName, N'ØŒ ') FROM dbo.WorkflowTaskAssignees a JOIN dbo.Users u ON u.UserID = a.UserID
             WHERE a.StepInstanceID = si.StepInstanceID AND a.IsActive = 1) AS AssigneeNames,
           (SELECT COUNT(*) FROM dbo.MessageDetails md JOIN dbo.MessageStatuses ms ON ms.MessageStatusID = md.MessageStatusID
             WHERE md.MessageID = m.MessageID AND ms.MessageStatusName NOT IN (N'Ù¾ÛŒØ§Ù… Ø®ÙˆØ§Ù†Ø¯Ù‡', N'Ù¾ÛŒØ§Ù… Ø¨Ø§ÛŒÚ¯Ø§Ù†ÛŒ Ø´Ø¯Ù‡')) AS OpenRecipientCount
    FROM dbo.WorkflowStepInstances si
    JOIN dbo.Messages m ON m.MessageID = si.MessageID
    JOIN dbo.WorkflowSteps s ON s.StepID = si.StepID
    WHERE si.InstanceID = @InstanceID AND si.MessageID IS NOT NULL
    ORDER BY si.StepInstanceID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Wf_GetInstances
/* Gap 3: Ù„ÛŒØ³ØªÙ InstanceÙ‡Ø§ Ø¨Ø§ ÙÛŒÙ„ØªØ± + Server-side Pagination (SP Ø¬Ø¯ÛŒØ¯) */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetInstances
    @DefinitionID     INT           = NULL,
    @Status           NVARCHAR(40)  = NULL,
    @EntityType       NVARCHAR(128) = NULL,
    @EntityID         BIGINT        = NULL,
    @StartedByUserID  INT           = NULL,
    @DateFrom         DATETIME2     = NULL,
    @DateTo           DATETIME2     = NULL,
    @Page             INT           = 1,
    @PageSize         INT           = 20
AS
BEGIN
    SET NOCOUNT ON;
    IF @Page < 1 SET @Page = 1;
    IF @PageSize < 1 OR @PageSize > 200 SET @PageSize = 20;

    SELECT
        i.InstanceID, i.InstanceNumber, i.DefinitionID, d.Code AS DefinitionCode, d.Name AS DefinitionName,
        i.VersionID, i.EntityType, i.EntityID, i.Status, i.StartedByUserID, su.FullName AS StartedByName,
        i.StartedAt, i.CompletedAt,
        cs.StepID   AS CurrentStepID,
        cs.StepCode AS CurrentStepCode,
        cs.StepName AS CurrentStepName,
        COUNT(*) OVER () AS TotalCount
    FROM dbo.WorkflowInstances i
    JOIN dbo.WorkflowDefinitions d ON d.DefinitionID = i.DefinitionID
    LEFT JOIN dbo.Users su ON su.UserID = i.StartedByUserID
    OUTER APPLY (
        SELECT TOP 1 si.StepID, si.StepCode, s.Name AS StepName
        FROM dbo.WorkflowStepInstances si
        JOIN dbo.WorkflowSteps s ON s.StepID = si.StepID
        WHERE si.InstanceID = i.InstanceID AND si.Status = N'ACTIVE'
        ORDER BY si.StepInstanceID DESC
    ) cs
    WHERE (@DefinitionID    IS NULL OR i.DefinitionID    = @DefinitionID)
      AND (@Status          IS NULL OR i.Status          = @Status)
      AND (@EntityType      IS NULL OR i.EntityType      = @EntityType)
      AND (@EntityID        IS NULL OR i.EntityID        = @EntityID)
      AND (@StartedByUserID IS NULL OR i.StartedByUserID = @StartedByUserID)
      AND (@DateFrom        IS NULL OR i.StartedAt      >= @DateFrom)
      AND (@DateTo          IS NULL OR i.StartedAt      <  @DateTo)
    ORDER BY i.StartedAt DESC
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;
END
GO

