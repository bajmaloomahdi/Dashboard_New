/* ==========================================================================
   پچ خودکار شماره: 110 | نام: calendar_sharing
   تاریخ: 2026-10-19 10:18:35 | شامل 14 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: CalendarShares
CREATE TABLE dbo.CalendarShares (
    CalendarShareID    INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CalendarShares PRIMARY KEY,
    RowGuid            UNIQUEIDENTIFIER  NOT NULL CONSTRAINT DF_CalendarShares_RowGuid DEFAULT (NEWSEQUENTIALID()),
    OwnerUserID        INT               NOT NULL CONSTRAINT FK_CalendarShares_Owner REFERENCES dbo.Users (UserID),
    SharedWithUserID   INT               NOT NULL CONSTRAINT FK_CalendarShares_SharedWith REFERENCES dbo.Users (UserID),
    AccessLevel        NVARCHAR(20)      NOT NULL CONSTRAINT CK_CalendarShares_AccessLevel CHECK (AccessLevel IN (N'FREEBUSY', N'READ')),
    IsActive           BIT               NOT NULL CONSTRAINT DF_CalendarShares_IsActive DEFAULT (1),
    Date_InsertFirst   DATETIME2(0)      NOT NULL CONSTRAINT DF_CalendarShares_Date_InsertFirst DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT               NULL,
    Date_LastUpdate    DATETIME2(0)      NULL,
    UserID_LastUpdate  INT               NULL,
    CONSTRAINT UQ_CalendarShares_Owner_SharedWith UNIQUE (OwnerUserID, SharedWithUserID),
    CONSTRAINT CK_CalendarShares_NotSelf CHECK (OwnerUserID <> SharedWithUserID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CalendarShares_SharedWith
CREATE INDEX IX_CalendarShares_SharedWith ON dbo.CalendarShares (SharedWithUserID) INCLUDE (OwnerUserID, AccessLevel, IsActive)
GO

-- [CREATE_FUNCTION] روی FUNCTION: fn_UserActiveUnits
/* واحدهایِ سازمانیِ کاربر — فقط عضویتِ مستقیم (زیرواحدها دسترسیِ خودکار به واحدِ بالادستی ندارند):
   UserPositions فعال با تاریخِ پایانِ خالی یا آینده، و واحدِ فعال. */
CREATE FUNCTION dbo.fn_UserActiveUnits (@UserID INT)
RETURNS TABLE
AS
RETURN
    SELECT DISTINCT UP.UnitID
    FROM dbo.UserPositions UP
    INNER JOIN dbo.OrganizationalUnits OU ON OU.UnitID = UP.UnitID AND OU.IsActive = 1
    WHERE UP.UserID = @UserID AND UP.IsActive = 1 AND (UP.EndDate IS NULL OR UP.EndDate > SYSDATETIME());
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_GetAccess
/* سطحِ دسترسیِ بیننده به تقویمِ شخصیِ یک کاربر: OWNER | ADMIN (CALENDAR_VIEW_OTHERS) | READ | FREEBUSY | NONE.
   بیننده باید فعال و دارایِ CALENDAR_VIEW باشد؛ اشتراک فقط از صاحبِ فعال معتبر است. */
CREATE PROCEDURE dbo.sp_Calendar_GetAccess
    @ViewerID    INT,
    @OwnerUserID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT CASE
        WHEN NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @ViewerID AND IsActive = 1)
          OR NOT EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@ViewerID, N'CALENDAR_VIEW')) THEN N'NONE'
        WHEN @ViewerID = @OwnerUserID THEN N'OWNER'
        WHEN EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@ViewerID, N'CALENDAR_VIEW_OTHERS')) THEN N'ADMIN'
        WHEN NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @OwnerUserID AND IsActive = 1) THEN N'NONE'
        ELSE ISNULL((SELECT TOP (1) S.AccessLevel FROM dbo.CalendarShares S
                     WHERE S.OwnerUserID = @OwnerUserID AND S.SharedWithUserID = @ViewerID AND S.IsActive = 1), N'NONE')
    END AS AccessLevel;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_GetShares
/* اشتراک‌هایِ فعالِ تقویمِ من */
CREATE PROCEDURE dbo.sp_Calendar_GetShares
    @OwnerUserID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT S.CalendarShareID, S.SharedWithUserID, dbo.fn_GetUserFullName(U.FirstName, U.LastName, U.UserName) AS FullName,
           S.AccessLevel, S.Date_InsertFirst, S.Date_LastUpdate, CAST(U.IsActive AS BIT) AS UserIsActive
    FROM dbo.CalendarShares S
    INNER JOIN dbo.Users U ON U.UserID = S.SharedWithUserID
    WHERE S.OwnerUserID = @OwnerUserID AND S.IsActive = 1
    ORDER BY FullName;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_SaveShare
/* ثبت/تغییرِ اشتراک (دوباره‌فعال‌سازیِ ردیفِ قبلی). مجوزِ CALENDAR_SHARE در Controller بررسی می‌شود. */
CREATE PROCEDURE dbo.sp_Calendar_SaveShare
    @OwnerUserID      INT,
    @SharedWithUserID INT,
    @AccessLevel      NVARCHAR(20),
    @ByUserID         INT
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF @AccessLevel NOT IN (N'FREEBUSY', N'READ')
        BEGIN SELECT CAST(0 AS BIT) AS Success, N'سطحِ دسترسی نامعتبر است.' AS Message; RETURN; END
        IF @OwnerUserID = @SharedWithUserID
        BEGIN SELECT CAST(0 AS BIT) AS Success, N'اشتراک با خودتان معنا ندارد.' AS Message; RETURN; END
        IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @OwnerUserID AND IsActive = 1)
           OR NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @SharedWithUserID AND IsActive = 1)
        BEGIN SELECT CAST(0 AS BIT) AS Success, N'کاربر نامعتبر یا غیرفعال است.' AS Message; RETURN; END

        MERGE dbo.CalendarShares AS t
        USING (SELECT @OwnerUserID AS OwnerUserID, @SharedWithUserID AS SharedWithUserID) AS s
        ON t.OwnerUserID = s.OwnerUserID AND t.SharedWithUserID = s.SharedWithUserID
        WHEN MATCHED THEN UPDATE SET AccessLevel = @AccessLevel, IsActive = 1, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ByUserID
        WHEN NOT MATCHED THEN INSERT (OwnerUserID, SharedWithUserID, AccessLevel, IsActive, Date_InsertFirst, UserID_InsertFirst)
             VALUES (@OwnerUserID, @SharedWithUserID, @AccessLevel, 1, SYSDATETIME(), @ByUserID);

        SELECT CAST(1 AS BIT) AS Success, N'اشتراکِ تقویم ذخیره شد.' AS Message,
               (SELECT CalendarShareID FROM dbo.CalendarShares WHERE OwnerUserID = @OwnerUserID AND SharedWithUserID = @SharedWithUserID) AS CalendarShareID;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ذخیرهٔ اشتراک: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_RemoveShare
/* لغوِ اشتراک (نرم) — فقط صاحبِ تقویم */
CREATE PROCEDURE dbo.sp_Calendar_RemoveShare
    @OwnerUserID     INT,
    @CalendarShareID INT,
    @ByUserID        INT
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE dbo.CalendarShares SET IsActive = 0, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ByUserID
    WHERE CalendarShareID = @CalendarShareID AND OwnerUserID = @OwnerUserID AND IsActive = 1;
    IF @@ROWCOUNT = 0
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'اشتراک یافت نشد.' AS Message; RETURN; END
    SELECT CAST(1 AS BIT) AS Success, N'اشتراک لغو شد.' AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_GetSharedWithMe
/* تقویم‌هایی که با من به اشتراک گذاشته شده‌اند (فقط صاحبِ فعال) */
CREATE PROCEDURE dbo.sp_Calendar_GetSharedWithMe
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT S.CalendarShareID, S.OwnerUserID, dbo.fn_GetUserFullName(U.FirstName, U.LastName, U.UserName) AS OwnerName, S.AccessLevel
    FROM dbo.CalendarShares S
    INNER JOIN dbo.Users U ON U.UserID = S.OwnerUserID AND U.IsActive = 1
    WHERE S.SharedWithUserID = @UserID AND S.IsActive = 1
    ORDER BY OwnerName;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_GetUserUnits
/* واحدهایِ سازمانیِ کاربر (عضویتِ مستقیم) و اینکه می‌تواند رویدادِ واحد بسازد/مدیریت کند */
CREATE PROCEDURE dbo.sp_Calendar_GetUserUnits
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT OU.UnitID, OU.UnitName,
           CAST(CASE WHEN EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@UserID, N'CALENDAR_UNIT_MANAGE')) THEN 1 ELSE 0 END AS BIT) AS CanManageEvents
    FROM dbo.fn_UserActiveUnits(@UserID) M
    INNER JOIN dbo.OrganizationalUnits OU ON OU.UnitID = M.UnitID
    ORDER BY OU.UnitName;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetUnitCalendarEvents
/* رویدادهایِ تقویمِ یک واحد در بازه. فقط برایِ عضوِ مستقیمِ فعالِ واحد یا دارندهٔ CALENDAR_VIEW_OTHERS (با CALENDAR_VIEW).
   MyRelation = CREATOR | OWNER | ATTENDEE | UNIT ؛ CanManage = سازنده/مالک یا عضوِ دارایِ CALENDAR_UNIT_MANAGE. */
CREATE PROCEDURE dbo.sp_GetUnitCalendarEvents
    @UnitID   INT,
    @UserID   INT,
    @FromDate DATETIME2(0),
    @ToDate   DATETIME2(0)
AS
BEGIN
    SET NOCOUNT ON;
    IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @UserID AND IsActive = 1)
       OR NOT EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@UserID, N'CALENDAR_VIEW'))
       OR NOT ((@UnitID IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.fn_UserActiveUnits(@UserID) uu WHERE uu.UnitID = @UnitID)) OR EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@UserID, N'CALENDAR_VIEW_OTHERS')))
        RETURN;
    SELECT e.EventID, e.Title, e.Description, e.StartDateTime, e.EndDateTime, e.IsAllDay, e.Color, e.Status,
           e.RecurrenceType, e.RecurrenceInterval, e.RecurrenceEndDate, e.CreatedByUserID, e.OwnerUserID, e.OrganizationalUnitID,
           dbo.fn_GetUserFullName(cu.FirstName, cu.LastName, cu.UserName) AS CreatorName,
           dbo.fn_GetUserFullName(ou.FirstName, ou.LastName, ou.UserName) AS OwnerName,
           CAST(CASE WHEN e.CreatedByUserID = @UserID OR e.OwnerUserID = @UserID OR (e.OrganizationalUnitID IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.fn_UserActiveUnits(@UserID) uu WHERE uu.UnitID = e.OrganizationalUnitID) AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@UserID, N'CALENDAR_UNIT_MANAGE'))) THEN 1 ELSE 0 END AS BIT) AS CanManage,
           CASE WHEN e.CreatedByUserID = @UserID THEN N'CREATOR' WHEN e.OwnerUserID = @UserID THEN N'OWNER'
                WHEN att.CalendarEventAttendeeID IS NOT NULL THEN N'ATTENDEE' ELSE N'UNIT' END AS MyRelation,
           att.ResponseStatus AS MyResponseStatus, rem.OffsetMinutes AS MyReminderOffsetMinutes
    FROM dbo.CalendarEvents e
    LEFT JOIN dbo.Users cu ON cu.UserID = e.CreatedByUserID
    LEFT JOIN dbo.Users ou ON ou.UserID = e.OwnerUserID
    LEFT JOIN dbo.CalendarEventAttendees att ON att.EventID = e.EventID AND att.UserID = @UserID AND att.IsActive = 1
    LEFT JOIN dbo.Reminders rem ON rem.EntityType = N'CALENDAR_EVENT' AND rem.EntityID = e.EventID AND rem.UserID = @UserID AND rem.IsActive = 1
    WHERE e.IsActive = 1 AND e.OrganizationalUnitID = @UnitID
      AND ((e.RecurrenceType = N'NONE' AND e.StartDateTime <= @ToDate AND e.EndDateTime >= @FromDate)
        OR (e.RecurrenceType <> N'NONE' AND e.StartDateTime <= @ToDate AND (e.RecurrenceEndDate IS NULL OR e.RecurrenceEndDate >= CAST(@FromDate AS DATE))))
    ORDER BY e.StartDateTime;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_GetCalendarEvent
ALTER PROCEDURE [dbo].[sp_GetCalendarEvent]
    @EventID BIGINT,
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
        e.OwnerUserID,
        e.OrganizationalUnitID,
        dbo.fn_GetUserFullName(cu.FirstName, cu.LastName, cu.UserName) AS CreatorName,
        dbo.fn_GetUserFullName(ou.FirstName, ou.LastName, ou.UserName) AS OwnerName,
        e.Date_InsertFirst,
        e.Date_LastUpdate,
        e.UserID_LastUpdate,
        CAST(CASE WHEN e.CreatedByUserID = @UserID OR e.OwnerUserID = @UserID OR (e.OrganizationalUnitID IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.fn_UserActiveUnits(@UserID) uu WHERE uu.UnitID = e.OrganizationalUnitID) AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@UserID, N'CALENDAR_UNIT_MANAGE'))) THEN 1 ELSE 0 END AS BIT) AS CanManage, /* + مدیرِ رویدادِ واحد */
        CASE
            WHEN e.CreatedByUserID = @UserID THEN N'CREATOR'
            WHEN e.OwnerUserID = @UserID THEN N'OWNER'
            WHEN EXISTS (SELECT 1 FROM dbo.CalendarEventAttendees a WHERE a.EventID = e.EventID AND a.UserID = @UserID AND a.IsActive = 1) THEN N'ATTENDEE'
            WHEN (e.OrganizationalUnitID IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.fn_UserActiveUnits(@UserID) uu WHERE uu.UnitID = e.OrganizationalUnitID)) THEN N'UNIT' /* عضوِ مستقیمِ واحدِ رویداد */
            ELSE N'NONE'
        END AS MyRelation,
        (SELECT a.ResponseStatus FROM dbo.CalendarEventAttendees a WHERE a.EventID = e.EventID AND a.UserID = @UserID AND a.IsActive = 1) AS MyResponseStatus
    FROM dbo.CalendarEvents e
    LEFT JOIN dbo.Users cu ON cu.UserID = e.CreatedByUserID
    LEFT JOIN dbo.Users ou ON ou.UserID = e.OwnerUserID
    WHERE e.EventID = @EventID AND e.IsActive = 1;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_InsertCalendarEvent
ALTER PROCEDURE [dbo].[sp_InsertCalendarEvent]
    @Title                NVARCHAR(250),
    @Description          NVARCHAR(MAX) = NULL,
    @StartDateTime        DATETIME2(0),
    @EndDateTime          DATETIME2(0),
    @IsAllDay             BIT = 0,
    @Color                NVARCHAR(20) = '#1677ff',
    @Status               NVARCHAR(20) = 'CONFIRMED',
    @RecurrenceType       NVARCHAR(20) = 'NONE',
    @RecurrenceInterval   INT = 1,
    @RecurrenceEndDate    DATE = NULL,
    @OwnerUserID          INT,
    @OrganizationalUnitID INT = NULL,
    @AttendeeUserIDs      NVARCHAR(MAX) = NULL,
    @CreateUser           INT
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF @EndDateTime < @StartDateTime
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'تاریخ/ساعت پایان نمی‌تواند قبل از شروع باشد.' AS Message;
            RETURN;
        END

        /* رویدادِ تقویمِ واحد فقط توسطِ عضوِ مستقیمِ همان واحد با مجوزِ CALENDAR_UNIT_MANAGE */
        IF @OrganizationalUnitID IS NOT NULL AND NOT (@OrganizationalUnitID IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.fn_UserActiveUnits(@CreateUser) uu WHERE uu.UnitID = @OrganizationalUnitID) AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@CreateUser, N'CALENDAR_UNIT_MANAGE')))
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'اجازهٔ ثبتِ رویداد در تقویمِ این واحد را ندارید.' AS Message;
            RETURN;
        END

        IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @OwnerUserID AND IsActive = 1)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'کاربر مالک رویداد نامعتبر است.' AS Message;
            RETURN;
        END

        DECLARE @NewEventID BIGINT;

        INSERT INTO dbo.CalendarEvents
            (Title, Description, StartDateTime, EndDateTime, IsAllDay, Color, Status,
             RecurrenceType, RecurrenceInterval, RecurrenceEndDate,
             CreatedByUserID, OwnerUserID, OrganizationalUnitID, Date_InsertFirst, UserID_InsertFirst)
        VALUES
            (@Title, @Description, @StartDateTime, @EndDateTime, @IsAllDay, @Color, @Status,
             @RecurrenceType, @RecurrenceInterval, @RecurrenceEndDate,
             @CreateUser, @OwnerUserID, @OrganizationalUnitID, SYSDATETIME(), @CreateUser);

        SET @NewEventID = SCOPE_IDENTITY();

        -- سازنده به‌عنوان ORGANIZER (اگر مالک شخص دیگری است)
        IF @CreateUser <> @OwnerUserID
        BEGIN
            INSERT INTO dbo.CalendarEventAttendees (EventID, UserID, RelationType, ResponseStatus, ResponseDate, Date_InsertFirst, UserID_InsertFirst)
            VALUES (@NewEventID, @CreateUser, N'ORGANIZER', N'ACCEPTED', SYSDATETIME(), SYSDATETIME(), @CreateUser);

            INSERT INTO dbo.CalendarEventAttendees (EventID, UserID, RelationType, ResponseStatus, Date_InsertFirst, UserID_InsertFirst, InvitedAt)
            VALUES (@NewEventID, @OwnerUserID, N'PARTICIPANT', N'PENDING', SYSDATETIME(), @CreateUser, SYSDATETIME());
        END

        IF @AttendeeUserIDs IS NOT NULL AND LTRIM(RTRIM(@AttendeeUserIDs)) <> ''
        BEGIN
            INSERT INTO dbo.CalendarEventAttendees (EventID, UserID, RelationType, ResponseStatus, Date_InsertFirst, UserID_InsertFirst, InvitedAt)
            SELECT @NewEventID, CAST(LTRIM(RTRIM(value)) AS INT), N'PARTICIPANT', N'PENDING', SYSDATETIME(), @CreateUser, SYSDATETIME() /* InvitedAt */
            FROM STRING_SPLIT(@AttendeeUserIDs, ',')
            WHERE LTRIM(RTRIM(value)) <> ''
              AND CAST(LTRIM(RTRIM(value)) AS INT) NOT IN (@CreateUser, @OwnerUserID)
              AND NOT EXISTS (
                  SELECT 1 FROM dbo.CalendarEventAttendees x
                  WHERE x.EventID = @NewEventID AND x.UserID = CAST(LTRIM(RTRIM(value)) AS INT)
              );
        END

        SELECT CAST(1 AS BIT) AS Success, N'رویداد با موفقیت ایجاد شد.' AS Message, @NewEventID AS NewEventID;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ایجاد رویداد: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_UpdateCalendarEvent
ALTER PROCEDURE [dbo].[sp_UpdateCalendarEvent]
    @EventID              BIGINT,
    @Title                NVARCHAR(250),
    @Description          NVARCHAR(MAX) = NULL,
    @StartDateTime        DATETIME2(0),
    @EndDateTime          DATETIME2(0),
    @IsAllDay             BIT = 0,
    @Color                NVARCHAR(20) = '#1677ff',
    @Status               NVARCHAR(20) = 'CONFIRMED',
    @RecurrenceType       NVARCHAR(20) = 'NONE',
    @RecurrenceInterval   INT = 1,
    @RecurrenceEndDate    DATE = NULL,
    @OwnerUserID          INT,
    @OrganizationalUnitID INT = NULL,
    @AttendeeUserIDs      NVARCHAR(MAX) = NULL,
    @ModifyUser           INT
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        DECLARE @Creator INT, @Owner INT, @Unit INT;
        SELECT @Creator = CreatedByUserID, @Owner = OwnerUserID, @Unit = OrganizationalUnitID
        FROM dbo.CalendarEvents WHERE EventID = @EventID AND IsActive = 1;

        IF @Creator IS NULL
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'رویداد یافت نشد.' AS Message;
            RETURN;
        END

        IF @ModifyUser <> @Creator AND @ModifyUser <> @Owner AND NOT (@Unit IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.fn_UserActiveUnits(@ModifyUser) uu WHERE uu.UnitID = @Unit) AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@ModifyUser, N'CALENDAR_UNIT_MANAGE'))) /* یا مدیرِ رویدادِ واحد */
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'فقط ایجادکننده یا مالک رویداد می‌تواند آن را ویرایش کند.' AS Message;
            RETURN;
        END

        /* انتقال به (یا ماندن در) تقویمِ واحدِ دیگر فقط برایِ مدیرِ رویدادِ همان واحد */
        IF @OrganizationalUnitID IS NOT NULL AND ISNULL(@Unit, 0) <> @OrganizationalUnitID AND NOT (@OrganizationalUnitID IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.fn_UserActiveUnits(@ModifyUser) uu WHERE uu.UnitID = @OrganizationalUnitID) AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@ModifyUser, N'CALENDAR_UNIT_MANAGE')))
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'اجازهٔ ثبتِ رویداد در تقویمِ این واحد را ندارید.' AS Message;
            RETURN;
        END

        IF @EndDateTime < @StartDateTime
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'تاریخ/ساعت پایان نمی‌تواند قبل از شروع باشد.' AS Message;
            RETURN;
        END

        UPDATE dbo.CalendarEvents SET
            Title                = @Title,
            Description          = @Description,
            StartDateTime        = @StartDateTime,
            EndDateTime          = @EndDateTime,
            IsAllDay             = @IsAllDay,
            Color                = @Color,
            Status               = @Status,
            RecurrenceType       = @RecurrenceType,
            RecurrenceInterval   = @RecurrenceInterval,
            RecurrenceEndDate    = @RecurrenceEndDate,
            OwnerUserID          = @OwnerUserID,
            OrganizationalUnitID = @OrganizationalUnitID,
            Date_LastUpdate      = SYSDATETIME(),
            UserID_LastUpdate    = @ModifyUser
        WHERE EventID = @EventID;

        -- به‌روزرسانی کاربران مرتبط: غیرفعال‌سازی همه به‌جز ORGANIZER، سپس درج/فعال‌سازی لیست جدید
        /* شرکت‌کنندگانِ فعالِ پیش از این ویرایش — برایِ تشخیصِ «تازه یا دوباره اضافه‌شده» */
        DECLARE @Prev TABLE (UserID INT PRIMARY KEY);
        INSERT INTO @Prev (UserID) SELECT DISTINCT UserID FROM dbo.CalendarEventAttendees
        WHERE EventID = @EventID AND IsActive = 1 AND RelationType <> N'ORGANIZER';

        UPDATE dbo.CalendarEventAttendees
        SET IsActive = 0, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ModifyUser
        WHERE EventID = @EventID AND RelationType <> N'ORGANIZER';

        IF @OwnerUserID <> @Creator
        BEGIN
            MERGE dbo.CalendarEventAttendees AS t
            USING (SELECT @EventID AS EventID, @OwnerUserID AS UserID,
                   CASE WHEN EXISTS (SELECT 1 FROM @Prev p WHERE p.UserID = @OwnerUserID) THEN 1 ELSE 0 END AS WasActive) AS s
            ON t.EventID = s.EventID AND t.UserID = s.UserID
            WHEN MATCHED THEN UPDATE SET IsActive = 1, RelationType = N'PARTICIPANT', Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ModifyUser
                     /* تازه یا دوباره اضافه‌شده (در فهرستِ فعالِ قبلی نبود: s.WasActive = 0) → دعوتِ تازه؛ بقیه دست نمی‌خورند */
                     , ResponseStatus = CASE WHEN s.WasActive = 1 THEN t.ResponseStatus ELSE N'PENDING' END
                     , ResponseDate = CASE WHEN s.WasActive = 1 THEN t.ResponseDate ELSE NULL END
                     , InvitedAt = CASE WHEN s.WasActive = 1 THEN t.InvitedAt WHEN t.InvitedAt IS NOT NULL AND t.InvitedAt >= CAST(SYSDATETIME() AS DATETIME2(0)) THEN DATEADD(SECOND, 1, t.InvitedAt) /* همان ثانیه → نسخهٔ تازه */ ELSE SYSDATETIME() END
            WHEN NOT MATCHED THEN INSERT (EventID, UserID, RelationType, ResponseStatus, Date_InsertFirst, UserID_InsertFirst, InvitedAt)
                 VALUES (@EventID, @OwnerUserID, N'PARTICIPANT', N'PENDING', SYSDATETIME(), @ModifyUser, SYSDATETIME());
        END

        IF @AttendeeUserIDs IS NOT NULL AND LTRIM(RTRIM(@AttendeeUserIDs)) <> ''
        BEGIN
            MERGE dbo.CalendarEventAttendees AS t
            USING (
                SELECT CAST(LTRIM(RTRIM(value)) AS INT) AS UserID,
                       CASE WHEN EXISTS (SELECT 1 FROM @Prev p WHERE p.UserID = CAST(LTRIM(RTRIM(value)) AS INT)) THEN 1 ELSE 0 END AS WasActive
                FROM STRING_SPLIT(@AttendeeUserIDs, ',')
                WHERE LTRIM(RTRIM(value)) <> '' AND CAST(LTRIM(RTRIM(value)) AS INT) NOT IN (@Creator)
            ) AS s
            ON t.EventID = @EventID AND t.UserID = s.UserID
            WHEN MATCHED THEN UPDATE SET IsActive = 1, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ModifyUser
                     /* تازه یا دوباره اضافه‌شده (در فهرستِ فعالِ قبلی نبود: s.WasActive = 0) → دعوتِ تازه؛ بقیه دست نمی‌خورند */
                     , ResponseStatus = CASE WHEN s.WasActive = 1 THEN t.ResponseStatus ELSE N'PENDING' END
                     , ResponseDate = CASE WHEN s.WasActive = 1 THEN t.ResponseDate ELSE NULL END
                     , InvitedAt = CASE WHEN s.WasActive = 1 THEN t.InvitedAt WHEN t.InvitedAt IS NOT NULL AND t.InvitedAt >= CAST(SYSDATETIME() AS DATETIME2(0)) THEN DATEADD(SECOND, 1, t.InvitedAt) /* همان ثانیه → نسخهٔ تازه */ ELSE SYSDATETIME() END
            WHEN NOT MATCHED THEN INSERT (EventID, UserID, RelationType, ResponseStatus, Date_InsertFirst, UserID_InsertFirst, InvitedAt)
                 VALUES (@EventID, s.UserID, N'PARTICIPANT', N'PENDING', SYSDATETIME(), @ModifyUser, SYSDATETIME());
        END

        -- پاکسازی یادآوری کاربرانی که دیگر عضو رویداد نیستند
        UPDATE rem SET IsActive = 0
        FROM dbo.Reminders rem
        WHERE rem.EntityType = N'CALENDAR_EVENT' AND rem.EntityID = @EventID AND rem.IsActive = 1
          AND NOT EXISTS (
              SELECT 1 FROM dbo.CalendarEventAttendees a
              WHERE a.EventID = @EventID AND a.UserID = rem.UserID AND a.IsActive = 1
          )
          AND rem.UserID NOT IN (@Creator, @OwnerUserID);

        SELECT CAST(1 AS BIT) AS Success, N'رویداد با موفقیت ویرایش شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ویرایش رویداد: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_DeleteCalendarEvent
ALTER PROCEDURE [dbo].[sp_DeleteCalendarEvent]
    @EventID    BIGINT,
    @ModifyUser INT
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        DECLARE @Creator INT, @Owner INT, @Unit INT;
        SELECT @Creator = CreatedByUserID, @Owner = OwnerUserID, @Unit = OrganizationalUnitID
        FROM dbo.CalendarEvents WHERE EventID = @EventID AND IsActive = 1;

        IF @Creator IS NULL
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'رویداد یافت نشد.' AS Message;
            RETURN;
        END

        IF @ModifyUser <> @Creator AND @ModifyUser <> @Owner AND NOT (@Unit IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.fn_UserActiveUnits(@ModifyUser) uu WHERE uu.UnitID = @Unit) AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@ModifyUser, N'CALENDAR_UNIT_MANAGE'))) /* یا مدیرِ رویدادِ واحد */
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'فقط ایجادکننده یا مالک رویداد می‌تواند آن را حذف کند.' AS Message;
            RETURN;
        END

        UPDATE dbo.CalendarEvents
        SET IsActive = 0, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ModifyUser
        WHERE EventID = @EventID;

        UPDATE dbo.CalendarEventAttendees SET IsActive = 0 WHERE EventID = @EventID;
        UPDATE dbo.Reminders SET IsActive = 0 WHERE EntityType = N'CALENDAR_EVENT' AND EntityID = @EventID;

        SELECT CAST(1 AS BIT) AS Success, N'رویداد با موفقیت حذف شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در حذف رویداد: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO



-- [DML — دستی، خارج از DDL Trigger] مجوزهایِ تقویمِ مشترک (در «دسترسی‌های عملیاتی»ِ نقش‌ها قابلِ اعطا) و اعطایِ پیش‌فرض به RoleID = 1
IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE PermissionCode = N'CALENDAR_SHARE')
    INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive)
    VALUES (N'CALENDAR_SHARE', N'اشتراک‌گذاریِ تقویمِ شخصی', N'Calendar', N'به اشتراک گذاشتنِ تقویمِ شخصی با کاربرانِ دیگر در سطحِ «آزاد/مشغول» یا «خواندنیِ جزئیات»', 7, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE PermissionCode = N'CALENDAR_UNIT_MANAGE')
    INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive)
    VALUES (N'CALENDAR_UNIT_MANAGE', N'مدیریتِ رویدادهایِ تقویمِ واحد', N'Calendar', N'ثبت، ویرایش، جابه‌جایی و حذفِ رویداد در تقویمِ واحدهایی که کاربر عضوِ مستقیمِ آن‌هاست', 8, 1);
IF EXISTS (SELECT 1 FROM dbo.Roles WHERE RoleID = 1)
    INSERT INTO dbo.RolePermissions (RoleID, PermissionID, CanAccess, IsActive)
    SELECT 1, p.PermissionID, 1, 1
    FROM dbo.Permissions p
    WHERE p.PermissionCode IN (N'CALENDAR_SHARE', N'CALENDAR_UNIT_MANAGE')
      AND NOT EXISTS (SELECT 1 FROM dbo.RolePermissions rp WHERE rp.RoleID = 1 AND rp.PermissionID = p.PermissionID);
GO
