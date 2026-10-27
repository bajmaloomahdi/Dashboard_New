/* ==========================================================================
   پچ خودکار شماره: 129 | نام: calendar_event_comments
   تاریخ: 2026-10-27 17:33:03 | شامل 4 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: CalendarEventComments
CREATE TABLE dbo.CalendarEventComments (
    CommentID          BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CalendarEventComments PRIMARY KEY,
    EventID            BIGINT         NOT NULL CONSTRAINT FK_CalendarEventComments_Event REFERENCES dbo.CalendarEvents (EventID),
    CommentText        NVARCHAR(2000) NOT NULL,
    IsActive           BIT            NOT NULL CONSTRAINT DF_CalendarEventComments_IsActive DEFAULT 1,
    Date_InsertFirst   DATETIME2(0)   NOT NULL CONSTRAINT DF_CalendarEventComments_Date DEFAULT SYSDATETIME(),
    UserID_InsertFirst INT            NOT NULL
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CalendarEventComments_Event
CREATE INDEX IX_CalendarEventComments_Event ON dbo.CalendarEventComments (EventID, CommentID)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_GetEventComments
-- پچِ ۱۲۹: فهرستِ توضیحاتِ یک رویداد (قدیمی به جدید) با نامِ نویسنده — دسترسی در کنترلر بررسی می‌شود
CREATE PROCEDURE dbo.sp_Calendar_GetEventComments
    @EventID BIGINT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT C.CommentID, C.EventID, C.CommentText, C.UserID_InsertFirst AS UserID,
           ISNULL(NULLIF(LTRIM(RTRIM(U.FullName)), N''), U.UserName) AS UserName,
           CONVERT(VARCHAR(19), C.Date_InsertFirst, 120) AS CreatedAt
    FROM dbo.CalendarEventComments C
    LEFT JOIN dbo.Users U ON U.UserID = C.UserID_InsertFirst
    WHERE C.EventID = @EventID AND C.IsActive = 1
    ORDER BY C.CommentID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_AddEventComment
-- پچِ ۱۲۹: ثبتِ توضیح برایِ رویداد
CREATE PROCEDURE dbo.sp_Calendar_AddEventComment
    @EventID     BIGINT,
    @CommentText NVARCHAR(2000),
    @UserID      INT
AS
BEGIN
    SET NOCOUNT ON;
    SET @CommentText = NULLIF(LTRIM(RTRIM(@CommentText)), N'');
    IF @CommentText IS NULL
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'متنِ توضیح خالی است.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.CalendarEvents WHERE EventID = @EventID AND IsActive = 1)
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'رویداد یافت نشد.' AS Message; RETURN; END

    INSERT INTO dbo.CalendarEventComments (EventID, CommentText, UserID_InsertFirst) VALUES (@EventID, @CommentText, @UserID);
    SELECT CAST(1 AS BIT) AS Success, N'توضیح ثبت شد.' AS Message, CAST(SCOPE_IDENTITY() AS BIGINT) AS CommentID;
END
GO




-- [DML — دستی، خارج از DDL Trigger] مجوزهایِ توضیحاتِ رویداد (در «دسترسی‌های عملیاتی»ِ نقش‌ها قابلِ اعطا) و اعطایِ پیش‌فرض به RoleID = 1
IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE PermissionCode = N'CALENDAR_COMMENT_VIEW')
    INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive)
    VALUES (N'CALENDAR_COMMENT_VIEW', N'دیدنِ توضیحاتِ رویداد', N'Calendar', N'نمایشِ تبِ «توضیحات»ِ رویدادهایی که کاربر به جزئیاتشان دسترسی دارد (نویسنده و زمانِ هر توضیح)', 9, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE PermissionCode = N'CALENDAR_COMMENT_ADD')
    INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive)
    VALUES (N'CALENDAR_COMMENT_ADD', N'ثبتِ توضیح برایِ رویداد', N'Calendar', N'افزودنِ توضیح به رویدادهایی که کاربر به جزئیاتشان دسترسی دارد', 10, 1);
IF EXISTS (SELECT 1 FROM dbo.Roles WHERE RoleID = 1)
    INSERT INTO dbo.RolePermissions (RoleID, PermissionID, CanAccess, IsActive)
    SELECT 1, p.PermissionID, 1, 1
    FROM dbo.Permissions p
    WHERE p.PermissionCode IN (N'CALENDAR_COMMENT_VIEW', N'CALENDAR_COMMENT_ADD')
      AND NOT EXISTS (SELECT 1 FROM dbo.RolePermissions rp WHERE rp.RoleID = 1 AND rp.PermissionID = p.PermissionID);
GO
