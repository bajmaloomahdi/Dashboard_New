IF COL_LENGTH('dbo.CalendarEventComments', 'OriginalStart') IS NULL ALTER TABLE dbo.CalendarEventComments ADD OriginalStart DATETIME2(0) NULL;
IF COL_LENGTH('dbo.CalendarEventAttachments', 'OriginalStart') IS NULL ALTER TABLE dbo.CalendarEventAttachments ADD OriginalStart DATETIME2(0) NULL;
GO
ALTER PROCEDURE dbo.sp_Calendar_AddEventAttachment
    @EventID       BIGINT,
    @FileName      NVARCHAR(255),
    @FileExtension NVARCHAR(20) = NULL,
    @FileSize      BIGINT,
    @FilePath      NVARCHAR(1000),
    @UserID        INT,
    @Description   NVARCHAR(500) = NULL,
    @OriginalStart DATETIME2(0) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Creator INT, @Owner INT, @Unit INT;
    SELECT @Creator = CreatedByUserID, @Owner = OwnerUserID, @Unit = OrganizationalUnitID FROM dbo.CalendarEvents WHERE EventID = @EventID AND IsActive = 1;
    IF @Creator IS NULL
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'رویداد یافت نشد.' AS Message; RETURN; END
    IF @UserID <> @Creator AND @UserID <> @Owner
       AND NOT (@Unit IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.fn_UserActiveUnits(@UserID) uu WHERE uu.UnitID = @Unit)
                AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@UserID, N'CALENDAR_UNIT_MANAGE')))
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'فقط ایجادکننده یا مالکِ رویداد می‌تواند فایل ضمیمه کند.' AS Message; RETURN; END

    INSERT INTO dbo.CalendarEventAttachments (EventID, FileName, FileExtension, FileSize, FilePath, UserID_InsertFirst, Description, OriginalStart)
    VALUES (@EventID, @FileName, @FileExtension, @FileSize, @FilePath, @UserID, NULLIF(LTRIM(RTRIM(@Description)), N''), @OriginalStart);
    SELECT CAST(1 AS BIT) AS Success, N'فایل ضمیمه شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS CalendarEventAttachmentID;
END
GO
ALTER PROCEDURE dbo.sp_Calendar_GetEventAttachments
    @EventID BIGINT,
    @OriginalStart DATETIME2(0) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT a.CalendarEventAttachmentID, a.EventID, a.FileName, a.FileExtension, a.FileSize, a.FilePath, a.Date_InsertFirst,
           a.Description, CONVERT(VARCHAR(19), a.Date_InsertFirst, 120) AS UploadedAt, -- پچِ ۱۳۷
           dbo.fn_GetUserFullName(u.FirstName, u.LastName, u.UserName) AS UploadedByName
    FROM dbo.CalendarEventAttachments a
    LEFT JOIN dbo.Users u ON u.UserID = a.UserID_InsertFirst
    WHERE a.EventID = @EventID AND a.IsActive = 1 AND (a.OriginalStart IS NULL OR a.OriginalStart = @OriginalStart)
    ORDER BY a.CalendarEventAttachmentID;
END
GO
ALTER PROCEDURE dbo.sp_Calendar_GetEventComments
    @EventID BIGINT,
    @OriginalStart DATETIME2(0) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT C.CommentID, C.EventID, C.CommentText, C.UserID_InsertFirst AS UserID,
           ISNULL(NULLIF(LTRIM(RTRIM(U.FullName)), N''), U.UserName) AS UserName,
           CONVERT(VARCHAR(19), C.Date_InsertFirst, 120) AS CreatedAt,
           CONVERT(VARCHAR(19), C.Date_LastUpdate, 120) AS EditedAt, -- پچِ ۱۳۷: ویرایشِ متن
           ISNULL(NULLIF(LTRIM(RTRIM(EU.FullName)), N''), EU.UserName) AS EditedByName
    FROM dbo.CalendarEventComments C
    LEFT JOIN dbo.Users U ON U.UserID = C.UserID_InsertFirst
    LEFT JOIN dbo.Users EU ON EU.UserID = C.UserID_LastUpdate
    WHERE C.EventID = @EventID AND C.IsActive = 1 AND (C.OriginalStart IS NULL OR C.OriginalStart = @OriginalStart)
    ORDER BY C.CommentID;
END
GO
ALTER PROCEDURE dbo.sp_Calendar_UpdateEventAttachmentDescription
    @CalendarEventAttachmentID INT,
    @EventID     BIGINT,
    @Description NVARCHAR(500) = NULL,
    @UserID      INT,
    @OriginalStart DATETIME2(0) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Creator INT, @Owner INT, @Unit INT;
    SELECT @Creator = CreatedByUserID, @Owner = OwnerUserID, @Unit = OrganizationalUnitID FROM dbo.CalendarEvents WHERE EventID = @EventID AND IsActive = 1;
    IF @Creator IS NULL
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'رویداد یافت نشد.' AS Message; RETURN; END
    IF @UserID <> @Creator AND @UserID <> @Owner
       AND NOT (@Unit IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.fn_UserActiveUnits(@UserID) uu WHERE uu.UnitID = @Unit)
                AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@UserID, N'CALENDAR_UNIT_MANAGE')))
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'فقط ایجادکننده یا مالکِ رویداد می‌تواند این کار را انجام دهد.' AS Message; RETURN; END
    UPDATE dbo.CalendarEventAttachments
    SET Description = NULLIF(LTRIM(RTRIM(@Description)), N''), Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE CalendarEventAttachmentID = @CalendarEventAttachmentID AND EventID = @EventID AND IsActive = 1 AND (OriginalStart IS NULL OR OriginalStart = @OriginalStart);
    IF @@ROWCOUNT = 0
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'ضمیمه یافت نشد.' AS Message; RETURN; END
    SELECT CAST(1 AS BIT) AS Success, N'توضیحِ ضمیمه ثبت شد.' AS Message;
END
GO
ALTER PROCEDURE dbo.sp_Calendar_UpdateEventComment
    @CommentID   BIGINT,
    @EventID     BIGINT,
    @CommentText NVARCHAR(2000),
    @UserID      INT,
    @OriginalStart DATETIME2(0) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET @CommentText = NULLIF(LTRIM(RTRIM(@CommentText)), N'');
    IF @CommentText IS NULL
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'متنِ توضیح خالی است.' AS Message; RETURN; END
    UPDATE C
    SET CommentText = @CommentText, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    FROM dbo.CalendarEventComments C
    INNER JOIN dbo.CalendarEvents E ON E.EventID = C.EventID AND E.IsActive = 1
    WHERE C.CommentID = @CommentID AND C.EventID = @EventID AND C.IsActive = 1 AND (C.OriginalStart IS NULL OR C.OriginalStart = @OriginalStart);
    IF @@ROWCOUNT = 0
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'توضیح یافت نشد.' AS Message; RETURN; END
    SELECT CAST(1 AS BIT) AS Success, N'توضیح ویرایش شد.' AS Message;
END
GO
ALTER PROCEDURE dbo.sp_Calendar_AddEventComment
    @EventID     BIGINT,
    @CommentText NVARCHAR(2000),
    @UserID      INT,
    @OriginalStart DATETIME2(0) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET @CommentText = NULLIF(LTRIM(RTRIM(@CommentText)), N'');
    IF @CommentText IS NULL
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'متنِ توضیح خالی است.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.CalendarEvents WHERE EventID = @EventID AND IsActive = 1)
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'رویداد یافت نشد.' AS Message; RETURN; END

    INSERT INTO dbo.CalendarEventComments (EventID, CommentText, UserID_InsertFirst, OriginalStart) VALUES (@EventID, @CommentText, @UserID, @OriginalStart);
    SELECT CAST(1 AS BIT) AS Success, N'توضیح ثبت شد.' AS Message, CAST(SCOPE_IDENTITY() AS BIGINT) AS CommentID;
END
GO
