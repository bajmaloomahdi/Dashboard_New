ALTER PROCEDURE dbo.sp_Crm_GetInteractionAttachments
    @InteractionID           INT = NULL,
    @PartyID                 INT = NULL,
    @PersonID                INT = NULL,
    @InteractionAttachmentID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT a.InteractionAttachmentID, a.InteractionID, a.FileName, a.FileExtension, a.FileSize, a.FilePath, a.Description,
           a.Date_InsertFirst, a.UserID_InsertFirst,
           (u.FirstName + N' ' + u.LastName) AS CreatedByName,
           CONVERT(varchar(19), a.Date_LastUpdate, 120) AS EditedAt,
           dbo.fn_GetUserFullName(editor.FirstName, editor.LastName, editor.UserName) AS EditedByName
    FROM dbo.CrmInteractionAttachments a
    JOIN dbo.CrmInteractions i ON i.InteractionID = a.InteractionID
    LEFT JOIN dbo.Users u ON u.UserID = a.UserID_InsertFirst
    LEFT JOIN dbo.Users editor ON editor.UserID = a.UserID_LastUpdate
    WHERE (@InteractionID           IS NULL OR a.InteractionID = @InteractionID)
      AND (@PartyID                 IS NULL OR i.PartyID = @PartyID)
      AND (@PersonID                IS NULL OR i.PersonID = @PersonID)
      AND (@InteractionAttachmentID IS NULL OR a.InteractionAttachmentID = @InteractionAttachmentID)
    ORDER BY a.InteractionID, a.InteractionAttachmentID;
END
GO
CREATE OR ALTER PROCEDURE dbo.sp_Crm_UpdateInteractionAttachmentDescription
    @InteractionID int, @AttachmentID int, @Description nvarchar(1000), @UserID int
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE dbo.CrmInteractionAttachments
    SET Description = NULLIF(LTRIM(RTRIM(@Description)), N''),
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE InteractionID = @InteractionID AND InteractionAttachmentID = @AttachmentID
      AND ISNULL(Description, N'') <> ISNULL(NULLIF(LTRIM(RTRIM(@Description)), N''), N'');
    SELECT CONVERT(int, CASE WHEN EXISTS (
        SELECT 1 FROM dbo.CrmInteractionAttachments
        WHERE InteractionID = @InteractionID AND InteractionAttachmentID = @AttachmentID
    ) THEN 1 ELSE 0 END) AS Updated;
END
GO
