/* ==========================================================================
   پچ خودکار شماره: 117 | نام: calendar_event_attachments
   تاریخ: 2026-10-23 10:24:46 | شامل 5 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: CalendarEventAttachments
CREATE TABLE dbo.CalendarEventAttachments
(
    CalendarEventAttachmentID INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CalendarEventAttachments PRIMARY KEY,
    EventID            BIGINT           NOT NULL CONSTRAINT FK_CalendarEventAttachments_Event REFERENCES dbo.CalendarEvents (EventID),
    FileName           NVARCHAR(255)    NOT NULL,
    FileExtension      NVARCHAR(20)     NULL,
    FileSize           BIGINT           NOT NULL CONSTRAINT CK_CalendarEventAttachments_FileSize CHECK (FileSize >= 0),
    FilePath           NVARCHAR(1000)   NOT NULL,
    IsActive           BIT              NOT NULL CONSTRAINT DF_CalendarEventAttachments_IsActive DEFAULT (1),
    RowGuid            UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CalendarEventAttachments_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2(0)     NOT NULL CONSTRAINT DF_CalendarEventAttachments_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT              NULL,
    Date_LastUpdate    DATETIME2(0)     NULL,
    UserID_LastUpdate  INT              NULL
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CalendarEventAttachments_Event
CREATE INDEX IX_CalendarEventAttachments_Event ON dbo.CalendarEventAttachments (EventID) INCLUDE (IsActive)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_AddEventAttachment
/* افزودنِ یک ضمیمه — فقط سازنده، مالک یا مدیرِ رویدادِ واحد (همان قاعدهٔ ویرایش)؛ رویدادِ حذف‌شده نه */
CREATE PROCEDURE dbo.sp_Calendar_AddEventAttachment
    @EventID       BIGINT,
    @FileName      NVARCHAR(255),
    @FileExtension NVARCHAR(20) = NULL,
    @FileSize      BIGINT,
    @FilePath      NVARCHAR(1000),
    @UserID        INT
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

    INSERT INTO dbo.CalendarEventAttachments (EventID, FileName, FileExtension, FileSize, FilePath, UserID_InsertFirst)
    VALUES (@EventID, @FileName, @FileExtension, @FileSize, @FilePath, @UserID);
    SELECT CAST(1 AS BIT) AS Success, N'فایل ضمیمه شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS CalendarEventAttachmentID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_GetEventAttachments
/* ضمیمه‌هایِ فعالِ یک رویداد (دسترسیِ مشاهده در برنامه با همان قاعدهٔ جزئیاتِ رویداد بررسی می‌شود) */
CREATE PROCEDURE dbo.sp_Calendar_GetEventAttachments
    @EventID BIGINT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT a.CalendarEventAttachmentID, a.EventID, a.FileName, a.FileExtension, a.FileSize, a.FilePath, a.Date_InsertFirst,
           dbo.fn_GetUserFullName(u.FirstName, u.LastName, u.UserName) AS UploadedByName
    FROM dbo.CalendarEventAttachments a
    LEFT JOIN dbo.Users u ON u.UserID = a.UserID_InsertFirst
    WHERE a.EventID = @EventID AND a.IsActive = 1
    ORDER BY a.CalendarEventAttachmentID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_GetEventAttachment
/* یک ضمیمهٔ فعال برایِ دانلود (EventID برایِ بررسیِ دسترسی در برنامه) */
CREATE PROCEDURE dbo.sp_Calendar_GetEventAttachment
    @AttachmentID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT a.CalendarEventAttachmentID, a.EventID, a.FileName, a.FilePath
    FROM dbo.CalendarEventAttachments a
    INNER JOIN dbo.CalendarEvents e ON e.EventID = a.EventID AND e.IsActive = 1
    WHERE a.CalendarEventAttachmentID = @AttachmentID AND a.IsActive = 1;
END
GO

