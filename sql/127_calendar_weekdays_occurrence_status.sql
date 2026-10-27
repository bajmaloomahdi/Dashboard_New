/* ==========================================================================
   پچ خودکار شماره: 127 | نام: calendar_weekdays_occurrence_status
   تاریخ: 2026-10-27 16:26:26 | شامل 12 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: CalendarEvents
ALTER TABLE dbo.CalendarEvents ADD RecurrenceWeekDays NVARCHAR(20) NULL
GO

-- [CREATE_TABLE] روی TABLE: CalendarEventOccurrenceStatus
CREATE TABLE dbo.CalendarEventOccurrenceStatus (
    EventID           BIGINT        NOT NULL CONSTRAINT FK_CalendarEventOccurrenceStatus_Event REFERENCES dbo.CalendarEvents (EventID),
    OccurrenceStart   DATETIME2(0)  NOT NULL,
    Status            NVARCHAR(20)  NOT NULL CONSTRAINT CK_CalendarEventOccurrenceStatus_Status CHECK (Status IN (N'CONFIRMED', N'TENTATIVE', N'CANCELLED', N'DONE')),
    Date_LastUpdate   DATETIME2(0)  NOT NULL CONSTRAINT DF_CalendarEventOccurrenceStatus_Date DEFAULT SYSDATETIME(),
    UserID_LastUpdate INT           NULL,
    CONSTRAINT PK_CalendarEventOccurrenceStatus PRIMARY KEY (EventID, OccurrenceStart)
)
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
    @CreateUser           INT,
    @IsPrivate            BIT = 0,         -- رویدادِ خصوصی (پیش‌فرض: عمومی)
    @RecurrenceWeekDays   NVARCHAR(20) = NULL -- پچِ ۱۲۷: روزهایِ هفته برایِ WEEKLY (۰=شنبه … ۶=جمعه)
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF @EndDateTime < @StartDateTime
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'تاریخ/ساعت پایان نمی‌تواند قبل از شروع باشد.' AS Message;
            RETURN;
        END

        /* رویدادِ تقویمِ واحد نمی‌تواند خصوصی باشد (قیدِ CK_CalendarEvents_PrivateNoUnit هم همین را تضمین می‌کند) */
        IF ISNULL(@IsPrivate, 0) = 1 AND @OrganizationalUnitID IS NOT NULL
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'رویدادِ تقویمِ واحد نمی‌تواند خصوصی باشد.' AS Message;
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
             RecurrenceType, RecurrenceInterval, RecurrenceEndDate, RecurrenceWeekDays,
             CreatedByUserID, OwnerUserID, OrganizationalUnitID, IsPrivate, Date_InsertFirst, UserID_InsertFirst)
        VALUES
            (@Title, @Description, @StartDateTime, @EndDateTime, @IsAllDay, @Color, @Status,
             @RecurrenceType, @RecurrenceInterval, @RecurrenceEndDate, CASE WHEN @RecurrenceType = N'WEEKLY' THEN NULLIF(LTRIM(RTRIM(@RecurrenceWeekDays)), N'') END,
             @CreateUser, @OwnerUserID, @OrganizationalUnitID, ISNULL(@IsPrivate, 0), SYSDATETIME(), @CreateUser);

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
    @ModifyUser           INT,
    @IsPrivate            BIT = NULL,      -- NULL = همان مقدارِ فعلی (جابه‌جایی و فراخوانی‌هایِ قدیمی تغییرش نمی‌دهند)
    @RecurrenceWeekDays   NVARCHAR(20) = N'*' -- پچِ ۱۲۷: '*' = همان مقدارِ فعلی؛ فقط برایِ WEEKLY نگه داشته می‌شود
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        DECLARE @Creator INT, @Owner INT, @Unit INT, @WasPrivate BIT;
        DECLARE @OldStart DATETIME2(0), @OldEnd DATETIME2(0), @OldAllDay BIT, @OldRecType NVARCHAR(20), @OldRecInterval INT, @OldRecEnd DATE, @OldStatus NVARCHAR(20); /* زمان‌بندیِ پیش از ویرایش */
        DECLARE @OldRecWeekDays NVARCHAR(20); /* پچِ ۱۲۷ */
        SELECT @Creator = CreatedByUserID, @Owner = OwnerUserID, @Unit = OrganizationalUnitID, @WasPrivate = IsPrivate,
               @OldStart = StartDateTime, @OldEnd = EndDateTime, @OldAllDay = IsAllDay, @OldRecType = RecurrenceType,
               @OldRecInterval = RecurrenceInterval, @OldRecEnd = RecurrenceEndDate, @OldStatus = Status, @OldRecWeekDays = RecurrenceWeekDays
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

        /* پچِ ۱۲۷: روزهایِ هفته — '*' = مقدارِ فعلی؛ غیرِ هفتگی = NULL */
        SET @RecurrenceWeekDays = CASE WHEN @RecurrenceWeekDays = N'*' THEN @OldRecWeekDays ELSE @RecurrenceWeekDays END;
        SET @RecurrenceWeekDays = CASE WHEN @RecurrenceType = N'WEEKLY' THEN NULLIF(LTRIM(RTRIM(@RecurrenceWeekDays)), N'') END;

        /* خصوصی: ارسال‌نشده = مقدارِ فعلی؛ رویدادِ تقویمِ واحد خصوصی نمی‌شود */
        SET @IsPrivate = ISNULL(@IsPrivate, @WasPrivate);
        IF @IsPrivate = 1 AND @OrganizationalUnitID IS NOT NULL
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'رویدادِ تقویمِ واحد نمی‌تواند خصوصی باشد.' AS Message;
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
            RecurrenceWeekDays   = @RecurrenceWeekDays,
            OwnerUserID          = @OwnerUserID,
            OrganizationalUnitID = @OrganizationalUnitID,
            IsPrivate            = @IsPrivate,
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
                     , InviteIsUpdate = CASE WHEN s.WasActive = 1 THEN t.InviteIsUpdate ELSE 0 END, PreviousResponseStatus = CASE WHEN s.WasActive = 1 THEN t.PreviousResponseStatus ELSE NULL END, PreviousResponseDate = CASE WHEN s.WasActive = 1 THEN t.PreviousResponseDate ELSE NULL END /* دعوتِ تازه */
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
                     , InviteIsUpdate = CASE WHEN s.WasActive = 1 THEN t.InviteIsUpdate ELSE 0 END, PreviousResponseStatus = CASE WHEN s.WasActive = 1 THEN t.PreviousResponseStatus ELSE NULL END, PreviousResponseDate = CASE WHEN s.WasActive = 1 THEN t.PreviousResponseDate ELSE NULL END /* دعوتِ تازه */
            WHEN NOT MATCHED THEN INSERT (EventID, UserID, RelationType, ResponseStatus, Date_InsertFirst, UserID_InsertFirst, InvitedAt)
                 VALUES (@EventID, s.UserID, N'PARTICIPANT', N'PENDING', SYSDATETIME(), @ModifyUser, SYSDATETIME());
        END

        /* تغییرِ زمان، تاریخ، مدت، تمام‌روز یا قاعدهٔ تکرار → شرکت‌کنندگانِ قبلی (به‌جز ویرایشگر، سازنده و برگزارکننده) دوباره پاسخ می‌دهند:
           پاسخِ قبلی و زمانش در PreviousResponseStatus/PreviousResponseDate می‌ماند، پاسخ = PENDING، نسخهٔ دعوت (InvitedAt) تازه و InviteIsUpdate = 1
           → یک اعلانِ «تغییرِ جلسه» با همان صف و Pushِ دعوت (نسخه‌هایِ قدیمیِ ارسال‌نشده خودکار کنار می‌روند).
           تغییرِ عنوان/توضیح/رنگ/وضعیت یا فهرستِ شرکت‌کنندگان پاسخ‌ها را دست نمی‌زند؛ رویدادِ لغوشده بازنشانی نمی‌شود.
           شرکت‌کنندهٔ تازه یا دوباره‌اضافه‌شده در همین ویرایش همان «دعوتِ تازه» را دارد (در @Prev نیست). */
        DECLARE @ResponsesReset INT = 0;
        /* پچِ ۱۱۳: برگرداندنِ جلسه از «لغو» هم تغییرِ جلسه است (بازنشانی و یک اطلاعِ «تغییرِ جلسه») */
        IF @Status NOT IN (N'CANCELLED', N'DONE') /* پچِ ۱۱۶: انجام‌شده هم */ AND (ISNULL(@OldStatus, N'') = N'CANCELLED' OR EXISTS (
            SELECT @OldStart, @OldEnd, @OldAllDay, @OldRecType,
                   CASE WHEN @OldRecType = N'NONE' THEN NULL ELSE @OldRecInterval END,
                   CASE WHEN @OldRecType = N'NONE' THEN NULL ELSE @OldRecEnd END,
                   @OldRecWeekDays
            EXCEPT
            SELECT @StartDateTime, @EndDateTime, @IsAllDay, @RecurrenceType,
                   CASE WHEN @RecurrenceType = N'NONE' THEN NULL ELSE @RecurrenceInterval END,
                   CASE WHEN @RecurrenceType = N'NONE' THEN NULL ELSE @RecurrenceEndDate END,
                   @RecurrenceWeekDays))
        BEGIN
            UPDATE A SET
                /* پاسخِ واقعی (غیرِ PENDING) با زمانش نگه داشته می‌شود؛ اگر پس از بازنشانیِ قبلی هنوز پاسخ نداده، همان پاسخِ قبلی می‌ماند */
                PreviousResponseStatus = CASE WHEN A.ResponseStatus <> N'PENDING' THEN A.ResponseStatus ELSE A.PreviousResponseStatus END,
                PreviousResponseDate = CASE WHEN A.ResponseStatus <> N'PENDING' THEN A.ResponseDate ELSE A.PreviousResponseDate END,
                ResponseStatus = N'PENDING',
                ResponseDate = NULL,
                InviteIsUpdate = 1,
                InvitedAt = CASE WHEN A.InvitedAt IS NOT NULL AND A.InvitedAt >= CAST(SYSDATETIME() AS DATETIME2(0)) THEN DATEADD(SECOND, 1, A.InvitedAt) ELSE SYSDATETIME() END,
                Date_LastUpdate = SYSDATETIME(),
                UserID_LastUpdate = @ModifyUser
            FROM dbo.CalendarEventAttendees A
            INNER JOIN @Prev P ON P.UserID = A.UserID
            WHERE A.EventID = @EventID AND A.IsActive = 1 AND A.RelationType <> N'ORGANIZER'
              AND A.UserID <> @ModifyUser AND A.UserID <> @Creator;
            SET @ResponsesReset = @@ROWCOUNT;
        END

        /* پچِ ۱۱۳ — لغوِ جلسهٔ تمام‌نشده: شرکت‌کنندگانِ فعال (به‌جز ویرایشگر، سازنده، برگزارکننده و ردکرده‌ها) یک «اطلاعِ لغو»
           می‌گیرند (CancelNoticeAt = نسخه). برگرداندن از لغو این اطلاع را باطل می‌کند (Pushِ ارسال‌نشده کنار می‌رود). */
        IF @Status = N'CANCELLED' AND ISNULL(@OldStatus, N'') <> N'CANCELLED' AND (@RecurrenceType = N'NONE' AND @EndDateTime >= SYSDATETIME() OR @RecurrenceType <> N'NONE' AND (@RecurrenceEndDate IS NULL OR @RecurrenceEndDate >= CAST(SYSDATETIME() AS DATE)))
            UPDATE dbo.CalendarEventAttendees
            SET CancelNoticeAt = SYSDATETIME(), Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ModifyUser
            WHERE EventID = @EventID AND IsActive = 1 AND RelationType <> N'ORGANIZER'
              AND UserID <> @ModifyUser AND UserID <> @Creator AND ResponseStatus <> N'REJECTED';
        ELSE IF @Status <> N'CANCELLED' AND @OldStatus = N'CANCELLED'
            UPDATE dbo.CalendarEventAttendees
            SET CancelNoticeAt = NULL, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ModifyUser
            WHERE EventID = @EventID AND CancelNoticeAt IS NOT NULL;
        -- پاکسازی یادآوری کاربرانی که دیگر عضو رویداد نیستند
        UPDATE rem SET IsActive = 0
        FROM dbo.Reminders rem
        WHERE rem.EntityType = N'CALENDAR_EVENT' AND rem.EntityID = @EventID AND rem.IsActive = 1
          AND NOT EXISTS (
              SELECT 1 FROM dbo.CalendarEventAttendees a
              WHERE a.EventID = @EventID AND a.UserID = rem.UserID AND a.IsActive = 1
          )
          AND rem.UserID NOT IN (@Creator, @OwnerUserID);

        SELECT CAST(1 AS BIT) AS Success, N'رویداد با موفقیت ویرایش شد.' AS Message, @ResponsesReset AS ResponsesReset;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ویرایش رویداد: ' + ERROR_MESSAGE() AS Message;
    END CATCH
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
        e.RecurrenceWeekDays, -- پچِ ۱۲۷
        e.RecurrenceEndDate,
        e.CreatedByUserID,
        e.OwnerUserID,
        e.OrganizationalUnitID,
        e.IsPrivate,
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
    WHERE e.EventID = @EventID AND e.IsActive = 1
      /* خصوصی → فقط سازنده، مالک و شرکت‌کنندهٔ فعال (حتی CALENDAR_VIEW_OTHERS/اشتراکِ READ/عضوِ واحد نه) */
      AND (e.IsPrivate = 0 OR e.CreatedByUserID = @UserID OR e.OwnerUserID = @UserID
           OR EXISTS (SELECT 1 FROM dbo.CalendarEventAttendees pa WHERE pa.EventID = e.EventID AND pa.UserID = @UserID AND pa.IsActive = 1));
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_GetCalendarEvents
ALTER PROCEDURE [dbo].[sp_GetCalendarEvents]
    @UserID    INT,               -- صاحب تقویمی که رویدادهایش را می‌خواهیم (خود کاربر یا کاربر دیگر با مجوز)
    @FromDate  DATETIME2(0),
    @ToDate    DATETIME2(0),
    @ViewerID  INT = NULL         -- بیننده (NULL = خودِ صاحبِ تقویم)؛ رویدادِ خصوصی برایِ غیرِ شرکت‌کننده پنهان می‌شود
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Viewer INT = ISNULL(@ViewerID, @UserID);

    SELECT
        e.EventID,
        CASE WHEN vp.HideDetails = 1 THEN N'مشغول' ELSE e.Title END AS Title,
        CASE WHEN vp.HideDetails = 1 THEN NULL ELSE e.Description END AS Description,
        e.StartDateTime,
        e.EndDateTime,
        e.IsAllDay,
        e.Color,
        e.Status,
        e.RecurrenceType,
        e.RecurrenceInterval,
        e.RecurrenceWeekDays, -- پچِ ۱۲۷
        e.RecurrenceEndDate,
        CASE WHEN vp.HideDetails = 1 THEN NULL ELSE e.CreatedByUserID END AS CreatedByUserID,
        e.OwnerUserID,
        e.OrganizationalUnitID,
        e.IsPrivate,
        vp.IsParticipant AS ViewerIsParticipant,
        CASE WHEN vp.HideDetails = 1 THEN NULL ELSE dbo.fn_GetUserFullName(cu.FirstName, cu.LastName, cu.UserName) END AS CreatorName,
        dbo.fn_GetUserFullName(ou.FirstName, ou.LastName, ou.UserName) AS OwnerName,
        CAST(CASE WHEN e.CreatedByUserID = @UserID OR e.OwnerUserID = @UserID THEN 1 ELSE 0 END AS BIT) AS CanManage,
        CASE
            WHEN e.CreatedByUserID = @UserID THEN N'CREATOR'
            WHEN e.OwnerUserID = @UserID THEN N'OWNER'
            ELSE N'ATTENDEE'
        END AS MyRelation,
        att.ResponseStatus AS MyResponseStatus,
        rem.OffsetMinutes  AS MyReminderOffsetMinutes
    FROM dbo.CalendarEvents e
    LEFT JOIN dbo.Users cu ON cu.UserID = e.CreatedByUserID
    LEFT JOIN dbo.Users ou ON ou.UserID = e.OwnerUserID
    LEFT JOIN dbo.CalendarEventAttendees att
        ON att.EventID = e.EventID AND att.UserID = @UserID AND att.IsActive = 1
    LEFT JOIN dbo.Reminders rem
        ON rem.EntityType = N'CALENDAR_EVENT' AND rem.EntityID = e.EventID
       AND rem.UserID = @UserID AND rem.IsActive = 1
    CROSS APPLY (SELECT CAST(CASE WHEN e.CreatedByUserID = @Viewer OR e.OwnerUserID = @Viewer
                         OR EXISTS (SELECT 1 FROM dbo.CalendarEventAttendees va WHERE va.EventID = e.EventID AND va.UserID = @Viewer AND va.IsActive = 1)
                    THEN 1 ELSE 0 END AS BIT) AS IsParticipant) vpp
    CROSS APPLY (SELECT vpp.IsParticipant, CAST(CASE WHEN e.IsPrivate = 1 AND vpp.IsParticipant = 0 THEN 1 ELSE 0 END AS BIT) AS HideDetails) vp
    WHERE e.IsActive = 1
      AND (e.CreatedByUserID = @UserID OR e.OwnerUserID = @UserID OR att.CalendarEventAttendeeID IS NOT NULL)
      AND (
            (e.RecurrenceType = N'NONE' AND e.StartDateTime <= @ToDate AND e.EndDateTime >= @FromDate)
            OR
            (e.RecurrenceType <> N'NONE' AND e.StartDateTime <= @ToDate
             AND (e.RecurrenceEndDate IS NULL OR e.RecurrenceEndDate >= CAST(@FromDate AS DATE)))
          )
    ORDER BY e.StartDateTime;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_GetUnitCalendarEvents
/* رویدادهایِ تقویمِ یک واحد در بازه. فقط برایِ عضوِ مستقیمِ فعالِ واحد یا دارندهٔ CALENDAR_VIEW_OTHERS (با CALENDAR_VIEW).
   MyRelation = CREATOR | OWNER | ATTENDEE | UNIT ؛ CanManage = سازنده/مالک یا عضوِ دارایِ CALENDAR_UNIT_MANAGE. */
ALTER PROCEDURE dbo.sp_GetUnitCalendarEvents
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
           e.RecurrenceType, e.RecurrenceInterval, e.RecurrenceEndDate, e.RecurrenceWeekDays, e.CreatedByUserID, e.OwnerUserID, e.OrganizationalUnitID, e.IsPrivate,
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
    WHERE e.IsActive = 1 AND e.OrganizationalUnitID = @UnitID AND e.IsPrivate = 0
      AND ((e.RecurrenceType = N'NONE' AND e.StartDateTime <= @ToDate AND e.EndDateTime >= @FromDate)
        OR (e.RecurrenceType <> N'NONE' AND e.StartDateTime <= @ToDate AND (e.RecurrenceEndDate IS NULL OR e.RecurrenceEndDate >= CAST(@FromDate AS DATE))))
    ORDER BY e.StartDateTime;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_GetPendingCalendarInvitations
/* دعوت‌هایِ در انتظارِ پاسخِ یک کاربر — فقط رویدادِ فعال، غیرلغو و تمام‌نشده و فقط برایِ کاربرِ فعالِ دارایِ CALENDAR_VIEW.
   InvitedAt = نسخهٔ دعوت (برایِ نمایشِ یک‌بارهٔ اعلانِ داخلِ برنامه). */
ALTER PROCEDURE dbo.sp_GetPendingCalendarInvitations
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Now DATETIME2(0) = SYSDATETIME();
    SELECT A.CalendarEventAttendeeID, A.EventID, A.RelationType, ISNULL(A.InvitedAt, A.Date_InsertFirst) AS InvitedAt,
           A.InviteIsUpdate, A.PreviousResponseStatus, A.PreviousResponseDate,
           E.Title, E.StartDateTime, E.EndDateTime, E.IsAllDay, E.Color, E.Status, E.RecurrenceType, E.RecurrenceInterval, E.RecurrenceEndDate, E.RecurrenceWeekDays,
           E.CreatedByUserID, dbo.fn_GetUserFullName(cu.FirstName, cu.LastName, cu.UserName) AS OrganizerName,
           E.OwnerUserID, dbo.fn_GetUserFullName(ou.FirstName, ou.LastName, ou.UserName) AS OwnerName
    FROM dbo.CalendarEventAttendees A
    INNER JOIN dbo.CalendarEvents E ON E.EventID = A.EventID AND E.IsActive = 1 AND E.Status NOT IN (N'CANCELLED', N'DONE') /* پچِ ۱۱۶: انجام‌شده هم */
    LEFT JOIN dbo.Users cu ON cu.UserID = E.CreatedByUserID
    LEFT JOIN dbo.Users ou ON ou.UserID = E.OwnerUserID
    WHERE A.UserID = @UserID AND A.IsActive = 1 AND A.ResponseStatus = N'PENDING' AND A.RelationType <> N'ORGANIZER'
      AND A.UserID <> E.CreatedByUserID
      AND (E.RecurrenceType = N'NONE' AND E.EndDateTime >= @Now OR E.RecurrenceType <> N'NONE' AND (E.RecurrenceEndDate IS NULL OR E.RecurrenceEndDate >= CAST(@Now AS DATE)))
      AND EXISTS (SELECT 1 FROM dbo.Users AU WHERE AU.UserID = A.UserID AND AU.IsActive = 1)
          AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(A.UserID, N'CALENDAR_VIEW'))
    ORDER BY E.StartDateTime;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_GetReminderById
-- ---------- دریافت یک یادآوری با شناسه + اطلاعات تکرارِ رویدادِ مرتبط ----------
ALTER PROCEDURE [dbo].[sp_GetReminderById]
    @ReminderID BIGINT,
    @UserID     INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        r.ReminderID,
        r.EntityType,
        r.EntityID,
        r.UserID,
        r.OffsetMinutes,
        r.RemindAt,
        r.IsSent,
        r.CreatedByUserID,
        ISNULL(r.Title, e.Title)  AS Title,
        e.StartDateTime           AS EventStartDateTime,
        e.EndDateTime             AS EventEndDateTime,
        e.IsAllDay                AS EventIsAllDay,
        e.RecurrenceType          AS RecurrenceType,
        e.RecurrenceInterval      AS RecurrenceInterval,
        e.RecurrenceWeekDays      AS RecurrenceWeekDays, -- پچِ ۱۲۷
        e.RecurrenceEndDate       AS RecurrenceEndDate,
        e.IsActive                AS EventIsActive
    FROM dbo.Reminders r
    LEFT JOIN dbo.CalendarEvents e
        ON r.EntityType = N'CALENDAR_EVENT' AND e.EventID = r.EntityID
    WHERE r.ReminderID = @ReminderID
      AND r.UserID = @UserID
      AND r.IsActive = 1;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Calendar_GetRemindersToRoll
/* پچِ ۱۱۳ — یادآوری‌هایِ رویدادِ تکرارشونده که وقوعشان تمام شده و هنوز جلو نرفته‌اند (برنامه با تقویمِ شمسی به وقوعِ بعدی می‌برد).
   @UserID = NULL → همهٔ کاربران (زمان‌بند)؛ وگرنه فقط همان کاربر (هنگامِ دریافتِ یادآوری‌هایِ داخلِ برنامه). */
ALTER PROCEDURE dbo.sp_Calendar_GetRemindersToRoll
    @UserID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TOP (500) R.ReminderID, R.UserID, R.OffsetMinutes, R.RemindAt,
           E.StartDateTime, E.EndDateTime, E.RecurrenceType, E.RecurrenceInterval, E.RecurrenceEndDate,
           E.RecurrenceWeekDays, R.EntityID AS EventID -- پچِ ۱۲۷: روزهایِ هفته و وقوع‌هایِ «انجام/لغو» شده رد می‌شوند
    FROM dbo.Reminders R
    INNER JOIN dbo.CalendarEvents E ON R.EntityType = N'CALENDAR_EVENT' AND E.EventID = R.EntityID AND E.IsActive = 1
    WHERE R.IsActive = 1 AND R.IsSent = 0 AND E.RecurrenceType <> N'NONE' AND E.Status NOT IN (N'CANCELLED', N'DONE') /* پچِ ۱۱۶: انجام‌شده هم */
      AND (@UserID IS NULL OR R.UserID = @UserID)
      AND DATEADD(SECOND, DATEDIFF(SECOND, E.StartDateTime, E.EndDateTime), DATEADD(MINUTE, ISNULL(R.OffsetMinutes, 0), R.RemindAt)) < SYSDATETIME()
    ORDER BY R.RemindAt;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_SetOccurrenceStatus
-- پچِ ۱۲۷: وضعیتِ یک وقوع از رویدادِ تکرارشونده (ثبت شده / لغو شده / انجام شده) — وضعیتِ سری و وقوع‌هایِ دیگر دست نمی‌خورند.
-- مجوز (مدیرِ رویداد) در کنترلر بررسی می‌شود.
CREATE PROCEDURE dbo.sp_Calendar_SetOccurrenceStatus
    @EventID         BIGINT,
    @OccurrenceStart DATETIME2(0),
    @Status          NVARCHAR(20),
    @UserID          INT
AS
BEGIN
    SET NOCOUNT ON;
    IF @Status NOT IN (N'CONFIRMED', N'TENTATIVE', N'CANCELLED', N'DONE')
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'وضعیتِ نامعتبر.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.CalendarEvents WHERE EventID = @EventID AND IsActive = 1 AND RecurrenceType <> N'NONE')
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'رویدادِ تکرارشونده یافت نشد.' AS Message; RETURN; END

    MERGE dbo.CalendarEventOccurrenceStatus AS t
    USING (SELECT @EventID AS EventID, @OccurrenceStart AS OccurrenceStart) AS s
    ON t.EventID = s.EventID AND t.OccurrenceStart = s.OccurrenceStart
    WHEN MATCHED THEN UPDATE SET Status = @Status, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHEN NOT MATCHED THEN INSERT (EventID, OccurrenceStart, Status, Date_LastUpdate, UserID_LastUpdate)
         VALUES (@EventID, @OccurrenceStart, @Status, SYSDATETIME(), @UserID);

    SELECT CAST(1 AS BIT) AS Success,
           CASE @Status WHEN N'DONE' THEN N'این وقوع «انجام شده» شد.' WHEN N'CANCELLED' THEN N'این وقوع «لغو شده» شد.' ELSE N'وضعیتِ این وقوع ذخیره شد.' END AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_GetOccurrenceStatuses
-- پچِ ۱۲۷: وضعیت‌هایِ جداگانهٔ وقوع‌ها برایِ چند رویداد (شناسه‌ها با ویرگول)
CREATE PROCEDURE dbo.sp_Calendar_GetOccurrenceStatuses
    @EventIDs NVARCHAR(MAX)
AS
BEGIN
    SET NOCOUNT ON;
    SELECT S.EventID, CONVERT(VARCHAR(19), S.OccurrenceStart, 120) AS OccurrenceStart, S.Status
    FROM dbo.CalendarEventOccurrenceStatus S
    WHERE S.EventID IN (SELECT TRY_CAST(LTRIM(RTRIM(value)) AS BIGINT) FROM STRING_SPLIT(@EventIDs, ','));
END
GO

