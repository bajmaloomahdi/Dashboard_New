/* ==========================================================================
   پچ خودکار شماره: 128 | نام: calendar_weekdays_all_recurrences
   تاریخ: 2026-10-27 17:18:24 | شامل 2 دستور SQL
   ========================================================================== */

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
             @RecurrenceType, @RecurrenceInterval, @RecurrenceEndDate, CASE WHEN @RecurrenceType <> N'NONE' THEN NULLIF(LTRIM(RTRIM(@RecurrenceWeekDays)), N'') END /* پچِ ۱۲۸: همهٔ تکرارها */,
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
        SET @RecurrenceWeekDays = CASE WHEN @RecurrenceType <> N'NONE' THEN NULLIF(LTRIM(RTRIM(@RecurrenceWeekDays)), N'') END /* پچِ ۱۲۸: همهٔ تکرارها */;

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

