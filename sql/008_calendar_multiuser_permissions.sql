/* ==========================================================================
   پچ خودکار شماره: 008 | نام: calendar_multiuser_permissions
   تاریخ: 2026-09-05 13:35:00 | شامل 48 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: Permissions
CREATE TABLE [dbo].[Permissions]
(
    PermissionID    INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_Permissions PRIMARY KEY,
    RowGuid         UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_Permissions_RowGuid DEFAULT NEWSEQUENTIALID(),
    PermissionCode  NVARCHAR(100) NOT NULL,
    PermissionName  NVARCHAR(200) NOT NULL,
    PermissionGroup NVARCHAR(100) NOT NULL CONSTRAINT DF_Permissions_Group DEFAULT (N'General'),
    Description     NVARCHAR(500) NULL,
    SortOrder       INT NOT NULL CONSTRAINT DF_Permissions_SortOrder DEFAULT (0),
    IsActive        BIT NOT NULL CONSTRAINT DF_Permissions_IsActive DEFAULT (1),
    CreateDate      DATETIME2(3) NOT NULL CONSTRAINT DF_Permissions_CreateDate DEFAULT SYSDATETIME(),
    CreateUser      INT NULL,
    ModifyDate      DATETIME2(3) NULL,
    ModifyUser      INT NULL,
    CONSTRAINT UQ_Permissions_Code UNIQUE (PermissionCode)
)
GO

-- [CREATE_TABLE] روی TABLE: RolePermissions
CREATE TABLE [dbo].[RolePermissions]
(
    RolePermissionID INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_RolePermissions PRIMARY KEY,
    RoleID           INT NOT NULL,
    PermissionID     INT NOT NULL,
    CanAccess        BIT NOT NULL CONSTRAINT DF_RolePermissions_CanAccess DEFAULT (1),
    CreateDate       DATETIME2(3) NOT NULL CONSTRAINT DF_RolePermissions_CreateDate DEFAULT SYSDATETIME(),
    RowGuid          UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_RolePermissions_RowGuid DEFAULT NEWSEQUENTIALID(),
    IsActive         BIT NOT NULL CONSTRAINT DF_RolePermissions_IsActive DEFAULT (1),
    CreateUser       INT NULL,
    ModifyDate       DATETIME2(3) NULL,
    ModifyUser       INT NULL,
    CONSTRAINT UQ_RolePermissions_Role_Permission UNIQUE (RoleID, PermissionID),
    CONSTRAINT FK_RolePermissions_Roles FOREIGN KEY (RoleID) REFERENCES [dbo].[Roles](RoleID),
    CONSTRAINT FK_RolePermissions_Permissions FOREIGN KEY (PermissionID) REFERENCES [dbo].[Permissions](PermissionID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_RolePermissions_RoleID
CREATE INDEX IX_RolePermissions_RoleID ON [dbo].[RolePermissions] (RoleID)
GO

-- [CREATE_INDEX] روی INDEX: IX_RolePermissions_PermissionID
CREATE INDEX IX_RolePermissions_PermissionID ON [dbo].[RolePermissions] (PermissionID)
GO

-- [ALTER_TABLE] روی TABLE: CalendarEvents
ALTER TABLE [dbo].[CalendarEvents]
    ADD OwnerUserID INT NOT NULL CONSTRAINT DF_CalendarEvents_OwnerUserID DEFAULT (0)
GO

-- [ALTER_TABLE] روی TABLE: CalendarEvents
ALTER TABLE [dbo].[CalendarEvents]
    ADD OrganizationalUnitID INT NULL
GO

-- [ALTER_TABLE] روی TABLE: CalendarEvents
ALTER TABLE [dbo].[CalendarEvents]
    ADD CONSTRAINT FK_CalendarEvents_CreatedByUser FOREIGN KEY (CreatedByUserID) REFERENCES [dbo].[Users](UserID)
GO

-- [ALTER_TABLE] روی TABLE: CalendarEvents
ALTER TABLE [dbo].[CalendarEvents]
    ADD CONSTRAINT FK_CalendarEvents_OwnerUser FOREIGN KEY (OwnerUserID) REFERENCES [dbo].[Users](UserID)
GO

-- [ALTER_TABLE] روی TABLE: CalendarEvents
ALTER TABLE [dbo].[CalendarEvents]
    ADD CONSTRAINT FK_CalendarEvents_OrganizationalUnit FOREIGN KEY (OrganizationalUnitID) REFERENCES [dbo].[OrganizationalUnits](UnitID)
GO

-- [CREATE_INDEX] روی INDEX: IX_CalendarEvents_OwnerUserID
CREATE INDEX IX_CalendarEvents_OwnerUserID ON [dbo].[CalendarEvents] (OwnerUserID)
GO

-- [CREATE_INDEX] روی INDEX: IX_CalendarEvents_OrganizationalUnitID
CREATE INDEX IX_CalendarEvents_OrganizationalUnitID ON [dbo].[CalendarEvents] (OrganizationalUnitID)
GO

-- [ALTER_TABLE] روی TABLE: CalendarEventAttendees
ALTER TABLE [dbo].[CalendarEventAttendees]
    ADD RelationType NVARCHAR(20) NOT NULL
        CONSTRAINT DF_CalendarEventAttendees_RelationType DEFAULT (N'PARTICIPANT')
        CONSTRAINT CK_CalendarEventAttendees_RelationType CHECK (RelationType IN (N'ORGANIZER', N'PARTICIPANT', N'OPTIONAL', N'RESOURCE'))
GO

-- [ALTER_TABLE] روی TABLE: CalendarEventAttendees
ALTER TABLE [dbo].[CalendarEventAttendees]
    ADD ResponseStatus NVARCHAR(20) NOT NULL
        CONSTRAINT DF_CalendarEventAttendees_ResponseStatus DEFAULT (N'PENDING')
        CONSTRAINT CK_CalendarEventAttendees_ResponseStatus CHECK (ResponseStatus IN (N'PENDING', N'ACCEPTED', N'REJECTED', N'TENTATIVE'))
GO

-- [ALTER_TABLE] روی TABLE: CalendarEventAttendees
ALTER TABLE [dbo].[CalendarEventAttendees]
    ADD ResponseDate DATETIME2(3) NULL
GO

-- [ALTER_TABLE] روی TABLE: CalendarEventAttendees
ALTER TABLE [dbo].[CalendarEventAttendees]
    ADD Date_LastUpdate DATETIME2(3) NULL
GO

-- [ALTER_TABLE] روی TABLE: CalendarEventAttendees
ALTER TABLE [dbo].[CalendarEventAttendees]
    ADD UserID_LastUpdate INT NULL
GO

-- [ALTER_TABLE] روی TABLE: CalendarEventAttendees
ALTER TABLE [dbo].[CalendarEventAttendees]
    ADD CONSTRAINT FK_CalendarEventAttendees_Users FOREIGN KEY (UserID) REFERENCES [dbo].[Users](UserID)
GO

-- [ALTER_TABLE] روی TABLE: Reminders
ALTER TABLE [dbo].[Reminders]
    ADD Title NVARCHAR(250) NULL
GO

-- [ALTER_TABLE] روی TABLE: Reminders
ALTER TABLE [dbo].[Reminders]
    ADD CreatedByUserID INT NULL
GO

-- [ALTER_TABLE] روی TABLE: Reminders
ALTER TABLE [dbo].[Reminders]
    DROP CONSTRAINT UQ_Reminders_Entity_User
GO

-- [CREATE_INDEX] روی INDEX: UX_Reminders_Entity_User_Active
CREATE UNIQUE INDEX UX_Reminders_Entity_User_Active
    ON [dbo].[Reminders] (EntityType, EntityID, UserID)
    WHERE IsActive = 1 AND EntityType <> N'STANDALONE'
GO

-- [ALTER_TABLE] روی TABLE: Reminders
ALTER TABLE [dbo].[Reminders]
    ADD CONSTRAINT FK_Reminders_Users FOREIGN KEY (UserID) REFERENCES [dbo].[Users](UserID)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_CheckUserPermission
-- ============================================================
-- 5) Stored Procedures — بازتعریف موارد تغییر یافته
-- ============================================================

-- ---------- بررسی دسترسی کاربر ----------
CREATE PROCEDURE [dbo].[sp_CheckUserPermission]
    @UserID         INT,
    @PermissionCode NVARCHAR(100)
AS
BEGIN
    SET NOCOUNT ON;
    SELECT CAST(CASE WHEN EXISTS (
        SELECT 1
        FROM dbo.RolePermissions rp
        INNER JOIN dbo.Permissions p ON p.PermissionID = rp.PermissionID
        INNER JOIN dbo.UserRoles ur ON ur.RoleID = rp.RoleID
        INNER JOIN dbo.Roles r ON r.RoleID = ur.RoleID
        WHERE ur.UserID = @UserID
          AND p.PermissionCode = @PermissionCode
          AND p.IsActive = 1
          AND rp.IsActive = 1
          AND rp.CanAccess = 1
          AND ur.IsActive = 1
          AND r.IsActive = 1
    ) THEN 1 ELSE 0 END AS BIT) AS HasPermission;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetUserPermissions
-- ---------- لیست کدهای دسترسی یک کاربر ----------
CREATE PROCEDURE [dbo].[sp_GetUserPermissions]
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT DISTINCT p.PermissionCode, p.PermissionName, p.PermissionGroup
    FROM dbo.RolePermissions rp
    INNER JOIN dbo.Permissions p ON p.PermissionID = rp.PermissionID
    INNER JOIN dbo.UserRoles ur ON ur.RoleID = rp.RoleID
    INNER JOIN dbo.Roles r ON r.RoleID = ur.RoleID
    WHERE ur.UserID = @UserID
      AND p.IsActive = 1
      AND rp.IsActive = 1
      AND rp.CanAccess = 1
      AND ur.IsActive = 1
      AND r.IsActive = 1;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetPermissions
-- ---------- لیست همه دسترسی‌ها ----------
CREATE PROCEDURE [dbo].[sp_GetPermissions]
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT PermissionID, PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive
    FROM dbo.Permissions
    WHERE (@IsActive IS NULL OR IsActive = @IsActive)
      AND (@SearchText IS NULL OR @SearchText = ''
           OR PermissionName LIKE N'%' + @SearchText + N'%'
           OR PermissionCode LIKE N'%' + @SearchText + N'%'
           OR PermissionGroup LIKE N'%' + @SearchText + N'%')
    ORDER BY PermissionGroup, SortOrder, PermissionName;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetPermissionsForRole
-- ---------- دسترسی‌ها برای یک نقش (هم‌الگو با sp_GetMenusForRole) ----------
CREATE PROCEDURE [dbo].[sp_GetPermissionsForRole]
    @RoleID     INT,
    @SearchText NVARCHAR(200) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        p.PermissionID,
        p.PermissionCode,
        p.PermissionName,
        p.PermissionGroup,
        p.Description,
        p.SortOrder,
        CAST(CASE WHEN EXISTS (
            SELECT 1 FROM dbo.RolePermissions rp
            WHERE rp.RoleID = @RoleID AND rp.PermissionID = p.PermissionID
              AND rp.IsActive = 1 AND rp.CanAccess = 1
        ) THEN 1 ELSE 0 END AS BIT) AS HasAccess
    FROM dbo.Permissions p
    WHERE p.IsActive = 1
      AND (@SearchText IS NULL
           OR p.PermissionName LIKE N'%' + @SearchText + N'%'
           OR p.PermissionCode LIKE N'%' + @SearchText + N'%'
           OR p.PermissionGroup LIKE N'%' + @SearchText + N'%')
    ORDER BY p.PermissionGroup, p.SortOrder, p.PermissionName;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_SaveRolePermissions
-- ---------- ذخیره دسترسی‌های یک نقش (هم‌الگو با sp_SaveRoleMenus) ----------
CREATE PROCEDURE [dbo].[sp_SaveRolePermissions]
    @RoleID        INT,
    @PermissionIDs NVARCHAR(MAX),
    @ModifyUser    INT = NULL
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.Roles WHERE RoleID = @RoleID)
    BEGIN
        SELECT 0 AS Success, N'نقش مورد نظر یافت نشد' AS Message;
        RETURN;
    END

    DECLARE @Selected TABLE (PermissionID INT);
    IF @PermissionIDs IS NOT NULL AND LEN(@PermissionIDs) > 0
    BEGIN
        INSERT INTO @Selected (PermissionID)
        SELECT CAST(value AS INT) FROM STRING_SPLIT(@PermissionIDs, ',') WHERE ISNUMERIC(value) = 1;
    END

    UPDATE dbo.RolePermissions
    SET CanAccess = 0, ModifyDate = SYSDATETIME(), ModifyUser = @ModifyUser
    WHERE RoleID = @RoleID AND IsActive = 1;

    UPDATE rp
    SET CanAccess = 1, IsActive = 1, ModifyDate = SYSDATETIME(), ModifyUser = @ModifyUser
    FROM dbo.RolePermissions rp
    INNER JOIN @Selected s ON s.PermissionID = rp.PermissionID
    WHERE rp.RoleID = @RoleID;

    INSERT INTO dbo.RolePermissions (RoleID, PermissionID, CanAccess, CreateDate, RowGuid, IsActive, CreateUser)
    SELECT @RoleID, s.PermissionID, 1, SYSDATETIME(), NEWID(), 1, @ModifyUser
    FROM @Selected s
    WHERE NOT EXISTS (SELECT 1 FROM dbo.RolePermissions rp WHERE rp.RoleID = @RoleID AND rp.PermissionID = s.PermissionID);

    DECLARE @Cnt INT;
    SELECT @Cnt = COUNT(*) FROM dbo.RolePermissions WHERE RoleID = @RoleID AND IsActive = 1 AND CanAccess = 1;

    SELECT 1 AS Success, N'دسترسی‌های عملیاتی با موفقیت به‌روزرسانی شد' AS Message, @Cnt AS ActivePermissionsCount;
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_GetCalendarEvents
DROP PROCEDURE dbo.sp_GetCalendarEvents
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetCalendarEvents
CREATE PROCEDURE [dbo].[sp_GetCalendarEvents]
    @UserID    INT,               -- صاحب تقویمی که رویدادهایش را می‌خواهیم (خود کاربر یا کاربر دیگر با مجوز)
    @FromDate  DATETIME2(0),
    @ToDate    DATETIME2(0)
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

-- [DROP_PROCEDURE] روی PROCEDURE: sp_GetCalendarEvent
DROP PROCEDURE dbo.sp_GetCalendarEvent
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetCalendarEvent
CREATE PROCEDURE [dbo].[sp_GetCalendarEvent]
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
        CAST(CASE WHEN e.CreatedByUserID = @UserID OR e.OwnerUserID = @UserID THEN 1 ELSE 0 END AS BIT) AS CanManage,
        CASE
            WHEN e.CreatedByUserID = @UserID THEN N'CREATOR'
            WHEN e.OwnerUserID = @UserID THEN N'OWNER'
            WHEN EXISTS (SELECT 1 FROM dbo.CalendarEventAttendees a WHERE a.EventID = e.EventID AND a.UserID = @UserID AND a.IsActive = 1) THEN N'ATTENDEE'
            ELSE N'NONE'
        END AS MyRelation,
        (SELECT a.ResponseStatus FROM dbo.CalendarEventAttendees a WHERE a.EventID = e.EventID AND a.UserID = @UserID AND a.IsActive = 1) AS MyResponseStatus
    FROM dbo.CalendarEvents e
    LEFT JOIN dbo.Users cu ON cu.UserID = e.CreatedByUserID
    LEFT JOIN dbo.Users ou ON ou.UserID = e.OwnerUserID
    WHERE e.EventID = @EventID AND e.IsActive = 1;
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_GetCalendarEventAttendees
DROP PROCEDURE dbo.sp_GetCalendarEventAttendees
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetCalendarEventAttendees
CREATE PROCEDURE [dbo].[sp_GetCalendarEventAttendees]
    @EventID BIGINT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        a.CalendarEventAttendeeID,
        a.EventID,
        a.UserID,
        dbo.fn_GetUserFullName(u.FirstName, u.LastName, u.UserName) AS FullName,
        a.RelationType,
        a.ResponseStatus,
        a.ResponseDate,
        rem.OffsetMinutes AS ReminderOffsetMinutes
    FROM dbo.CalendarEventAttendees a
    LEFT JOIN dbo.Users u ON u.UserID = a.UserID
    LEFT JOIN dbo.Reminders rem
        ON rem.EntityType = N'CALENDAR_EVENT' AND rem.EntityID = a.EventID
       AND rem.UserID = a.UserID AND rem.IsActive = 1
    WHERE a.EventID = @EventID AND a.IsActive = 1
    ORDER BY CASE a.RelationType WHEN N'ORGANIZER' THEN 0 ELSE 1 END, FullName;
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_InsertCalendarEvent
DROP PROCEDURE dbo.sp_InsertCalendarEvent
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_InsertCalendarEvent
CREATE PROCEDURE [dbo].[sp_InsertCalendarEvent]
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

            INSERT INTO dbo.CalendarEventAttendees (EventID, UserID, RelationType, ResponseStatus, Date_InsertFirst, UserID_InsertFirst)
            VALUES (@NewEventID, @OwnerUserID, N'PARTICIPANT', N'PENDING', SYSDATETIME(), @CreateUser);
        END

        IF @AttendeeUserIDs IS NOT NULL AND LTRIM(RTRIM(@AttendeeUserIDs)) <> ''
        BEGIN
            INSERT INTO dbo.CalendarEventAttendees (EventID, UserID, RelationType, ResponseStatus, Date_InsertFirst, UserID_InsertFirst)
            SELECT @NewEventID, CAST(LTRIM(RTRIM(value)) AS INT), N'PARTICIPANT', N'PENDING', SYSDATETIME(), @CreateUser
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

-- [DROP_PROCEDURE] روی PROCEDURE: sp_UpdateCalendarEvent
DROP PROCEDURE dbo.sp_UpdateCalendarEvent
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_UpdateCalendarEvent
CREATE PROCEDURE [dbo].[sp_UpdateCalendarEvent]
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
        DECLARE @Creator INT, @Owner INT;
        SELECT @Creator = CreatedByUserID, @Owner = OwnerUserID
        FROM dbo.CalendarEvents WHERE EventID = @EventID AND IsActive = 1;

        IF @Creator IS NULL
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'رویداد یافت نشد.' AS Message;
            RETURN;
        END

        IF @ModifyUser <> @Creator AND @ModifyUser <> @Owner
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'فقط ایجادکننده یا مالک رویداد می‌تواند آن را ویرایش کند.' AS Message;
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
        UPDATE dbo.CalendarEventAttendees
        SET IsActive = 0, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ModifyUser
        WHERE EventID = @EventID AND RelationType <> N'ORGANIZER';

        IF @OwnerUserID <> @Creator
        BEGIN
            MERGE dbo.CalendarEventAttendees AS t
            USING (SELECT @EventID AS EventID, @OwnerUserID AS UserID) AS s
            ON t.EventID = s.EventID AND t.UserID = s.UserID
            WHEN MATCHED THEN UPDATE SET IsActive = 1, RelationType = N'PARTICIPANT', Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ModifyUser
            WHEN NOT MATCHED THEN INSERT (EventID, UserID, RelationType, ResponseStatus, Date_InsertFirst, UserID_InsertFirst)
                 VALUES (@EventID, @OwnerUserID, N'PARTICIPANT', N'PENDING', SYSDATETIME(), @ModifyUser);
        END

        IF @AttendeeUserIDs IS NOT NULL AND LTRIM(RTRIM(@AttendeeUserIDs)) <> ''
        BEGIN
            MERGE dbo.CalendarEventAttendees AS t
            USING (
                SELECT CAST(LTRIM(RTRIM(value)) AS INT) AS UserID
                FROM STRING_SPLIT(@AttendeeUserIDs, ',')
                WHERE LTRIM(RTRIM(value)) <> '' AND CAST(LTRIM(RTRIM(value)) AS INT) NOT IN (@Creator)
            ) AS s
            ON t.EventID = @EventID AND t.UserID = s.UserID
            WHEN MATCHED THEN UPDATE SET IsActive = 1, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ModifyUser
            WHEN NOT MATCHED THEN INSERT (EventID, UserID, RelationType, ResponseStatus, Date_InsertFirst, UserID_InsertFirst)
                 VALUES (@EventID, s.UserID, N'PARTICIPANT', N'PENDING', SYSDATETIME(), @ModifyUser);
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

-- [DROP_PROCEDURE] روی PROCEDURE: sp_DeleteCalendarEvent
DROP PROCEDURE dbo.sp_DeleteCalendarEvent
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_DeleteCalendarEvent
CREATE PROCEDURE [dbo].[sp_DeleteCalendarEvent]
    @EventID    BIGINT,
    @ModifyUser INT
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        DECLARE @Creator INT, @Owner INT;
        SELECT @Creator = CreatedByUserID, @Owner = OwnerUserID
        FROM dbo.CalendarEvents WHERE EventID = @EventID AND IsActive = 1;

        IF @Creator IS NULL
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'رویداد یافت نشد.' AS Message;
            RETURN;
        END

        IF @ModifyUser <> @Creator AND @ModifyUser <> @Owner
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

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_SetCalendarEventResponse
-- ---------- پاسخ کاربر به دعوت رویداد ----------
CREATE PROCEDURE [dbo].[sp_SetCalendarEventResponse]
    @EventID        BIGINT,
    @UserID         INT,
    @ResponseStatus NVARCHAR(20)
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF @ResponseStatus NOT IN (N'PENDING', N'ACCEPTED', N'REJECTED', N'TENTATIVE')
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'وضعیت پاسخ نامعتبر است.' AS Message;
            RETURN;
        END

        IF NOT EXISTS (SELECT 1 FROM dbo.CalendarEventAttendees WHERE EventID = @EventID AND UserID = @UserID AND IsActive = 1)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'شما در این رویداد عضو نیستید.' AS Message;
            RETURN;
        END

        UPDATE dbo.CalendarEventAttendees
        SET ResponseStatus = @ResponseStatus, ResponseDate = SYSDATETIME(),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE EventID = @EventID AND UserID = @UserID AND IsActive = 1;

        SELECT CAST(1 AS BIT) AS Success, N'پاسخ شما ثبت شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ثبت پاسخ: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_SaveReminder
DROP PROCEDURE dbo.sp_SaveReminder
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_SaveReminder
CREATE PROCEDURE [dbo].[sp_SaveReminder]
    @EntityType          NVARCHAR(50),
    @EntityID            BIGINT,
    @UserID              INT,                 -- کاربری که یادآوری برای اوست
    @OffsetMinutes       INT = NULL,          -- NULL = حذف یادآوری
    @EntityStartDateTime DATETIME2(0),
    @CreatedByUserID     INT,                 -- کاربری که یادآوری را ایجاد/تنظیم می‌کند
    @Title               NVARCHAR(250) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF @OffsetMinutes IS NULL
        BEGIN
            UPDATE dbo.Reminders SET IsActive = 0, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @CreatedByUserID
            WHERE EntityType = @EntityType AND EntityID = @EntityID AND UserID = @UserID;
            SELECT CAST(1 AS BIT) AS Success, N'یادآوری حذف شد.' AS Message;
            RETURN;
        END

        DECLARE @RemindAt DATETIME2(0) = DATEADD(MINUTE, -1 * @OffsetMinutes, @EntityStartDateTime);

        MERGE dbo.Reminders AS target
        USING (SELECT @EntityType AS EntityType, @EntityID AS EntityID, @UserID AS UserID) AS src
        ON target.EntityType = src.EntityType AND target.EntityID = src.EntityID AND target.UserID = src.UserID
        WHEN MATCHED THEN UPDATE SET
            OffsetMinutes = @OffsetMinutes, RemindAt = @RemindAt, Title = @Title,
            IsSent = 0, SentDate = NULL, IsActive = 1,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @CreatedByUserID
        WHEN NOT MATCHED THEN INSERT
            (EntityType, EntityID, UserID, OffsetMinutes, RemindAt, Title, IsActive, CreatedByUserID, Date_InsertFirst, UserID_InsertFirst)
            VALUES (@EntityType, @EntityID, @UserID, @OffsetMinutes, @RemindAt, @Title, 1, @CreatedByUserID, SYSDATETIME(), @CreatedByUserID);

        SELECT CAST(1 AS BIT) AS Success, N'یادآوری ذخیره شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ذخیره یادآوری: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_InsertStandaloneReminder
-- ---------- یادآوری مستقل (بدون رویداد تقویم) ----------
CREATE PROCEDURE [dbo].[sp_InsertStandaloneReminder]
    @Title           NVARCHAR(250),
    @RemindAt        DATETIME2(0),
    @ForUserID       INT,
    @CreatedByUserID INT
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @ForUserID AND IsActive = 1)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'کاربر مقصد نامعتبر است.' AS Message;
            RETURN;
        END

        DECLARE @NewID BIGINT;
        INSERT INTO dbo.Reminders
            (EntityType, EntityID, UserID, OffsetMinutes, RemindAt, Title, IsActive, CreatedByUserID, Date_InsertFirst, UserID_InsertFirst)
        VALUES
            (N'STANDALONE', 0, @ForUserID, 0, @RemindAt, @Title, 1, @CreatedByUserID, SYSDATETIME(), @CreatedByUserID);
        SET @NewID = SCOPE_IDENTITY();

        SELECT CAST(1 AS BIT) AS Success, N'یادآوری ثبت شد.' AS Message, @NewID AS NewReminderID;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ثبت یادآوری: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_UpdateStandaloneReminder
CREATE PROCEDURE [dbo].[sp_UpdateStandaloneReminder]
    @ReminderID BIGINT,
    @Title      NVARCHAR(250),
    @RemindAt   DATETIME2(0),
    @ModifyUser INT
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        DECLARE @Owner INT, @Creator INT;
        SELECT @Owner = UserID, @Creator = CreatedByUserID
        FROM dbo.Reminders WHERE ReminderID = @ReminderID AND IsActive = 1 AND EntityType = N'STANDALONE';

        IF @Owner IS NULL
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'یادآوری یافت نشد.' AS Message;
            RETURN;
        END

        IF @ModifyUser <> @Owner AND @ModifyUser <> ISNULL(@Creator, -1)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'فقط ایجادکننده یا صاحب یادآوری می‌تواند آن را ویرایش کند.' AS Message;
            RETURN;
        END

        UPDATE dbo.Reminders
        SET Title = @Title, RemindAt = @RemindAt, IsSent = 0, SentDate = NULL,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ModifyUser
        WHERE ReminderID = @ReminderID;

        SELECT CAST(1 AS BIT) AS Success, N'یادآوری ویرایش شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در ویرایش یادآوری: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_DeleteReminder
CREATE PROCEDURE [dbo].[sp_DeleteReminder]
    @ReminderID BIGINT,
    @ModifyUser INT
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        DECLARE @Owner INT, @Creator INT;
        SELECT @Owner = UserID, @Creator = CreatedByUserID
        FROM dbo.Reminders WHERE ReminderID = @ReminderID AND IsActive = 1;

        IF @Owner IS NULL
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'یادآوری یافت نشد.' AS Message;
            RETURN;
        END

        IF @ModifyUser <> @Owner AND @ModifyUser <> ISNULL(@Creator, -1)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'اجازه حذف این یادآوری را ندارید.' AS Message;
            RETURN;
        END

        UPDATE dbo.Reminders SET IsActive = 0, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ModifyUser
        WHERE ReminderID = @ReminderID;

        SELECT CAST(1 AS BIT) AS Success, N'یادآوری حذف شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در حذف یادآوری: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetUserReminders
-- ---------- همه یادآوری‌های فعال یک کاربر (رویدادی + مستقل) ----------
CREATE PROCEDURE [dbo].[sp_GetUserReminders]
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        r.ReminderID,
        r.EntityType,
        r.EntityID,
        r.OffsetMinutes,
        r.RemindAt,
        r.IsSent,
        ISNULL(r.Title, e.Title) AS Title,
        e.StartDateTime AS EventStartDateTime,
        r.CreatedByUserID,
        dbo.fn_GetUserFullName(cu.FirstName, cu.LastName, cu.UserName) AS CreatorName
    FROM dbo.Reminders r
    LEFT JOIN dbo.CalendarEvents e
        ON r.EntityType = N'CALENDAR_EVENT' AND e.EventID = r.EntityID AND e.IsActive = 1
    LEFT JOIN dbo.Users cu ON cu.UserID = r.CreatedByUserID
    WHERE r.UserID = @UserID
      AND r.IsActive = 1
      AND (r.EntityType <> N'CALENDAR_EVENT' OR e.EventID IS NOT NULL)
    ORDER BY r.RemindAt;
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_GetDueCalendarReminders
DROP PROCEDURE dbo.sp_GetDueCalendarReminders
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetDueCalendarReminders
CREATE PROCEDURE [dbo].[sp_GetDueCalendarReminders]
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        r.ReminderID,
        r.EntityType,
        r.EntityID AS EventID,
        ISNULL(r.Title, e.Title) AS Title,
        e.StartDateTime,
        r.OffsetMinutes,
        r.RemindAt
    FROM dbo.Reminders r
    LEFT JOIN dbo.CalendarEvents e
        ON r.EntityType = N'CALENDAR_EVENT' AND e.EventID = r.EntityID AND e.IsActive = 1
    WHERE r.UserID = @UserID
      AND r.IsActive = 1
      AND r.IsSent = 0
      AND r.RemindAt <= SYSDATETIME()
      AND (r.EntityType <> N'CALENDAR_EVENT' OR e.EventID IS NOT NULL);
END
GO


/* ==========================================================================
   [MANUAL DATA SEED] — تریگر DDL فقط تغییرات ساختاری را ثبت می‌کند، نه INSERT.
   دسترسی‌های عملیاتی ماژول تقویم و تخصیص پیش‌فرض آن‌ها به نقش «مدیر سیستم»
   (RoleID = 1) باید همراه همین پچ در همه‌ی محیط‌ها اعمال شود.
   ========================================================================== */
SET QUOTED_IDENTIFIER ON;
GO

MERGE dbo.Permissions AS t
USING (VALUES
  (N'CALENDAR_VIEW',              N'مشاهده تقویم',                          N'Calendar', 1, N'مشاهده تقویم شخصی، رویدادهای اختصاص‌یافته و دعوت‌شده و یادآوری‌های خود'),
  (N'CALENDAR_CREATE',            N'ایجاد رویداد و یادآوری (برای خود)',     N'Calendar', 2, N'ایجاد رویداد و یادآوری برای خودِ کاربر'),
  (N'CALENDAR_EDIT',              N'ویرایش رویداد',                        N'Calendar', 3, N'ویرایش رویدادهایی که کاربر سازنده یا مالک آن‌هاست'),
  (N'CALENDAR_DELETE',            N'حذف رویداد',                           N'Calendar', 4, N'حذف رویدادهایی که کاربر سازنده یا مالک آن‌هاست'),
  (N'CALENDAR_CREATE_FOR_OTHERS', N'ایجاد رویداد/یادآوری برای سایر کاربران', N'Calendar', 5, N'تعیین مالک یا کاربران مرتبط غیر از خودِ کاربر هنگام ایجاد رویداد یا یادآوری'),
  (N'CALENDAR_VIEW_OTHERS',       N'مشاهده تقویم سایر کاربران',            N'Calendar', 6, N'مشاهده‌ی فقط‌خواندنی تقویم کاربران دیگر')
) AS s(PermissionCode, PermissionName, PermissionGroup, SortOrder, Description)
ON t.PermissionCode = s.PermissionCode
WHEN MATCHED THEN UPDATE SET
    PermissionName = s.PermissionName, PermissionGroup = s.PermissionGroup,
    SortOrder = s.SortOrder, Description = s.Description, IsActive = 1
WHEN NOT MATCHED THEN INSERT (PermissionCode, PermissionName, PermissionGroup, SortOrder, Description, IsActive)
  VALUES (s.PermissionCode, s.PermissionName, s.PermissionGroup, s.SortOrder, s.Description, 1);
GO

-- تخصیص همه‌ی دسترسی‌های تقویم به نقش «مدیر سیستم» (RoleID = 1)؛ سایر نقش‌ها از
-- صفحه‌ی «نقش‌ها > دسترسی‌ها > دسترسی‌های عملیاتی» قابل تنظیم هستند.
IF EXISTS (SELECT 1 FROM dbo.Roles WHERE RoleID = 1)
BEGIN
    INSERT INTO dbo.RolePermissions (RoleID, PermissionID, CanAccess, IsActive, CreateDate, RowGuid, CreateUser)
    SELECT 1, p.PermissionID, 1, 1, SYSDATETIME(), NEWID(), NULL
    FROM dbo.Permissions p
    WHERE p.PermissionGroup = N'Calendar'
      AND NOT EXISTS (SELECT 1 FROM dbo.RolePermissions rp WHERE rp.RoleID = 1 AND rp.PermissionID = p.PermissionID);
END
GO
