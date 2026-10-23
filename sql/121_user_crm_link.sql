/* ==========================================================================
   پچ خودکار شماره: 121 | نام: user_crm_link
   تاریخ: 2026-10-23 13:19:56 | شامل 7 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: Users
ALTER TABLE dbo.Users ADD UserType NVARCHAR(10) NOT NULL CONSTRAINT DF_Users_UserType DEFAULT N'INTERNAL', CrmPersonID INT NULL, CrmPartyID INT NULL
GO

-- [ALTER_TABLE] روی TABLE: Users
ALTER TABLE dbo.Users ADD
    CONSTRAINT FK_Users_CrmPersons FOREIGN KEY (CrmPersonID) REFERENCES dbo.CrmPersons (PersonID),
    CONSTRAINT FK_Users_CrmParties FOREIGN KEY (CrmPartyID) REFERENCES dbo.CrmParties (PartyID),
    -- داخلی: بدونِ اتصال؛ مخاطب: فقط CrmPersonID؛ طرف‌حساب: فقط CrmPartyID
    CONSTRAINT CK_Users_UserType CHECK (
        (UserType = N'INTERNAL' AND CrmPersonID IS NULL AND CrmPartyID IS NULL)
        OR (UserType = N'PERSON' AND CrmPersonID IS NOT NULL AND CrmPartyID IS NULL)
        OR (UserType = N'PARTY' AND CrmPartyID IS NOT NULL AND CrmPersonID IS NULL))
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_GetUsers
ALTER PROCEDURE [dbo].[sp_GetUsers]
    @SearchText NVARCHAR(100) = NULL,
    @IsActive   INT = 1
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        u.UserID,
        u.UserCode,
        u.UserName,
        u.FirstName,
        u.LastName,
        u.FullName,
        u.Email,
        u.Mobile,
        u.LastLoginDate,
        u.LastLoginIP,
        u.FailedLoginCount,
        u.IsLocked,
        u.IsActive,
        u.Description,
        u.UserType, u.CrmPersonID, u.CrmPartyID, -- پچِ ۱۲۱
        CASE u.UserType WHEN N'PERSON' THEN LTRIM(cp.FirstName + N' ' + cp.LastName) WHEN N'PARTY' THEN cpa.OfficialName END AS CrmLinkName,
        u.CreateDate,
        u.ModifyDate,
        ISNULL((
            SELECT COUNT(*)
            FROM dbo.UserRoles ur
            WHERE ur.UserID = u.UserID AND ur.IsActive = 1
        ), 0) AS RolesCount
    FROM dbo.Users u
    LEFT JOIN dbo.CrmPersons cp ON cp.PersonID = u.CrmPersonID
    LEFT JOIN dbo.CrmParties cpa ON cpa.PartyID = u.CrmPartyID
    WHERE (@IsActive IS NULL OR u.IsActive = @IsActive)
      AND (@SearchText IS NULL OR @SearchText = ''
           OR u.UserName  LIKE N'%' + @SearchText + N'%'
           OR u.FirstName LIKE N'%' + @SearchText + N'%'
           OR u.LastName  LIKE N'%' + @SearchText + N'%'
           OR u.FullName  LIKE N'%' + @SearchText + N'%'
           OR u.Email     LIKE N'%' + @SearchText + N'%'
           OR u.Mobile    LIKE N'%' + @SearchText + N'%'
           OR u.UserCode  LIKE N'%' + @SearchText + N'%')
    ORDER BY u.FullName;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_SetUserCrmLink
-- پچِ ۱۲۱: تعیینِ نوعِ کاربر (داخلی/مخاطب/طرف‌حساب) و مخاطب/طرف‌حسابِ متصل — از فرمِ تعریفِ کاربر.
-- هر مخاطب/طرف‌حساب حداکثر به یک کاربر متصل است.
CREATE PROCEDURE dbo.sp_SetUserCrmLink
    @UserID      INT,
    @UserType    NVARCHAR(10),
    @CrmPersonID INT = NULL,
    @CrmPartyID  INT = NULL,
    @ModifyUser  INT = NULL
AS
BEGIN
    SET NOCOUNT ON;

    IF @UserType NOT IN (N'INTERNAL', N'PERSON', N'PARTY')
    BEGIN SELECT 0 AS Success, N'نوعِ کاربر نامعتبر است.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @UserID)
    BEGIN SELECT 0 AS Success, N'کاربر یافت نشد.' AS Message; RETURN; END

    IF @UserType <> N'PERSON' SET @CrmPersonID = NULL;
    IF @UserType <> N'PARTY' SET @CrmPartyID = NULL;

    IF @UserType = N'PERSON' AND @CrmPersonID IS NULL
    BEGIN SELECT 0 AS Success, N'مخاطبِ این کاربر را انتخاب کنید.' AS Message; RETURN; END
    IF @UserType = N'PARTY' AND @CrmPartyID IS NULL
    BEGIN SELECT 0 AS Success, N'طرف‌حسابِ این کاربر را انتخاب کنید.' AS Message; RETURN; END
    IF @CrmPersonID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmPersons WHERE PersonID = @CrmPersonID)
    BEGIN SELECT 0 AS Success, N'مخاطبِ انتخاب‌شده یافت نشد.' AS Message; RETURN; END
    IF @CrmPartyID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @CrmPartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حسابِ انتخاب‌شده یافت نشد.' AS Message; RETURN; END

    DECLARE @Other NVARCHAR(200);
    SELECT TOP 1 @Other = ISNULL(NULLIF(LTRIM(RTRIM(FullName)), N''), UserName)
    FROM dbo.Users WITH (UPDLOCK, HOLDLOCK)
    WHERE UserID <> @UserID
      AND ((@CrmPersonID IS NOT NULL AND CrmPersonID = @CrmPersonID) OR (@CrmPartyID IS NOT NULL AND CrmPartyID = @CrmPartyID));
    IF @Other IS NOT NULL
    BEGIN
        SELECT 0 AS Success,
               CASE WHEN @CrmPersonID IS NOT NULL THEN N'این مخاطب قبلاً به کاربرِ «' ELSE N'این طرف‌حساب قبلاً به کاربرِ «' END + @Other + N'» متصل شده است.' AS Message;
        RETURN;
    END

    UPDATE dbo.Users
    SET UserType = @UserType, CrmPersonID = @CrmPersonID, CrmPartyID = @CrmPartyID, ModifyDate = GETDATE(), ModifyUser = @ModifyUser
    WHERE UserID = @UserID;

    SELECT 1 AS Success, N'نوعِ کاربر ذخیره شد.' AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SetEntityUser
-- پچِ ۱۲۱: تعیین/برداشتنِ کاربرِ یک مخاطب یا طرف‌حساب — از صفحهٔ اصلیِ CRM.
-- @UserID = NULL: کاربرِ فعلی جدا و «داخلی» می‌شود؛ کاربرِ جدید نوعِ مخاطب/طرف‌حساب می‌گیرد و کاربرِ قبلیِ همین رکورد جدا می‌شود.
CREATE PROCEDURE dbo.sp_Crm_SetEntityUser
    @EntityType NVARCHAR(10),   -- PARTY | PERSON
    @EntityID   INT,
    @UserID     INT = NULL,
    @ModifyUser INT = NULL
AS
BEGIN
    SET NOCOUNT ON;

    IF @EntityType NOT IN (N'PARTY', N'PERSON')
    BEGIN SELECT 0 AS Success, N'نوعِ رکورد نامعتبر است.' AS Message; RETURN; END
    IF @EntityType = N'PARTY' AND NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @EntityID)
    BEGIN SELECT 0 AS Success, N'طرف‌حساب یافت نشد.' AS Message; RETURN; END
    IF @EntityType = N'PERSON' AND NOT EXISTS (SELECT 1 FROM dbo.CrmPersons WHERE PersonID = @EntityID)
    BEGIN SELECT 0 AS Success, N'مخاطب یافت نشد.' AS Message; RETURN; END

    IF @UserID IS NOT NULL
    BEGIN
        DECLARE @Type NVARCHAR(10), @PersonID INT, @PartyID INT;
        SELECT @Type = UserType, @PersonID = CrmPersonID, @PartyID = CrmPartyID FROM dbo.Users WITH (UPDLOCK, HOLDLOCK) WHERE UserID = @UserID;
        IF @Type IS NULL
        BEGIN SELECT 0 AS Success, N'کاربر یافت نشد.' AS Message; RETURN; END
        -- کاربری که به مخاطب/طرف‌حسابِ دیگری متصل است، اول باید از آنجا جدا شود
        IF (@PersonID IS NOT NULL AND NOT (@EntityType = N'PERSON' AND @PersonID = @EntityID))
           OR (@PartyID IS NOT NULL AND NOT (@EntityType = N'PARTY' AND @PartyID = @EntityID))
        BEGIN
            SELECT 0 AS Success,
                   N'این کاربر قبلاً به ' + CASE WHEN @PersonID IS NOT NULL
                        THEN N'مخاطبِ «' + (SELECT LTRIM(FirstName + N' ' + LastName) FROM dbo.CrmPersons WHERE PersonID = @PersonID)
                        ELSE N'طرف‌حسابِ «' + (SELECT OfficialName FROM dbo.CrmParties WHERE PartyID = @PartyID) END
                   + N'» متصل است؛ ابتدا آن اتصال را بردارید.' AS Message;
            RETURN;
        END
    END

    BEGIN TRANSACTION;
    -- کاربرِ قبلیِ همین رکورد (اگر کسِ دیگری است) جدا و «داخلی» می‌شود
    UPDATE dbo.Users
    SET UserType = N'INTERNAL', CrmPersonID = NULL, CrmPartyID = NULL, ModifyDate = GETDATE(), ModifyUser = @ModifyUser
    WHERE (@UserID IS NULL OR UserID <> @UserID)
      AND ((@EntityType = N'PERSON' AND CrmPersonID = @EntityID) OR (@EntityType = N'PARTY' AND CrmPartyID = @EntityID));

    IF @UserID IS NOT NULL
        UPDATE dbo.Users
        SET UserType = @EntityType,
            CrmPersonID = CASE WHEN @EntityType = N'PERSON' THEN @EntityID END,
            CrmPartyID = CASE WHEN @EntityType = N'PARTY' THEN @EntityID END,
            ModifyDate = GETDATE(), ModifyUser = @ModifyUser
        WHERE UserID = @UserID;
    COMMIT TRANSACTION;

    SELECT 1 AS Success, CASE WHEN @UserID IS NULL THEN N'اتصالِ کاربر برداشته شد.' ELSE N'کاربر متصل شد.' END AS Message;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetParty
ALTER PROCEDURE dbo.sp_Crm_GetParty
    @PartyID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT p.PartyID, p.PartyNature, p.OfficialName, p.TradeName, p.RegistrationNumber, p.EconomicCode,
           p.IdentifierNumber, p.IdentifierDate, p.Description,
           p.DepartmentID, p.PartyTypeID, p.ActivityID, p.IsActive, p.RowGuid, p.CooperationEndedAt, -- پچِ ۱۱۹
           (SELECT TOP 1 lu.UserID FROM dbo.Users lu WHERE lu.CrmPartyID = p.PartyID) AS LinkedUserID, -- پچِ ۱۲۱
           (SELECT TOP 1 ISNULL(NULLIF(LTRIM(RTRIM(lu.FullName)), N''), lu.UserName) FROM dbo.Users lu WHERE lu.CrmPartyID = p.PartyID) AS LinkedUserName,
           p.Date_InsertFirst, p.UserID_InsertFirst, p.Date_LastUpdate, p.UserID_LastUpdate,
           ISNULL(p.OfficialName, N'') AS DisplayName,
           d.DisplayName AS DepartmentName,
           pt.DisplayName AS PartyTypeName,
           a.DisplayName AS ActivityName,
           cu.FirstName + N' ' + cu.LastName AS CreatedByName,
           mu.FirstName + N' ' + mu.LastName AS ModifiedByName,
           (SELECT COUNT(*) FROM dbo.CrmPartyClassifications pc WHERE pc.PartyID = p.PartyID AND pc.IsActive = 1) AS ClassificationCount
    FROM dbo.CrmParties p
    LEFT JOIN dbo.CrmDepartments d ON d.DepartmentID = p.DepartmentID
    LEFT JOIN dbo.CrmPartyTypes pt ON pt.PartyTypeID = p.PartyTypeID
    LEFT JOIN dbo.CrmActivities a ON a.ActivityID = p.ActivityID
    LEFT JOIN dbo.Users cu ON cu.UserID = p.UserID_InsertFirst
    LEFT JOIN dbo.Users mu ON mu.UserID = p.UserID_LastUpdate
    WHERE p.PartyID = @PartyID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetPerson
ALTER PROCEDURE dbo.sp_Crm_GetPerson
    @PersonID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT per.PersonID, per.FirstName, per.LastName, per.TitleID, per.IdentifierNumber, per.IdentifierDate,
           per.Description, per.IsActive, per.RowGuid, per.CooperationEndedAt, -- پچِ ۱۱۹
           (SELECT TOP 1 lu.UserID FROM dbo.Users lu WHERE lu.CrmPersonID = per.PersonID) AS LinkedUserID, -- پچِ ۱۲۱
           (SELECT TOP 1 ISNULL(NULLIF(LTRIM(RTRIM(lu.FullName)), N''), lu.UserName) FROM dbo.Users lu WHERE lu.CrmPersonID = per.PersonID) AS LinkedUserName,
           LTRIM(per.FirstName + N' ' + per.LastName) AS DisplayName,
           t.DisplayName AS TitleName,
           per.Date_InsertFirst, per.UserID_InsertFirst, per.Date_LastUpdate, per.UserID_LastUpdate,
           cu.FirstName + N' ' + cu.LastName AS CreatedByName,
           mu.FirstName + N' ' + mu.LastName AS ModifiedByName
    FROM dbo.CrmPersons per
    LEFT JOIN dbo.CrmTitles t ON t.TitleID = per.TitleID
    LEFT JOIN dbo.Users cu ON cu.UserID = per.UserID_InsertFirst
    LEFT JOIN dbo.Users mu ON mu.UserID = per.UserID_LastUpdate
    WHERE per.PersonID = @PersonID;
END
GO

