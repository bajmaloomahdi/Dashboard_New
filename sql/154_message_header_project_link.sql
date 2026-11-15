SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO
CREATE OR ALTER PROCEDURE [dbo].[sp_GetMessageHeader]
    @MessageID INT,
    @ViewerID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        M.MessageID,
        M.Subject,
        M.MessageText,
        M.DueDate,
        M.MessageTypeID,
        MT.MessageTypeName,
        M.SenderUserID,
        LTRIM(CONCAT(SU.FirstName, N' ', SU.LastName)) AS SenderName,
        M.CreateDate,
        M.CreateUser,
        CASE WHEN EXISTS (
            SELECT 1 FROM dbo.WorkflowStepInstances WSI WHERE WSI.MessageID = M.MessageID
        ) THEN 1 ELSE 0 END AS IsWfTask,
        taskProject.ProjectID,
        taskProject.ProjectTitle
    FROM dbo.Messages M
    JOIN dbo.MessageTypes MT ON MT.MessageTypeID = M.MessageTypeID
    LEFT JOIN dbo.Users SU   ON SU.UserID = M.SenderUserID
    OUTER APPLY (
        SELECT TOP (1) p.ProjectID, p.ProjectTitle
        FROM dbo.ProjectMessages AS pm
        JOIN dbo.Projects AS p ON p.ProjectID = pm.ProjectID
        WHERE pm.MessageID = M.MessageID AND @ViewerID IS NOT NULL
            AND (p.UserID_InsertFirst = @ViewerID OR EXISTS (
                SELECT 1 FROM dbo.ProjectMembers AS member
                WHERE member.ProjectID = p.ProjectID AND member.UserID = @ViewerID AND member.IsActive = 1
            ))
        ORDER BY p.ProjectID DESC
    ) AS taskProject
    WHERE M.MessageID = @MessageID;
END
GO
