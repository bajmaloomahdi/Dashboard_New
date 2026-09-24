/* ==========================================================================
   پچ خودکار شماره: 044 | نام: crm_party_business_only_person_identity_enrichment
   تاریخ: 2026-09-24 12:11:47 | شامل 25 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties DROP CONSTRAINT FK_CrmParties_Title
GO

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties DROP CONSTRAINT UQ_CrmParties_Nature_Identifier
GO

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties DROP COLUMN PartyNature
GO

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties DROP COLUMN TitleID
GO

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties DROP COLUMN FirstName
GO

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties DROP COLUMN LastName
GO

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties ADD DepartmentID INT NULL
GO

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties ADD PartyTypeID INT NULL
GO

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties ADD ActivityID INT NULL
GO

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties ADD CONSTRAINT FK_CrmParties_Department FOREIGN KEY (DepartmentID) REFERENCES dbo.CrmDepartments(DepartmentID)
GO

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties ADD CONSTRAINT FK_CrmParties_PartyType FOREIGN KEY (PartyTypeID) REFERENCES dbo.CrmPartyTypes(PartyTypeID)
GO

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties ADD CONSTRAINT FK_CrmParties_Activity FOREIGN KEY (ActivityID) REFERENCES dbo.CrmActivities(ActivityID)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmParties_DepartmentID
CREATE INDEX IX_CrmParties_DepartmentID ON dbo.CrmParties (DepartmentID)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmParties_PartyTypeID
CREATE INDEX IX_CrmParties_PartyTypeID ON dbo.CrmParties (PartyTypeID)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmParties_ActivityID
CREATE INDEX IX_CrmParties_ActivityID ON dbo.CrmParties (ActivityID)
GO

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties ADD CONSTRAINT UQ_CrmParties_Identifier UNIQUE (IdentifierNumber)
GO

-- [ALTER_TABLE] روی TABLE: CrmPersons
ALTER TABLE dbo.CrmPersons ADD TitleID INT NULL
GO

-- [ALTER_TABLE] روی TABLE: CrmPersons
ALTER TABLE dbo.CrmPersons ADD IdentifierNumber NVARCHAR(20) NULL
GO

-- [ALTER_TABLE] روی TABLE: CrmPersons
ALTER TABLE dbo.CrmPersons ADD IdentifierDate DATE NULL
GO

-- [ALTER_TABLE] روی TABLE: CrmPersons
ALTER TABLE dbo.CrmPersons ADD CONSTRAINT FK_CrmPersons_Title FOREIGN KEY (TitleID) REFERENCES dbo.CrmTitles(TitleID)
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveParty
/* ===================== sp_Crm_SaveParty ===================== */

ALTER PROCEDURE dbo.sp_Crm_SaveParty
    @PartyID            INT           = NULL,
    @OfficialName       NVARCHAR(200),
    @TradeName          NVARCHAR(200) = NULL,
    @RegistrationNumber NVARCHAR(50)  = NULL,
    @EconomicCode       NVARCHAR(50)  = NULL,
    @IdentifierNumber   NVARCHAR(20),
    @IdentifierDate     DATE          = NULL,
    @Description        NVARCHAR(MAX) = NULL,
    @DepartmentID       INT           = NULL,
    @PartyTypeID        INT           = NULL,
    @ActivityID         INT           = NULL,
    @UserID             INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@OfficialName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'Ù†Ø§Ù…Ù Ø±Ø³Ù…ÛŒ Ø§Ù„Ø²Ø§Ù…ÛŒ Ø§Ø³Øª.' AS Message; RETURN; END

    IF NULLIF(LTRIM(RTRIM(@IdentifierNumber)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'Ø´Ù†Ø§Ø³Ù‡ Ø§Ù„Ø²Ø§Ù…ÛŒ Ø§Ø³Øª.' AS Message; RETURN; END

    IF @ActivityID IS NOT NULL AND @PartyTypeID IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM dbo.CrmActivities WHERE ActivityID = @ActivityID AND PartyTypeID = @PartyTypeID)
    BEGIN SELECT 0 AS Success, N'ÙØ¹Ø§Ù„ÛŒØªÙ Ø§Ù†ØªØ®Ø§Ø¨â€ŒØ´Ø¯Ù‡ Ù…ØªØ¹Ù„Ù‚ Ø¨Ù‡ Ù†ÙˆØ¹Ù Ø§Ù†ØªØ®Ø§Ø¨â€ŒØ´Ø¯Ù‡ Ù†ÛŒØ³Øª.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.CrmParties
               WHERE IdentifierNumber = @IdentifierNumber
                 AND (@PartyID IS NULL OR PartyID <> @PartyID))
    BEGIN SELECT 0 AS Success, N'Ø·Ø±Ùâ€ŒØ­Ø³Ø§Ø¨ÛŒ Ø¨Ø§ Ø§ÛŒÙ† Ø´Ù†Ø§Ø³Ù‡ Ø§Ø² Ù‚Ø¨Ù„ Ø«Ø¨Øª Ø´Ø¯Ù‡ Ø§Ø³Øª.' AS Message; RETURN; END

    IF @PartyID IS NULL
    BEGIN
        INSERT INTO dbo.CrmParties (
            OfficialName, TradeName, RegistrationNumber, EconomicCode, IdentifierNumber, IdentifierDate,
            Description, DepartmentID, PartyTypeID, ActivityID, IsActive,
            Date_InsertFirst, UserID_InsertFirst
        )
        VALUES (
            @OfficialName, @TradeName, @RegistrationNumber, @EconomicCode, @IdentifierNumber, @IdentifierDate,
            @Description, @DepartmentID, @PartyTypeID, @ActivityID, 1,
            SYSDATETIME(), @UserID
        );

        SELECT 1 AS Success, N'Ø·Ø±Ùâ€ŒØ­Ø³Ø§Ø¨ Ø§ÛŒØ¬Ø§Ø¯ Ø´Ø¯.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS PartyID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
        BEGIN SELECT 0 AS Success, N'Ø·Ø±Ùâ€ŒØ­Ø³Ø§Ø¨ ÛŒØ§ÙØª Ù†Ø´Ø¯.' AS Message; RETURN; END

        UPDATE dbo.CrmParties
        SET OfficialName = @OfficialName, TradeName = @TradeName, RegistrationNumber = @RegistrationNumber,
            EconomicCode = @EconomicCode, IdentifierNumber = @IdentifierNumber, IdentifierDate = @IdentifierDate,
            Description = @Description, DepartmentID = @DepartmentID, PartyTypeID = @PartyTypeID, ActivityID = @ActivityID,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE PartyID = @PartyID;

        SELECT 1 AS Success, N'Ø·Ø±Ùâ€ŒØ­Ø³Ø§Ø¨ ÙˆÛŒØ±Ø§ÛŒØ´ Ø´Ø¯.' AS Message, @PartyID AS PartyID;
    END
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetParty
/* ===================== sp_Crm_GetParty ===================== */

ALTER PROCEDURE dbo.sp_Crm_GetParty
    @PartyID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT p.PartyID, p.OfficialName, p.TradeName, p.RegistrationNumber, p.EconomicCode,
           p.IdentifierNumber, p.IdentifierDate, p.Description,
           p.DepartmentID, p.PartyTypeID, p.ActivityID, p.IsActive, p.RowGuid,
           p.Date_InsertFirst, p.UserID_InsertFirst, p.Date_LastUpdate, p.UserID_LastUpdate,
           ISNULL(p.OfficialName, N'') AS DisplayName,
           d.DisplayName AS DepartmentName,
           pt.DisplayName AS PartyTypeName,
           a.DisplayName AS ActivityName,
           cu.FirstName + N' ' + cu.LastName AS CreatedByName,
           mu.FirstName + N' ' + mu.LastName AS ModifiedByName
    FROM dbo.CrmParties p
    LEFT JOIN dbo.CrmDepartments d ON d.DepartmentID = p.DepartmentID
    LEFT JOIN dbo.CrmPartyTypes pt ON pt.PartyTypeID = p.PartyTypeID
    LEFT JOIN dbo.CrmActivities a ON a.ActivityID = p.ActivityID
    LEFT JOIN dbo.Users cu ON cu.UserID = p.UserID_InsertFirst
    LEFT JOIN dbo.Users mu ON mu.UserID = p.UserID_LastUpdate
    WHERE p.PartyID = @PartyID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetParties
/* ===================== sp_Crm_GetParties ===================== */

ALTER PROCEDURE dbo.sp_Crm_GetParties
    @SearchText   NVARCHAR(200) = NULL,
    @IsActive     BIT           = NULL,
    @DepartmentID INT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT p.PartyID, p.OfficialName, p.TradeName, p.RegistrationNumber, p.EconomicCode,
           p.IdentifierNumber, p.IdentifierDate, p.Description,
           p.DepartmentID, p.PartyTypeID, p.ActivityID, p.IsActive,
           p.Date_InsertFirst, p.UserID_InsertFirst, p.Date_LastUpdate, p.UserID_LastUpdate,
           ISNULL(p.OfficialName, N'') AS DisplayName,
           d.DisplayName AS DepartmentName,
           pt.DisplayName AS PartyTypeName,
           a.DisplayName AS ActivityName,
           (SELECT COUNT(*) FROM dbo.CrmBrands b WHERE b.PartyID = p.PartyID AND b.IsActive = 1) AS ActiveBrandCount,
           (SELECT COUNT(*) FROM dbo.CrmAddresses ad WHERE ad.PartyID = p.PartyID AND ad.IsActive = 1) AS ActiveAddressCount,
           (SELECT COUNT(*) FROM dbo.CrmContacts c WHERE c.PartyID = p.PartyID AND c.IsActive = 1) AS ActiveContactCount
    FROM dbo.CrmParties p
    LEFT JOIN dbo.CrmDepartments d ON d.DepartmentID = p.DepartmentID
    LEFT JOIN dbo.CrmPartyTypes pt ON pt.PartyTypeID = p.PartyTypeID
    LEFT JOIN dbo.CrmActivities a ON a.ActivityID = p.ActivityID
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR p.OfficialName LIKE N'%' + @SearchText + N'%'
           OR p.TradeName LIKE N'%' + @SearchText + N'%'
           OR p.IdentifierNumber LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR p.IsActive = @IsActive)
      AND (@DepartmentID IS NULL OR p.DepartmentID = @DepartmentID)
    ORDER BY p.Date_InsertFirst DESC;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetPersons
/* ===================== sp_Crm_GetPersons ===================== */

ALTER PROCEDURE dbo.sp_Crm_GetPersons
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT per.PersonID, per.FirstName, per.LastName, per.TitleID, per.IdentifierNumber, per.IdentifierDate, per.IsActive,
           (per.FirstName + N' ' + per.LastName) AS DisplayName,
           t.DisplayName AS TitleName,
           per.Date_InsertFirst, per.UserID_InsertFirst, per.Date_LastUpdate, per.UserID_LastUpdate
    FROM dbo.CrmPersons per
    LEFT JOIN dbo.CrmTitles t ON t.TitleID = per.TitleID
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR per.FirstName LIKE N'%' + @SearchText + N'%'
           OR per.LastName LIKE N'%' + @SearchText + N'%'
           OR (per.FirstName + N' ' + per.LastName) LIKE N'%' + @SearchText + N'%'
           OR per.IdentifierNumber LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR per.IsActive = @IsActive)
    ORDER BY per.PersonID DESC;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SavePerson
/* ===================== sp_Crm_SavePerson ===================== */

ALTER PROCEDURE dbo.sp_Crm_SavePerson
    @PersonID        INT           = NULL,
    @FirstName       NVARCHAR(100),
    @LastName        NVARCHAR(100),
    @TitleID         INT           = NULL,
    @IdentifierNumber NVARCHAR(20) = NULL,
    @IdentifierDate  DATE          = NULL,
    @UserID          INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@FirstName)), N'') IS NULL OR NULLIF(LTRIM(RTRIM(@LastName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'Ù†Ø§Ù… Ùˆ Ù†Ø§Ù…Ù Ø®Ø§Ù†ÙˆØ§Ø¯Ú¯ÛŒ Ø§Ù„Ø²Ø§Ù…ÛŒ Ø§Ø³Øª.' AS Message; RETURN; END

    IF @PersonID IS NULL
    BEGIN
        INSERT INTO dbo.CrmPersons (FirstName, LastName, TitleID, IdentifierNumber, IdentifierDate, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@FirstName, @LastName, @TitleID, @IdentifierNumber, @IdentifierDate, 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'Ø´Ø®Øµ Ø§ÛŒØ¬Ø§Ø¯ Ø´Ø¯.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS PersonID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmPersons WHERE PersonID = @PersonID)
        BEGIN SELECT 0 AS Success, N'Ø´Ø®Øµ ÛŒØ§ÙØª Ù†Ø´Ø¯.' AS Message; RETURN; END

        UPDATE dbo.CrmPersons
        SET FirstName = @FirstName, LastName = @LastName, TitleID = @TitleID,
            IdentifierNumber = @IdentifierNumber, IdentifierDate = @IdentifierDate,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE PersonID = @PersonID;

        SELECT 1 AS Success, N'Ø´Ø®Øµ ÙˆÛŒØ±Ø§ÛŒØ´ Ø´Ø¯.' AS Message, @PersonID AS PersonID;
    END
END
GO

