/* ==========================================================================
   پچ خودکار شماره: 137 | نام: calendar_attachment_note_content_edit
   تاریخ: 2026-11-03 16:54:20 | شامل 8 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: CalendarEventAttachments
ALTER TABLE dbo.CalendarEventAttachments ADD Description NVARCHAR(500) NULL
GO

-- [ALTER_TABLE] روی TABLE: CalendarEventComments
ALTER TABLE dbo.CalendarEventComments ADD Date_LastUpdate DATETIME2(0) NULL, UserID_LastUpdate INT NULL
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Calendar_AddEventAttachment
/* افزودنِ یک ضمیمه — فقط سازنده، مالک یا مدیرِ رویدادِ واحد (همان قاعدهٔ ویرایش)؛ رویدادِ حذف‌شده نه */
ALTER PROCEDURE dbo.sp_Calendar_AddEventAttachment
    @EventID       BIGINT,
    @FileName      NVARCHAR(255),
    @FileExtension NVARCHAR(20) = NULL,
    @FileSize      BIGINT,
    @FilePath      NVARCHAR(1000),
    @UserID        INT,
    @Description   NVARCHAR(500) = NULL -- پچِ ۱۳۷
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

    INSERT INTO dbo.CalendarEventAttachments (EventID, FileName, FileExtension, FileSize, FilePath, UserID_InsertFirst, Description)
    VALUES (@EventID, @FileName, @FileExtension, @FileSize, @FilePath, @UserID, NULLIF(LTRIM(RTRIM(@Description)), N''));
    SELECT CAST(1 AS BIT) AS Success, N'فایل ضمیمه شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS CalendarEventAttachmentID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Calendar_GetEventAttachments
/* ضمیمه‌هایِ فعالِ یک رویداد (دسترسیِ مشاهده در برنامه با همان قاعدهٔ جزئیاتِ رویداد بررسی می‌شود) */
ALTER PROCEDURE dbo.sp_Calendar_GetEventAttachments
    @EventID BIGINT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT a.CalendarEventAttachmentID, a.EventID, a.FileName, a.FileExtension, a.FileSize, a.FilePath, a.Date_InsertFirst,
           a.Description, CONVERT(VARCHAR(19), a.Date_InsertFirst, 120) AS UploadedAt, -- پچِ ۱۳۷
           dbo.fn_GetUserFullName(u.FirstName, u.LastName, u.UserName) AS UploadedByName
    FROM dbo.CalendarEventAttachments a
    LEFT JOIN dbo.Users u ON u.UserID = a.UserID_InsertFirst
    WHERE a.EventID = @EventID AND a.IsActive = 1
    ORDER BY a.CalendarEventAttachmentID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Calendar_GetEventComments
-- پچِ ۱۲۹: فهرستِ توضیحاتِ یک رویداد (قدیمی به جدید) با نامِ نویسنده — دسترسی در کنترلر بررسی می‌شود
ALTER PROCEDURE dbo.sp_Calendar_GetEventComments
    @EventID BIGINT
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
    WHERE C.EventID = @EventID AND C.IsActive = 1
    ORDER BY C.CommentID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_UpdateEventAttachmentDescription
-- پچِ ۱۳۷: توضیحِ یک ضمیمهٔ رویداد (ثبت یا تغییر) — همان قاعدهٔ افزودنِ ضمیمه
CREATE PROCEDURE dbo.sp_Calendar_UpdateEventAttachmentDescription
    @CalendarEventAttachmentID INT,
    @EventID     BIGINT,
    @Description NVARCHAR(500) = NULL,
    @UserID      INT
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
    WHERE CalendarEventAttachmentID = @CalendarEventAttachmentID AND EventID = @EventID AND IsActive = 1;
    IF @@ROWCOUNT = 0
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'ضمیمه یافت نشد.' AS Message; RETURN; END
    SELECT CAST(1 AS BIT) AS Success, N'توضیحِ ضمیمه ثبت شد.' AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_UpdateEventContent
-- پچِ ۱۳۷: ویرایشِ موضوع و متنِ رویداد (مجوزِ CALENDAR_EDIT_CONTENT در برنامه) — فقط سازنده، مالک یا مدیرِ رویدادِ واحد
CREATE PROCEDURE dbo.sp_Calendar_UpdateEventContent
    @EventID     BIGINT,
    @Title       NVARCHAR(250),
    @Description NVARCHAR(MAX) = NULL,
    @UserID      INT
AS
BEGIN
    SET NOCOUNT ON;
    SET @Title = NULLIF(LTRIM(RTRIM(@Title)), N'');
    IF @Title IS NULL
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'موضوعِ رویداد الزامی است.' AS Message; RETURN; END
    DECLARE @Creator INT, @Owner INT, @Unit INT;
    SELECT @Creator = CreatedByUserID, @Owner = OwnerUserID, @Unit = OrganizationalUnitID FROM dbo.CalendarEvents WHERE EventID = @EventID AND IsActive = 1;
    IF @Creator IS NULL
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'رویداد یافت نشد.' AS Message; RETURN; END
    IF @UserID <> @Creator AND @UserID <> @Owner
       AND NOT (@Unit IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.fn_UserActiveUnits(@UserID) uu WHERE uu.UnitID = @Unit)
                AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@UserID, N'CALENDAR_UNIT_MANAGE')))
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'فقط ایجادکننده یا مالکِ رویداد می‌تواند این کار را انجام دهد.' AS Message; RETURN; END
    UPDATE dbo.CalendarEvents
    SET Title = @Title, Description = NULLIF(LTRIM(RTRIM(@Description)), N''), Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE EventID = @EventID AND IsActive = 1;
    SELECT CAST(1 AS BIT) AS Success, N'موضوع و متنِ رویداد ویرایش شد.' AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_UpdateEventComment
-- پچِ ۱۳۷: ویرایشِ متنِ یک توضیحِ رویداد (مجوزِ CALENDAR_COMMENT_EDIT و دسترسیِ جزئیاتِ رویداد در برنامه)؛ ویرایشگر و زمان ثبت می‌شود
CREATE PROCEDURE dbo.sp_Calendar_UpdateEventComment
    @CommentID   BIGINT,
    @EventID     BIGINT,
    @CommentText NVARCHAR(2000),
    @UserID      INT
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
    WHERE C.CommentID = @CommentID AND C.EventID = @EventID AND C.IsActive = 1;
    IF @@ROWCOUNT = 0
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'توضیح یافت نشد.' AS Message; RETURN; END
    SELECT CAST(1 AS BIT) AS Success, N'توضیح ویرایش شد.' AS Message;
END
GO


-- [DML — دستی، خارج از DDL Trigger] مجوزهایِ «ویرایشِ موضوع و متنِ رویداد» و «ویرایشِ متنِ توضیحاتِ رویداد» (در «دسترسی‌های عملیاتی»ِ نقش‌ها قابلِ اعطا) و اعطایِ پیش‌فرض به RoleID = 1
IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE PermissionCode = N'CALENDAR_EDIT_CONTENT')
    INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive)
    VALUES (N'CALENDAR_EDIT_CONTENT', N'ویرایشِ موضوع و متنِ رویداد', N'Calendar', N'تغییرِ موضوع و متنِ رویدادهایی که کاربر ایجادکننده، مالک یا مدیرِ (واحدِ) آن‌هاست', 11, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE PermissionCode = N'CALENDAR_COMMENT_EDIT')
    INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive)
    VALUES (N'CALENDAR_COMMENT_EDIT', N'ویرایشِ متنِ توضیحاتِ رویداد', N'Calendar', N'ویرایشِ متنِ توضیحاتِ رویدادهایی که کاربر به جزئیاتشان دسترسی دارد (ویرایشگر و زمان ثبت می‌شود)', 12, 1);
IF EXISTS (SELECT 1 FROM dbo.Roles WHERE RoleID = 1)
    INSERT INTO dbo.RolePermissions (RoleID, PermissionID, CanAccess, IsActive)
    SELECT 1, p.PermissionID, 1, 1
    FROM dbo.Permissions p
    WHERE p.PermissionCode IN (N'CALENDAR_EDIT_CONTENT', N'CALENDAR_COMMENT_EDIT')
      AND NOT EXISTS (SELECT 1 FROM dbo.RolePermissions rp WHERE rp.RoleID = 1 AND rp.PermissionID = p.PermissionID);
GO
