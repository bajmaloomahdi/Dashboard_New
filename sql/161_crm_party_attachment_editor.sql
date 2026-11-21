ALTER PROCEDURE dbo.sp_Crm_GetPartyAttachments
    @PartyID int = NULL,
    @PartyAttachmentID int = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT a.PartyAttachmentID, a.PartyID, a.FileName, a.FileExtension, a.FileSize, a.FilePath, a.Description,
           a.Date_InsertFirst, a.UserID_InsertFirst,
           (cu.FirstName + N' ' + cu.LastName) AS CreatedByName,
           CONVERT(varchar(19), a.Date_LastUpdate, 120) AS EditedAt,
           dbo.fn_GetUserFullName(editor.FirstName, editor.LastName, editor.UserName) AS EditedByName
    FROM dbo.CrmPartyAttachments a
    LEFT JOIN dbo.Users cu ON cu.UserID = a.UserID_InsertFirst
    LEFT JOIN dbo.Users editor ON editor.UserID = a.UserID_LastUpdate
    WHERE a.IsActive = 1
      AND (@PartyID IS NULL OR a.PartyID = @PartyID)
      AND (@PartyAttachmentID IS NULL OR a.PartyAttachmentID = @PartyAttachmentID)
    ORDER BY a.PartyAttachmentID;
END
GO
