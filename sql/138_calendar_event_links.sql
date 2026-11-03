/* ==========================================================================
   پچ خودکار شماره: 138 | نام: calendar_event_links
   تاریخ: 2026-11-03 17:11:52 | شامل 6 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: CalendarEventLinks
CREATE TABLE dbo.CalendarEventLinks (
    CalendarEventLinkID BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CalendarEventLinks PRIMARY KEY,
    EventID             BIGINT       NOT NULL CONSTRAINT FK_CalendarEventLinks_Event REFERENCES dbo.CalendarEvents (EventID),
    LinkType            VARCHAR(20)  NOT NULL CONSTRAINT CK_CalendarEventLinks_Type CHECK (LinkType IN ('PARTY', 'PERSON', 'PROJECT', 'MESSAGE')),
    EntityID            BIGINT       NOT NULL,
    IsActive            BIT          NOT NULL CONSTRAINT DF_CalendarEventLinks_IsActive DEFAULT (1),
    Date_InsertFirst    DATETIME2(0) NOT NULL CONSTRAINT DF_CalendarEventLinks_Date_InsertFirst DEFAULT (SYSDATETIME()),
    UserID_InsertFirst  INT          NULL,
    Date_LastUpdate     DATETIME2(0) NULL,
    UserID_LastUpdate   INT          NULL,
    CONSTRAINT UQ_CalendarEventLinks UNIQUE (EventID, LinkType, EntityID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CalendarEventLinks_Entity
CREATE NONCLUSTERED INDEX IX_CalendarEventLinks_Entity ON dbo.CalendarEventLinks (LinkType, EntityID, IsActive) INCLUDE (EventID)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_SetEventLinks
-- پچِ ۱۳۸: ثبتِ ارتباط‌هایِ رویداد (جایگزینیِ کاملِ هر نوع). NULL = همان قبلی بماند، '' = خالی؛ MESSAGE دست نمی‌خورد.
-- پروژه فقط اگر کاربر سازنده یا عضوِ فعالِ آن است؛ طرف‌حساب/فرد فقط اگر وجود دارد (نامعتبرها نادیده گرفته می‌شوند).
CREATE PROCEDURE dbo.sp_Calendar_SetEventLinks
    @EventID    BIGINT,
    @UserID     INT,
    @PartyIDs   NVARCHAR(MAX) = NULL,
    @PersonIDs  NVARCHAR(MAX) = NULL,
    @ProjectIDs NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    DECLARE @Creator INT, @Owner INT, @Unit INT;
    SELECT @Creator = CreatedByUserID, @Owner = OwnerUserID, @Unit = OrganizationalUnitID FROM dbo.CalendarEvents WHERE EventID = @EventID AND IsActive = 1;
    IF @Creator IS NULL
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'رویداد یافت نشد.' AS Message; RETURN; END
    IF @UserID <> @Creator AND @UserID <> @Owner
       AND NOT (@Unit IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.fn_UserActiveUnits(@UserID) uu WHERE uu.UnitID = @Unit)
                AND EXISTS (SELECT 1 FROM dbo.fn_UserHasPermission(@UserID, N'CALENDAR_UNIT_MANAGE')))
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'فقط ایجادکننده یا مالکِ رویداد می‌تواند این کار را انجام دهد.' AS Message; RETURN; END
    DECLARE @Want TABLE (LinkType VARCHAR(20) NOT NULL, EntityID BIGINT NOT NULL, PRIMARY KEY (LinkType, EntityID));
    IF @PartyIDs IS NOT NULL
        INSERT INTO @Want SELECT DISTINCT 'PARTY', p.PartyID FROM STRING_SPLIT(@PartyIDs, ',') s
        INNER JOIN dbo.CrmParties p ON p.PartyID = TRY_CAST(LTRIM(RTRIM(s.value)) AS INT);
    IF @PersonIDs IS NOT NULL
        INSERT INTO @Want SELECT DISTINCT 'PERSON', p.PersonID FROM STRING_SPLIT(@PersonIDs, ',') s
        INNER JOIN dbo.CrmPersons p ON p.PersonID = TRY_CAST(LTRIM(RTRIM(s.value)) AS INT);
    IF @ProjectIDs IS NOT NULL
        INSERT INTO @Want SELECT DISTINCT 'PROJECT', p.ProjectID FROM STRING_SPLIT(@ProjectIDs, ',') s
        INNER JOIN dbo.Projects p ON p.ProjectID = TRY_CAST(LTRIM(RTRIM(s.value)) AS BIGINT)
        WHERE p.UserID_InsertFirst = @UserID
           OR EXISTS (SELECT 1 FROM dbo.ProjectMembers pm WHERE pm.ProjectID = p.ProjectID AND pm.UserID = @UserID AND pm.IsActive = 1)
           -- پروژه‌ای که از قبل وصل است و کاربر عضوش نیست، با ویرایشِ همین رویداد جدا نمی‌شود
           OR EXISTS (SELECT 1 FROM dbo.CalendarEventLinks x WHERE x.EventID = @EventID AND x.LinkType = 'PROJECT' AND x.EntityID = p.ProjectID AND x.IsActive = 1);

    BEGIN TRAN;
    -- نوع‌هایی که فرستاده شده‌اند: بقیه غیرفعال، موجودها فعال، تازه‌ها درج
    UPDATE l SET IsActive = 0, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    FROM dbo.CalendarEventLinks l
    WHERE l.EventID = @EventID AND l.IsActive = 1
      AND ((l.LinkType = 'PARTY' AND @PartyIDs IS NOT NULL) OR (l.LinkType = 'PERSON' AND @PersonIDs IS NOT NULL) OR (l.LinkType = 'PROJECT' AND @ProjectIDs IS NOT NULL))
      AND NOT EXISTS (SELECT 1 FROM @Want w WHERE w.LinkType = l.LinkType AND w.EntityID = l.EntityID);
    UPDATE l SET IsActive = 1, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    FROM dbo.CalendarEventLinks l INNER JOIN @Want w ON w.LinkType = l.LinkType AND w.EntityID = l.EntityID
    WHERE l.EventID = @EventID AND l.IsActive = 0;
    INSERT INTO dbo.CalendarEventLinks (EventID, LinkType, EntityID, UserID_InsertFirst)
    SELECT @EventID, w.LinkType, w.EntityID, @UserID FROM @Want w
    WHERE NOT EXISTS (SELECT 1 FROM dbo.CalendarEventLinks l WHERE l.EventID = @EventID AND l.LinkType = w.LinkType AND l.EntityID = w.EntityID);
    COMMIT;
    SELECT CAST(1 AS BIT) AS Success, N'ارتباط‌هایِ رویداد ثبت شد.' AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_GetEventLinks
-- پچِ ۱۳۸: ارتباط‌هایِ فعالِ یک رویداد با نامِ نمایشی (دسترسیِ دیدنِ رویداد در برنامه بررسی می‌شود)
CREATE PROCEDURE dbo.sp_Calendar_GetEventLinks
    @EventID BIGINT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT l.LinkType, l.EntityID,
           CASE l.LinkType
               WHEN 'PARTY'   THEN COALESCE(NULLIF(LTRIM(RTRIM(pa.TradeName)), N''), pa.OfficialName)
               WHEN 'PERSON'  THEN LTRIM(RTRIM(CONCAT(pe.FirstName, N' ', pe.LastName)))
               WHEN 'PROJECT' THEN CONCAT(pr.ProjectTitle, CASE WHEN pr.ProjectCode IS NOT NULL THEN N' (' + pr.ProjectCode + N')' END)
               WHEN 'MESSAGE' THEN CONCAT(m.MessageNumber, CASE WHEN m.Subject IS NOT NULL THEN N' — ' + m.Subject END)
           END AS DisplayName
    FROM dbo.CalendarEventLinks l
    LEFT JOIN dbo.CrmParties pa ON l.LinkType = 'PARTY' AND pa.PartyID = l.EntityID
    LEFT JOIN dbo.CrmPersons pe ON l.LinkType = 'PERSON' AND pe.PersonID = l.EntityID
    LEFT JOIN dbo.Projects pr ON l.LinkType = 'PROJECT' AND pr.ProjectID = l.EntityID
    LEFT JOIN dbo.Messages m ON l.LinkType = 'MESSAGE' AND m.MessageID = l.EntityID
    WHERE l.EventID = @EventID AND l.IsActive = 1
    ORDER BY CASE l.LinkType WHEN 'PROJECT' THEN 1 WHEN 'MESSAGE' THEN 2 WHEN 'PARTY' THEN 3 ELSE 4 END, l.CalendarEventLinkID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_GetProjectEvents
-- پچِ ۱۳۸: رویدادهایِ مرتبط با یک پروژه برایِ تبِ «رویدادها» (همان ستون‌هایِ sp_GetCalendarEvents برایِ بسطِ تکرار).
-- برنامه فقط برایِ سازنده/عضوِ پروژه صدا می‌زند؛ رویدادِ خصوصی برایِ غیرِ شرکت‌کننده «مشغول» است.
CREATE PROCEDURE dbo.sp_Calendar_GetProjectEvents
    @ProjectID BIGINT,
    @FromDate  DATETIME2(0),
    @ToDate    DATETIME2(0),
    @ViewerID  INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        e.EventID,
        CASE WHEN vp.HideDetails = 1 THEN N'مشغول' ELSE e.Title END AS Title,
        CASE WHEN vp.HideDetails = 1 THEN NULL ELSE e.Description END AS Description,
        e.StartDateTime, e.EndDateTime, e.IsAllDay, e.Color, e.Status,
        e.RecurrenceType, e.RecurrenceInterval, e.RecurrenceWeekDays, e.RecurrenceEndDate,
        CASE WHEN vp.HideDetails = 1 THEN NULL ELSE e.CreatedByUserID END AS CreatedByUserID,
        e.OwnerUserID, e.OrganizationalUnitID, e.IsPrivate,
        vp.IsParticipant AS ViewerIsParticipant,
        CAST(vp.HideDetails AS BIT) AS IsBusyOnly,
        CASE WHEN vp.HideDetails = 1 THEN NULL ELSE dbo.fn_GetUserFullName(cu.FirstName, cu.LastName, cu.UserName) END AS CreatorName,
        dbo.fn_GetUserFullName(ou.FirstName, ou.LastName, ou.UserName) AS OwnerName,
        CAST(CASE WHEN e.CreatedByUserID = @ViewerID OR e.OwnerUserID = @ViewerID THEN 1 ELSE 0 END AS BIT) AS CanManage,
        CASE
            WHEN e.CreatedByUserID = @ViewerID THEN N'CREATOR'
            WHEN e.OwnerUserID = @ViewerID THEN N'OWNER'
            WHEN att.CalendarEventAttendeeID IS NOT NULL THEN N'ATTENDEE'
            ELSE N'NONE'
        END AS MyRelation,
        att.ResponseStatus AS MyResponseStatus,
        rem.OffsetMinutes  AS MyReminderOffsetMinutes
    FROM dbo.CalendarEvents e
    INNER JOIN dbo.CalendarEventLinks l ON l.EventID = e.EventID AND l.LinkType = 'PROJECT' AND l.EntityID = @ProjectID AND l.IsActive = 1
    LEFT JOIN dbo.Users cu ON cu.UserID = e.CreatedByUserID
    LEFT JOIN dbo.Users ou ON ou.UserID = e.OwnerUserID
    LEFT JOIN dbo.CalendarEventAttendees att ON att.EventID = e.EventID AND att.UserID = @ViewerID AND att.IsActive = 1
    LEFT JOIN dbo.Reminders rem ON rem.EntityType = N'CALENDAR_EVENT' AND rem.EntityID = e.EventID AND rem.UserID = @ViewerID AND rem.IsActive = 1
    CROSS APPLY (SELECT CAST(CASE WHEN e.CreatedByUserID = @ViewerID OR e.OwnerUserID = @ViewerID OR att.CalendarEventAttendeeID IS NOT NULL
                    THEN 1 ELSE 0 END AS BIT) AS IsParticipant) vpp
    CROSS APPLY (SELECT vpp.IsParticipant, CAST(CASE WHEN e.IsPrivate = 1 AND vpp.IsParticipant = 0 THEN 1 ELSE 0 END AS BIT) AS HideDetails) vp
    WHERE e.IsActive = 1
      AND (
            (e.RecurrenceType = N'NONE' AND e.StartDateTime <= @ToDate AND e.EndDateTime >= @FromDate)
            OR
            (e.RecurrenceType <> N'NONE' AND e.StartDateTime <= @ToDate
             AND (e.RecurrenceEndDate IS NULL OR e.RecurrenceEndDate >= CAST(@FromDate AS DATE)))
          )
    ORDER BY e.StartDateTime;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Calendar_CreateTaskDueEvent
-- پچِ ۱۳۸: رویدادِ خودکارِ مهلتِ وظیفهٔ پروژه — روزِ مهلت ساعتِ ۱۳:۰۰ تا ۱۳:۳۰؛ مالک = گیرندهٔ وظیفه (پذیرفته، بدونِ دعوت)،
-- سازنده = فرستنده؛ به پروژه و همان وظیفه (MESSAGE) وصل می‌شود. برنامه فقط پس از ثبتِ موفقِ وظیفهٔ دارایِ مهلت صدا می‌زند.
CREATE PROCEDURE dbo.sp_Calendar_CreateTaskDueEvent
    @ProjectID      BIGINT,
    @MessageID      INT,
    @Title          NVARCHAR(250),
    @Description    NVARCHAR(MAX) = NULL,
    @DueDate        DATE,
    @CreatorUserID  INT,
    @AssigneeUserID INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    IF @DueDate IS NULL OR NOT EXISTS (SELECT 1 FROM dbo.Messages WHERE MessageID = @MessageID)
       OR NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @AssigneeUserID AND IsActive = 1)
    BEGIN SELECT CAST(0 AS BIT) AS Success, N'وظیفه یا گیرنده معتبر نیست.' AS Message, CAST(NULL AS BIGINT) AS NewEventID; RETURN; END
    -- یک رویداد برایِ هر وظیفه
    DECLARE @Existing BIGINT = (SELECT TOP 1 l.EventID FROM dbo.CalendarEventLinks l INNER JOIN dbo.CalendarEvents e ON e.EventID = l.EventID AND e.IsActive = 1
                                WHERE l.LinkType = 'MESSAGE' AND l.EntityID = @MessageID AND l.IsActive = 1);
    IF @Existing IS NOT NULL
    BEGIN SELECT CAST(1 AS BIT) AS Success, N'رویدادِ مهلتِ این وظیفه از قبل وجود دارد.' AS Message, @Existing AS NewEventID; RETURN; END

    DECLARE @Start DATETIME2(0) = DATEADD(HOUR, 13, CAST(@DueDate AS DATETIME2(0)));
    DECLARE @End DATETIME2(0) = DATEADD(MINUTE, 30, @Start);
    DECLARE @EventID BIGINT;
    BEGIN TRAN;
    INSERT INTO dbo.CalendarEvents (Title, Description, StartDateTime, EndDateTime, IsAllDay, Color, Status, RecurrenceType, RecurrenceInterval,
                                    CreatedByUserID, IsActive, Date_InsertFirst, UserID_InsertFirst, OwnerUserID, IsPrivate)
    VALUES (LEFT(@Title, 250), NULLIF(LTRIM(RTRIM(@Description)), N''), @Start, @End, 0, '#fa8c16', N'CONFIRMED', N'NONE', 1,
            @CreatorUserID, 1, SYSDATETIME(), @CreatorUserID, @AssigneeUserID, 0);
    SET @EventID = SCOPE_IDENTITY();
    IF @CreatorUserID <> @AssigneeUserID
    BEGIN
        INSERT INTO dbo.CalendarEventAttendees (EventID, UserID, RelationType, ResponseStatus, ResponseDate, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@EventID, @CreatorUserID, N'ORGANIZER', N'ACCEPTED', SYSDATETIME(), SYSDATETIME(), @CreatorUserID);
        INSERT INTO dbo.CalendarEventAttendees (EventID, UserID, RelationType, ResponseStatus, ResponseDate, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@EventID, @AssigneeUserID, N'PARTICIPANT', N'ACCEPTED', SYSDATETIME(), SYSDATETIME(), @CreatorUserID);
    END
    INSERT INTO dbo.CalendarEventLinks (EventID, LinkType, EntityID, UserID_InsertFirst)
    VALUES (@EventID, 'PROJECT', @ProjectID, @CreatorUserID), (@EventID, 'MESSAGE', @MessageID, @CreatorUserID);
    COMMIT;
    SELECT CAST(1 AS BIT) AS Success, N'رویدادِ مهلتِ وظیفه ثبت شد.' AS Message, @EventID AS NewEventID;
END
GO

