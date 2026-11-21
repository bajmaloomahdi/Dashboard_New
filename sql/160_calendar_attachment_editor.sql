ALTER PROCEDURE dbo.sp_Calendar_GetEventAttachments
    @EventID bigint,
    @OriginalStart datetime2(0) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    SELECT a.CalendarEventAttachmentID, a.EventID, a.FileName, a.FileExtension,
           a.FileSize, a.FilePath, a.Date_InsertFirst, a.Description,
           CONVERT(varchar(19), a.Date_InsertFirst, 120) AS UploadedAt,
           dbo.fn_GetUserFullName(u.FirstName, u.LastName, u.UserName) AS UploadedByName,
           CONVERT(varchar(19), a.Date_LastUpdate, 120) AS EditedAt,
           ISNULL(NULLIF(LTRIM(RTRIM(editor.FullName)), N''), editor.UserName) AS EditedByName
    FROM dbo.CalendarEventAttachments a
    LEFT JOIN dbo.Users u ON u.UserID = a.UserID_InsertFirst
    LEFT JOIN dbo.Users editor ON editor.UserID = a.UserID_LastUpdate
    WHERE a.EventID = @EventID AND a.IsActive = 1
      AND (a.OriginalStart IS NULL OR a.OriginalStart = @OriginalStart)
    ORDER BY a.CalendarEventAttachmentID;
END
GO
