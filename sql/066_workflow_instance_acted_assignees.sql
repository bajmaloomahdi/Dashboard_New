/* ==========================================================================
   پچ خودکار شماره: 066 | نام: workflow_instance_acted_assignees
   تاریخ: 2026-09-28 18:44:13 | شامل 1 دستور SQL
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
           -- انجام‌دهندهٔ واقعی (نه صرفاً Assigneeِ واجدِ شرایط) — فقط کسانی که واقعاً Decision ثبت کرده‌اند؛
           -- Assigneeِ فعالِ بدونِ Decision (چون ANY/N_OF_M دیگری زودتر تصمیم گرفته) اینجا نمی‌آید. بدونِ اقدام، NULL است.
           (SELECT STRING_AGG(u.FullName, N'ØŒ ') FROM dbo.WorkflowTaskAssignees a JOIN dbo.Users u ON u.UserID = a.UserID
             WHERE a.StepInstanceID = si.StepInstanceID AND a.Decision IS NOT NULL) AS ActedAssigneeNames,
           (SELECT COUNT(*) FROM dbo.MessageDetails md JOIN dbo.MessageStatuses ms ON ms.MessageStatusID = md.MessageStatusID
             WHERE md.MessageID = m.MessageID AND ms.MessageStatusName NOT IN (N'Ù¾ÛŒØ§Ù… Ø®ÙˆØ§Ù†Ø¯Ù‡', N'Ù¾ÛŒØ§Ù… Ø¨Ø§ÛŒÚ¯Ø§Ù†ÛŒ Ø´Ø¯Ù‡')) AS OpenRecipientCount
    FROM dbo.WorkflowStepInstances si
    JOIN dbo.Messages m ON m.MessageID = si.MessageID
    JOIN dbo.WorkflowSteps s ON s.StepID = si.StepID
    WHERE si.InstanceID = @InstanceID AND si.MessageID IS NOT NULL
    ORDER BY si.StepInstanceID;
END
GO

