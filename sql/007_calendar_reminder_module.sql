/* ==========================================================================
   پچ خودکار شماره: 007 | نام: calendar_reminder_module
   تاریخ: 2026-09-05 12:50:56 | شامل 20 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: CalendarEvents
CREATE TABLE [dbo].[CalendarEvents]
(
    EventID BIGINT IDENTITY(1,1) NOT NULL
        CONSTRAINT PK_CalendarEvents PRIMARY KEY,

    RowGuid UNIQUEIDENTIFIER NOT NULL
        CONSTRAINT DF_CalendarEvents_RowGuid DEFAULT NEWSEQUENTIALID(),

    Title NVARCHAR(250) NOT NULL,
    Description NVARCHAR(MAX) NULL,

    StartDateTime DATETIME2(0) NOT NULL,
    EndDateTime DATETIME2(0) NOT NULL,
    IsAllDay BIT NOT NULL
        CONSTRAINT DF_CalendarEvents_IsAllDay DEFAULT (0),

    Color NVARCHAR(20) NOT NULL
        CONSTRAINT DF_CalendarEvents_Color DEFAULT ('#1677ff'),

    Status NVARCHAR(20) NOT NULL
        CONSTRAINT DF_CalendarEvents_Status DEFAULT ('CONFIRMED')
        CONSTRAINT CK_CalendarEvents_Status CHECK (Status IN ('CONFIRMED','TENTATIVE','CANCELLED')),

    RecurrenceType NVARCHAR(20) NOT NULL
        CONSTRAINT DF_CalendarEvents_RecurrenceType DEFAULT ('NONE')
        CONSTRAINT CK_CalendarEvents_RecurrenceType CHECK (RecurrenceType IN ('NONE','DAILY','WEEKLY','MONTHLY','YEARLY')),
    RecurrenceInterval INT NOT NULL
        CONSTRAINT DF_CalendarEvents_RecurrenceInterval DEFAULT (1),
    RecurrenceEndDate DATE NULL,

    CreatedByUserID INT NOT NULL,

    IsActive BIT NOT NULL
        CONSTRAINT DF_CalendarEvents_IsActive DEFAULT (1),

    Date_InsertFirst DATETIME2(3) NOT NULL
        CONSTRAINT DF_CalendarEvents_Date_InsertFirst DEFAULT SYSDATETIME(),
    UserID_InsertFirst INT NULL,

    Date_LastUpdate DATETIME2(3) NULL,
    UserID_LastUpdate INT NULL
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CalendarEvents_StartEnd
CREATE INDEX IX_CalendarEvents_StartEnd
ON [dbo].[CalendarEvents] (StartDateTime, EndDateTime)
GO

-- [CREATE_INDEX] روی INDEX: IX_CalendarEvents_CreatedByUserID
CREATE INDEX IX_CalendarEvents_CreatedByUserID
ON [dbo].[CalendarEvents] (CreatedByUserID)
GO

-- [CREATE_INDEX] روی INDEX: IX_CalendarEvents_IsActive
CREATE INDEX IX_CalendarEvents_IsActive
ON [dbo].[CalendarEvents] (IsActive)
GO

-- [CREATE_TABLE] روی TABLE: CalendarEventAttendees
CREATE TABLE [dbo].[CalendarEventAttendees]
(
    CalendarEventAttendeeID BIGINT IDENTITY(1,1) NOT NULL
        CONSTRAINT PK_CalendarEventAttendees PRIMARY KEY,

    RowGuid UNIQUEIDENTIFIER NOT NULL
        CONSTRAINT DF_CalendarEventAttendees_RowGuid DEFAULT NEWSEQUENTIALID(),

    EventID BIGINT NOT NULL,
    UserID INT NOT NULL,

    IsActive BIT NOT NULL
        CONSTRAINT DF_CalendarEventAttendees_IsActive DEFAULT (1),

    Date_InsertFirst DATETIME2(3) NOT NULL
        CONSTRAINT DF_CalendarEventAttendees_Date_InsertFirst DEFAULT SYSDATETIME(),
    UserID_InsertFirst INT NULL,

    CONSTRAINT UQ_CalendarEventAttendees_Event_User UNIQUE (EventID, UserID),
    CONSTRAINT FK_CalendarEventAttendees_CalendarEvents
        FOREIGN KEY (EventID) REFERENCES [dbo].[CalendarEvents](EventID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CalendarEventAttendees_UserID
CREATE INDEX IX_CalendarEventAttendees_UserID
ON [dbo].[CalendarEventAttendees] (UserID)
GO

-- [CREATE_TABLE] روی TABLE: Reminders
CREATE TABLE [dbo].[Reminders]
(
    ReminderID BIGINT IDENTITY(1,1) NOT NULL
        CONSTRAINT PK_Reminders PRIMARY KEY,

    RowGuid UNIQUEIDENTIFIER NOT NULL
        CONSTRAINT DF_Reminders_RowGuid DEFAULT NEWSEQUENTIALID(),

    EntityType NVARCHAR(50) NOT NULL,   -- مثلا: CALENDAR_EVENT, PROJECT_TASK, PROJECT, MESSAGE
    EntityID BIGINT NOT NULL,

    UserID INT NOT NULL,                -- کاربری که یادآوری برایش نمایش داده می‌شود

    OffsetMinutes INT NOT NULL,         -- 0 = هنگام شروع، 5/10/15/30/60/1440 = دقیقه قبل
    RemindAt DATETIME2(0) NOT NULL,     -- زمان دقیق محاسبه‌شده یادآوری (StartDateTime - OffsetMinutes)

    IsSent BIT NOT NULL
        CONSTRAINT DF_Reminders_IsSent DEFAULT (0),
    SentDate DATETIME2(3) NULL,

    IsActive BIT NOT NULL
        CONSTRAINT DF_Reminders_IsActive DEFAULT (1),

    Date_InsertFirst DATETIME2(3) NOT NULL
        CONSTRAINT DF_Reminders_Date_InsertFirst DEFAULT SYSDATETIME(),
    UserID_InsertFirst INT NULL,

    Date_LastUpdate DATETIME2(3) NULL,
    UserID_LastUpdate INT NULL,

    CONSTRAINT UQ_Reminders_Entity_User UNIQUE (EntityType, EntityID, UserID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_Reminders_EntityType_EntityID
CREATE INDEX IX_Reminders_EntityType_EntityID
ON [dbo].[Reminders] (EntityType, EntityID)
GO

-- [CREATE_INDEX] روی INDEX: IX_Reminders_RemindAt_IsSent
CREATE INDEX IX_Reminders_RemindAt_IsSent
ON [dbo].[Reminders] (RemindAt, IsSent)
GO

-- [CREATE_INDEX] روی INDEX: IX_Reminders_UserID
CREATE INDEX IX_Reminders_UserID
ON [dbo].[Reminders] (UserID)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetCalendarEvents
-- ============================================================
-- Stored Procedures
-- ============================================================

-- دریافت رویدادهای بازه‌ی زمانی (برای نمای ماه/هفته/روز)
CREATE PROCEDURE [dbo].[sp_GetCalendarEvents]
    @UserID    INT,
    @FromDate  DATETIME2(0),
    @ToDate    DATETIME2(0)
AS
BEGIN
    SET NOCOUNT ON;

    SELECT DISTINCT
        e.EventID,
        e.Title,
        e.Description,
        e.StartDateTime,
        e.EndDateTime,
        e.IsAllDay,
        e.Color,
        e.Status,
        e.RecurrenceType,
        e.RecurrenceInterval,
        e.RecurrenceEndDate,
        e.CreatedByUserID,
        dbo.fn_GetUserFullName(u.FirstName, u.LastName, u.UserName) AS CreatorName,
        CAST(CASE WHEN e.CreatedByUserID = @UserID THEN 1 ELSE 0 END AS BIT) AS CanManage
    FROM dbo.CalendarEvents e
    LEFT JOIN dbo.Users u ON u.UserID = e.CreatedByUserID
    LEFT JOIN dbo.CalendarEventAttendees a
        ON a.EventID = e.EventID AND a.UserID = @UserID AND a.IsActive = 1
    WHERE e.IsActive = 1
      AND (e.CreatedByUserID = @UserID OR a.CalendarEventAttendeeID IS NOT NULL)
      AND (
            -- رویداد غیرتکرارشونده که با بازه تلاقی دارد
            (e.RecurrenceType = 'NONE' AND e.StartDateTime <= @ToDate AND e.EndDateTime >= @FromDate)
            OR
            -- رویداد تکرارشونده که ممکن است در این بازه رخداد داشته باشد
            (e.RecurrenceType <> 'NONE' AND e.StartDateTime <= @ToDate
             AND (e.RecurrenceEndDate IS NULL OR e.RecurrenceEndDate >= CAST(@FromDate AS DATE)))
          )
    ORDER BY e.StartDateTime;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetCalendarEvent
-- دریافت یک رویداد به همراه جزئیات
CREATE PROCEDURE [dbo].[sp_GetCalendarEvent]
    @EventID INT,
    @UserID  INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        e.EventID,
        e.Title,
        e.Description,
        e.StartDateTime,
        e.EndDateTime,
        e.IsAllDay,
        e.Color,
        e.Status,
        e.RecurrenceType,
        e.RecurrenceInterval,
        e.RecurrenceEndDate,
        e.CreatedByUserID,
        dbo.fn_GetUserFullName(u.FirstName, u.LastName, u.UserName) AS CreatorName,
        e.Date_InsertFirst,
        e.Date_LastUpdate,
        CAST(CASE WHEN e.CreatedByUserID = @UserID THEN 1 ELSE 0 END AS BIT) AS CanManage
    FROM dbo.CalendarEvents e
    LEFT JOIN dbo.Users u ON u.UserID = e.CreatedByUserID
    WHERE e.EventID = @EventID AND e.IsActive = 1;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetCalendarEventAttendees
-- دریافت اعضا/کاربران مرتبط یک رویداد
CREATE PROCEDURE [dbo].[sp_GetCalendarEventAttendees]
    @EventID BIGINT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        a.CalendarEventAttendeeID,
        a.EventID,
        a.UserID,
        dbo.fn_GetUserFullName(u.FirstName, u.LastName, u.UserName) AS FullName
    FROM dbo.CalendarEventAttendees a
    LEFT JOIN dbo.Users u ON u.UserID = a.UserID
    WHERE a.EventID = @EventID AND a.IsActive = 1
    ORDER BY FullName;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_InsertCalendarEvent
-- ایجاد رویداد جدید (همراه با کاربران مرتبط)
CREATE PROCEDURE [dbo].[sp_InsertCalendarEvent]
    @Title              NVARCHAR(250),
    @Description        NVARCHAR(MAX) = NULL,
    @StartDateTime      DATETIME2(0),
    @EndDateTime        DATETIME2(0),
    @IsAllDay           BIT = 0,
    @Color              NVARCHAR(20) = '#1677ff',
    @Status             NVARCHAR(20) = 'CONFIRMED',
    @RecurrenceType     NVARCHAR(20) = 'NONE',
    @RecurrenceInterval INT = 1,
    @RecurrenceEndDate  DATE = NULL,
    @AttendeeUserIDs    NVARCHAR(MAX) = NULL,  -- لیست شناسه کاربران با ویرگول
    @CreateUser         INT
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF @EndDateTime < @StartDateTime
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'تاریخ/ساعت پایان نمی‌تواند قبل از شروع باشد.' AS Message;
            RETURN;
        END

        DECLARE @NewEventID BIGINT;

        INSERT INTO dbo.CalendarEvents
            (Title, Description, StartDateTime, EndDateTime, IsAllDay, Color, Status,
             RecurrenceType, RecurrenceInterval, RecurrenceEndDate,
             CreatedByUserID, Date_InsertFirst, UserID_InsertFirst)
        VALUES
            (@Title, @Description, @StartDateTime, @EndDateTime, @IsAllDay, @Color, @Status,
             @RecurrenceType, @RecurrenceInterval, @RecurrenceEndDate,
             @CreateUser, SYSDATETIME(), @CreateUser);

        SET @NewEventID = SCOPE_IDENTITY();

        IF @AttendeeUserIDs IS NOT NULL AND LTRIM(RTRIM(@AttendeeUserIDs)) <> ''
        BEGIN
            INSERT INTO dbo.CalendarEventAttendees (EventID, UserID, Date_InsertFirst, UserID_InsertFirst)
            SELECT @NewEventID, CAST(LTRIM(RTRIM(value)) AS INT), SYSDATETIME(), @CreateUser
            FROM STRING_SPLIT(@AttendeeUserIDs, ',')
            WHERE LTRIM(RTRIM(value)) <> '' AND CAST(LTRIM(RTRIM(value)) AS INT) <> @CreateUser;
        END

        SELECT CAST(1 AS BIT) AS Success, N'رویداد با موفقیت ایجاد شد.' AS Message, @NewEventID AS NewEventID;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ایجاد رویداد: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_UpdateCalendarEvent
-- ویرایش رویداد (فقط ایجادکننده)
CREATE PROCEDURE [dbo].[sp_UpdateCalendarEvent]
    @EventID            BIGINT,
    @Title              NVARCHAR(250),
    @Description        NVARCHAR(MAX) = NULL,
    @StartDateTime      DATETIME2(0),
    @EndDateTime        DATETIME2(0),
    @IsAllDay           BIT = 0,
    @Color              NVARCHAR(20) = '#1677ff',
    @Status             NVARCHAR(20) = 'CONFIRMED',
    @RecurrenceType     NVARCHAR(20) = 'NONE',
    @RecurrenceInterval INT = 1,
    @RecurrenceEndDate  DATE = NULL,
    @AttendeeUserIDs    NVARCHAR(MAX) = NULL,
    @ModifyUser         INT
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF NOT EXISTS (SELECT 1 FROM dbo.CalendarEvents WHERE EventID = @EventID AND IsActive = 1)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'رویداد یافت نشد.' AS Message;
            RETURN;
        END

        IF NOT EXISTS (SELECT 1 FROM dbo.CalendarEvents WHERE EventID = @EventID AND CreatedByUserID = @ModifyUser)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'فقط ایجادکننده رویداد می‌تواند آن را ویرایش کند.' AS Message;
            RETURN;
        END

        IF @EndDateTime < @StartDateTime
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'تاریخ/ساعت پایان نمی‌تواند قبل از شروع باشد.' AS Message;
            RETURN;
        END

        UPDATE dbo.CalendarEvents SET
            Title               = @Title,
            Description         = @Description,
            StartDateTime       = @StartDateTime,
            EndDateTime         = @EndDateTime,
            IsAllDay            = @IsAllDay,
            Color               = @Color,
            Status              = @Status,
            RecurrenceType      = @RecurrenceType,
            RecurrenceInterval  = @RecurrenceInterval,
            RecurrenceEndDate   = @RecurrenceEndDate,
            Date_LastUpdate     = SYSDATETIME(),
            UserID_LastUpdate   = @ModifyUser
        WHERE EventID = @EventID;

        -- جایگزینی کامل لیست کاربران مرتبط
        UPDATE dbo.CalendarEventAttendees SET IsActive = 0
        WHERE EventID = @EventID;

        IF @AttendeeUserIDs IS NOT NULL AND LTRIM(RTRIM(@AttendeeUserIDs)) <> ''
        BEGIN
            MERGE dbo.CalendarEventAttendees AS target
            USING (
                SELECT CAST(LTRIM(RTRIM(value)) AS INT) AS UserID
                FROM STRING_SPLIT(@AttendeeUserIDs, ',')
                WHERE LTRIM(RTRIM(value)) <> '' AND CAST(LTRIM(RTRIM(value)) AS INT) <> @ModifyUser
            ) AS src
            ON target.EventID = @EventID AND target.UserID = src.UserID
            WHEN MATCHED THEN
                UPDATE SET IsActive = 1
            WHEN NOT MATCHED THEN
                INSERT (EventID, UserID, IsActive, Date_InsertFirst, UserID_InsertFirst)
                VALUES (@EventID, src.UserID, 1, SYSDATETIME(), @ModifyUser);
        END

        SELECT CAST(1 AS BIT) AS Success, N'رویداد با موفقیت ویرایش شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ویرایش رویداد: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_DeleteCalendarEvent
-- حذف رویداد (Soft Delete هماهنگ با الگوی IsActive پروژه، فقط ایجادکننده)
CREATE PROCEDURE [dbo].[sp_DeleteCalendarEvent]
    @EventID    BIGINT,
    @ModifyUser INT
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF NOT EXISTS (SELECT 1 FROM dbo.CalendarEvents WHERE EventID = @EventID AND CreatedByUserID = @ModifyUser)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'فقط ایجادکننده رویداد می‌تواند آن را حذف کند.' AS Message;
            RETURN;
        END

        UPDATE dbo.CalendarEvents
        SET IsActive = 0, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ModifyUser
        WHERE EventID = @EventID;

        UPDATE dbo.Reminders
        SET IsActive = 0
        WHERE EntityType = 'CALENDAR_EVENT' AND EntityID = @EventID;

        SELECT CAST(1 AS BIT) AS Success, N'رویداد با موفقیت حذف شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در حذف رویداد: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetReminder
-- دریافت تنظیم یادآوری کاربر برای یک موجودیت (عمومی)
CREATE PROCEDURE [dbo].[sp_GetReminder]
    @EntityType NVARCHAR(50),
    @EntityID   BIGINT,
    @UserID     INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT TOP 1
        ReminderID, EntityType, EntityID, UserID, OffsetMinutes, RemindAt, IsSent
    FROM dbo.Reminders
    WHERE EntityType = @EntityType AND EntityID = @EntityID AND UserID = @UserID AND IsActive = 1;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_SaveReminder
-- ذخیره (ایجاد/ویرایش) یادآوری کاربر برای یک موجودیت (عمومی)
CREATE PROCEDURE [dbo].[sp_SaveReminder]
    @EntityType          NVARCHAR(50),
    @EntityID            BIGINT,
    @UserID              INT,
    @OffsetMinutes       INT = NULL,           -- NULL = بدون یادآوری (حذف تنظیم)
    @EntityStartDateTime DATETIME2(0),
    @CreateUser          INT
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF @OffsetMinutes IS NULL
        BEGIN
            UPDATE dbo.Reminders SET IsActive = 0
            WHERE EntityType = @EntityType AND EntityID = @EntityID AND UserID = @UserID;

            SELECT CAST(1 AS BIT) AS Success, N'یادآوری حذف شد.' AS Message;
            RETURN;
        END

        DECLARE @RemindAt DATETIME2(0) = DATEADD(MINUTE, -1 * @OffsetMinutes, @EntityStartDateTime);

        MERGE dbo.Reminders AS target
        USING (SELECT @EntityType AS EntityType, @EntityID AS EntityID, @UserID AS UserID) AS src
        ON target.EntityType = src.EntityType AND target.EntityID = src.EntityID AND target.UserID = src.UserID
        WHEN MATCHED THEN
            UPDATE SET
                OffsetMinutes     = @OffsetMinutes,
                RemindAt          = @RemindAt,
                IsSent            = 0,
                SentDate          = NULL,
                IsActive          = 1,
                Date_LastUpdate   = SYSDATETIME(),
                UserID_LastUpdate = @CreateUser
        WHEN NOT MATCHED THEN
            INSERT (EntityType, EntityID, UserID, OffsetMinutes, RemindAt, IsActive, Date_InsertFirst, UserID_InsertFirst)
            VALUES (@EntityType, @EntityID, @UserID, @OffsetMinutes, @RemindAt, 1, SYSDATETIME(), @CreateUser);

        SELECT CAST(1 AS BIT) AS Success, N'یادآوری ذخیره شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ذخیره یادآوری: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetDueCalendarReminders
-- یادآوری‌های سررسیدشده‌ی کاربر برای رویدادهای تقویم (برای نمایش داخل خود صفحه تقویم)
CREATE PROCEDURE [dbo].[sp_GetDueCalendarReminders]
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        r.ReminderID,
        r.EntityID AS EventID,
        e.Title,
        e.StartDateTime,
        r.OffsetMinutes,
        r.RemindAt
    FROM dbo.Reminders r
    INNER JOIN dbo.CalendarEvents e ON e.EventID = r.EntityID AND e.IsActive = 1
    WHERE r.EntityType = 'CALENDAR_EVENT'
      AND r.UserID = @UserID
      AND r.IsActive = 1
      AND r.IsSent = 0
      AND r.RemindAt <= SYSDATETIME();
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_MarkReminderSent
-- علامت‌گذاری یادآوری به‌عنوان نمایش‌داده‌شده
CREATE PROCEDURE [dbo].[sp_MarkReminderSent]
    @ReminderID BIGINT
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE dbo.Reminders
    SET IsSent = 1, SentDate = SYSDATETIME()
    WHERE ReminderID = @ReminderID;
END
GO


/* ==========================================================================
   [MANUAL DATA SEED] — این بخش به‌صورت دستی اضافه شده است، نه توسط تریگر DDL.
   تریگر trg_DevTrackSchemaChanges فقط رویدادهای DDL_DATABASE_LEVEL_EVENTS را
   ثبت می‌کند و INSERT های داده‌ای (DML) را ردیابی نمی‌کند؛ بنابراین درج ردیف
   منوی «تقویم و یادآوری‌ها» باید برای هماهنگی بین محیط‌ها (dev/staging/prod)
   همراه همین پچ اعمال شود.
   ========================================================================== */

-- [DATA_SEED] افزودن منوی «تقویم و یادآوری‌ها» (در صورت عدم وجود)
IF NOT EXISTS (SELECT 1 FROM dbo.Menu WHERE MenuCode = N'CALENDAR')
BEGIN
    DECLARE @CalendarMenuID INT;

    INSERT INTO dbo.Menu (ParentID, MenuCode, MenuTitle, MenuKind, Url, Icon, Level, SortOrder, OpenInNewTab, IsVisible, IsActive, IsHomeTab, CreateDate)
    VALUES (NULL, N'CALENDAR', N'تقویم و یادآوری‌ها', N'PAGE', N'/calendar', N'bi bi-calendar3', 1, 2, 0, 1, 1, 0, SYSDATETIME());

    SET @CalendarMenuID = SCOPE_IDENTITY();

    -- دسترسی پیش‌فرض برای نقش مدیر سیستم (RoleID = 1)؛ سایر نقش‌ها از صفحه
    -- «نقش‌ها > دسترسی‌ها» می‌توانند این منو را به‌صورت دستی فعال کنند.
    IF EXISTS (SELECT 1 FROM dbo.Roles WHERE RoleID = 1)
    BEGIN
        INSERT INTO dbo.RoleMenus (RoleID, MenuID, CanView, CreateDate, IsActive)
        VALUES (1, @CalendarMenuID, 1, SYSDATETIME(), 1);
    END
END
GO
