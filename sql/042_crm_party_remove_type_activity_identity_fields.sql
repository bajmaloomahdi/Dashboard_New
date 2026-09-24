/* ==========================================================================
   پچ خودکار شماره: 042 | نام: crm_party_remove_type_activity_identity_fields
   تاریخ: 2026-09-24 11:41:26 | شامل 21 دستور SQL
   ========================================================================== */

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetDepartments
/* ==========================================================================
   Ù¾Ú† Ø®ÙˆØ¯Ú©Ø§Ø± Ø´Ù…Ø§Ø±Ù‡: 041 | Ù†Ø§Ù…: crm_masterdata_sort_by_code_and_county_province
   ØªØ§Ø±ÛŒØ®: 2026-09-24 11:02:53 | Ø´Ø§Ù…Ù„ 12 Ø¯Ø³ØªÙˆØ± SQL
   ========================================================================== */

-- [ALTER_PROCEDURE] Ø±ÙˆÛŒ PROCEDURE: sp_Crm_GetDepartments
-- Sort all CRM Master Data grids by Code ascending (numeric), and add
-- ProvinceID/ProvinceName to GetCounties so the County grid can filter by
-- both Province and City.

ALTER PROCEDURE dbo.sp_Crm_GetDepartments
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT DepartmentID, Code, DisplayName, SortOrder, IsActive,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate
    FROM dbo.CrmDepartments d
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR d.DisplayName LIKE N'%' + @SearchText + N'%'
           OR d.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR d.IsActive = @IsActive)
    ORDER BY CAST(d.Code AS INT);
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetPartyTypes
-- [ALTER_PROCEDURE] Ø±ÙˆÛŒ PROCEDURE: sp_Crm_GetPartyTypes
ALTER PROCEDURE dbo.sp_Crm_GetPartyTypes
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT PartyTypeID, Code, DisplayName, SortOrder, IsActive,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate
    FROM dbo.CrmPartyTypes t
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR t.DisplayName LIKE N'%' + @SearchText + N'%'
           OR t.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR t.IsActive = @IsActive)
    ORDER BY CAST(t.Code AS INT);
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetActivities
-- [ALTER_PROCEDURE] Ø±ÙˆÛŒ PROCEDURE: sp_Crm_GetActivities
ALTER PROCEDURE dbo.sp_Crm_GetActivities
    @PartyTypeID INT           = NULL,
    @SearchText  NVARCHAR(200) = NULL,
    @IsActive    BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT a.ActivityID, a.PartyTypeID, a.Code, a.DisplayName, a.SortOrder, a.IsActive,
           a.Date_InsertFirst, a.UserID_InsertFirst, a.Date_LastUpdate, a.UserID_LastUpdate,
           t.DisplayName AS PartyTypeName
    FROM dbo.CrmActivities a
    JOIN dbo.CrmPartyTypes t ON t.PartyTypeID = a.PartyTypeID
    WHERE (@PartyTypeID IS NULL OR a.PartyTypeID = @PartyTypeID)
      AND (@SearchText IS NULL OR @SearchText = N''
           OR a.DisplayName LIKE N'%' + @SearchText + N'%'
           OR a.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR a.IsActive = @IsActive)
    ORDER BY CAST(a.Code AS INT);
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetTitles
-- [ALTER_PROCEDURE] Ø±ÙˆÛŒ PROCEDURE: sp_Crm_GetTitles
ALTER PROCEDURE dbo.sp_Crm_GetTitles
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TitleID, Code, DisplayName, SortOrder, IsActive,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate
    FROM dbo.CrmTitles x
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR x.DisplayName LIKE N'%' + @SearchText + N'%'
           OR x.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR x.IsActive = @IsActive)
    ORDER BY CAST(x.Code AS INT);
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetPositions
-- [ALTER_PROCEDURE] Ø±ÙˆÛŒ PROCEDURE: sp_Crm_GetPositions
ALTER PROCEDURE dbo.sp_Crm_GetPositions
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT PositionID, Code, DisplayName, SortOrder, IsActive,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate
    FROM dbo.CrmPositions p
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR p.DisplayName LIKE N'%' + @SearchText + N'%'
           OR p.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR p.IsActive = @IsActive)
    ORDER BY CAST(p.Code AS INT);
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetContactRoles
-- [ALTER_PROCEDURE] Ø±ÙˆÛŒ PROCEDURE: sp_Crm_GetContactRoles
ALTER PROCEDURE dbo.sp_Crm_GetContactRoles
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT ContactRoleID, Code, DisplayName, SortOrder, IsActive,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate
    FROM dbo.CrmContactRoles r
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR r.DisplayName LIKE N'%' + @SearchText + N'%'
           OR r.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR r.IsActive = @IsActive)
    ORDER BY CAST(r.Code AS INT);
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetContactTypes
-- [ALTER_PROCEDURE] Ø±ÙˆÛŒ PROCEDURE: sp_Crm_GetContactTypes
ALTER PROCEDURE dbo.sp_Crm_GetContactTypes
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT ContactTypeID, Code, DisplayName, SortOrder, IsActive,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate
    FROM dbo.CrmContactTypes c
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR c.DisplayName LIKE N'%' + @SearchText + N'%'
           OR c.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR c.IsActive = @IsActive)
    ORDER BY CAST(c.Code AS INT);
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetAddressTitles
-- [ALTER_PROCEDURE] Ø±ÙˆÛŒ PROCEDURE: sp_Crm_GetAddressTitles
ALTER PROCEDURE dbo.sp_Crm_GetAddressTitles
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT AddressTitleID, Code, DisplayName, SortOrder, IsActive,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate
    FROM dbo.CrmAddressTitles t
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR t.DisplayName LIKE N'%' + @SearchText + N'%'
           OR t.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR t.IsActive = @IsActive)
    ORDER BY CAST(t.Code AS INT);
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetProvinces
-- [ALTER_PROCEDURE] Ø±ÙˆÛŒ PROCEDURE: sp_Crm_GetProvinces
ALTER PROCEDURE dbo.sp_Crm_GetProvinces
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT ProvinceID, Code, DisplayName, SortOrder, IsActive,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate
    FROM dbo.CrmProvinces p
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR p.DisplayName LIKE N'%' + @SearchText + N'%'
           OR p.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR p.IsActive = @IsActive)
    ORDER BY CAST(p.Code AS INT);
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetCities
-- [ALTER_PROCEDURE] Ø±ÙˆÛŒ PROCEDURE: sp_Crm_GetCities
ALTER PROCEDURE dbo.sp_Crm_GetCities
    @ProvinceID INT           = NULL,
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT c.CityID, c.ProvinceID, c.Code, c.DisplayName, c.SortOrder, c.IsActive,
           c.Date_InsertFirst, c.UserID_InsertFirst, c.Date_LastUpdate, c.UserID_LastUpdate,
           p.DisplayName AS ProvinceName
    FROM dbo.CrmCities c
    JOIN dbo.CrmProvinces p ON p.ProvinceID = c.ProvinceID
    WHERE (@ProvinceID IS NULL OR c.ProvinceID = @ProvinceID)
      AND (@SearchText IS NULL OR @SearchText = N''
           OR c.DisplayName LIKE N'%' + @SearchText + N'%'
           OR c.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR c.IsActive = @IsActive)
    ORDER BY CAST(c.Code AS INT);
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetCounties
-- [ALTER_PROCEDURE] Ø±ÙˆÛŒ PROCEDURE: sp_Crm_GetCounties
ALTER PROCEDURE dbo.sp_Crm_GetCounties
    @CityID     INT           = NULL,
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT co.CountyID, co.CityID, co.Code, co.DisplayName, co.SortOrder, co.IsActive,
           co.Date_InsertFirst, co.UserID_InsertFirst, co.Date_LastUpdate, co.UserID_LastUpdate,
           c.DisplayName AS CityName,
           c.ProvinceID  AS ProvinceID,
           p.DisplayName AS ProvinceName
    FROM dbo.CrmCounties co
    JOIN dbo.CrmCities c ON c.CityID = co.CityID
    JOIN dbo.CrmProvinces p ON p.ProvinceID = c.ProvinceID
    WHERE (@CityID IS NULL OR co.CityID = @CityID)
      AND (@SearchText IS NULL OR @SearchText = N''
           OR co.DisplayName LIKE N'%' + @SearchText + N'%'
           OR co.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR co.IsActive = @IsActive)
    ORDER BY CAST(co.Code AS INT);
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetMunicipalZones
-- [ALTER_PROCEDURE] Ø±ÙˆÛŒ PROCEDURE: sp_Crm_GetMunicipalZones
ALTER PROCEDURE dbo.sp_Crm_GetMunicipalZones
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT MunicipalZoneID, Code, DisplayName, SortOrder, IsActive,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate
    FROM dbo.CrmMunicipalZones z
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR z.DisplayName LIKE N'%' + @SearchText + N'%'
           OR z.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR z.IsActive = @IsActive)
    ORDER BY CAST(z.Code AS INT);
END
GO

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties DROP CONSTRAINT FK_CrmParties_PartyType
GO

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties DROP CONSTRAINT FK_CrmParties_Activity
GO

-- [DROP_INDEX] روی INDEX: IX_CrmParties_PartyTypeID
DROP INDEX IX_CrmParties_PartyTypeID ON dbo.CrmParties
GO

-- [DROP_INDEX] روی INDEX: IX_CrmParties_ActivityID
DROP INDEX IX_CrmParties_ActivityID ON dbo.CrmParties
GO

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties DROP COLUMN PartyTypeID
GO

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties DROP COLUMN ActivityID
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveParty
ALTER PROCEDURE dbo.sp_Crm_SaveParty
    @PartyID            INT           = NULL,
    @PartyNature        NVARCHAR(20),
    @TitleID            INT           = NULL,
    @FirstName          NVARCHAR(100) = NULL,
    @LastName           NVARCHAR(100) = NULL,
    @OfficialName       NVARCHAR(200) = NULL,
    @TradeName          NVARCHAR(200) = NULL,
    @RegistrationNumber NVARCHAR(50)  = NULL,
    @EconomicCode       NVARCHAR(50)  = NULL,
    @IdentifierNumber   NVARCHAR(20),
    @IdentifierDate     DATE          = NULL,
    @Description        NVARCHAR(MAX) = NULL,
    @DepartmentID       INT           = NULL,
    @UserID             INT
AS
BEGIN
    SET NOCOUNT ON;

    IF @PartyNature NOT IN (N'INDIVIDUAL', N'LEGAL')
    BEGIN SELECT 0 AS Success, N'Ù…Ø§Ù‡ÛŒØªÙ Ø·Ø±Ùâ€ŒØ­Ø³Ø§Ø¨ Ù†Ø§Ù…Ø¹ØªØ¨Ø± Ø§Ø³Øª.' AS Message; RETURN; END

    IF NULLIF(LTRIM(RTRIM(@IdentifierNumber)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'Ø´Ù†Ø§Ø³Ù‡ Ø§Ù„Ø²Ø§Ù…ÛŒ Ø§Ø³Øª.' AS Message; RETURN; END

    IF @PartyNature = N'INDIVIDUAL' AND (NULLIF(LTRIM(RTRIM(@FirstName)), N'') IS NULL OR NULLIF(LTRIM(RTRIM(@LastName)), N'') IS NULL)
    BEGIN SELECT 0 AS Success, N'Ù†Ø§Ù… Ùˆ Ù†Ø§Ù…Ù Ø®Ø§Ù†ÙˆØ§Ø¯Ú¯ÛŒ Ø¨Ø±Ø§ÛŒÙ Ø´Ø®ØµÙ Ø­Ù‚ÛŒÙ‚ÛŒ Ø§Ù„Ø²Ø§Ù…ÛŒ Ø§Ø³Øª.' AS Message; RETURN; END

    IF @PartyNature = N'LEGAL' AND NULLIF(LTRIM(RTRIM(@OfficialName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'Ù†Ø§Ù…Ù Ø±Ø³Ù…ÛŒ Ø¨Ø±Ø§ÛŒÙ Ø´Ø®ØµÙ Ø­Ù‚ÙˆÙ‚ÛŒ Ø§Ù„Ø²Ø§Ù…ÛŒ Ø§Ø³Øª.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.CrmParties
               WHERE PartyNature = @PartyNature AND IdentifierNumber = @IdentifierNumber
                 AND (@PartyID IS NULL OR PartyID <> @PartyID))
    BEGIN SELECT 0 AS Success, N'Ø·Ø±Ùâ€ŒØ­Ø³Ø§Ø¨ÛŒ Ø¨Ø§ Ø§ÛŒÙ† Ø´Ù†Ø§Ø³Ù‡ Ø§Ø² Ù‚Ø¨Ù„ Ø«Ø¨Øª Ø´Ø¯Ù‡ Ø§Ø³Øª.' AS Message; RETURN; END

    IF @PartyID IS NULL
    BEGIN
        INSERT INTO dbo.CrmParties (
            PartyNature, TitleID, FirstName, LastName, OfficialName, TradeName,
            RegistrationNumber, EconomicCode, IdentifierNumber, IdentifierDate,
            Description, DepartmentID, IsActive,
            Date_InsertFirst, UserID_InsertFirst
        )
        VALUES (
            @PartyNature, @TitleID, @FirstName, @LastName, @OfficialName, @TradeName,
            @RegistrationNumber, @EconomicCode, @IdentifierNumber, @IdentifierDate,
            @Description, @DepartmentID, 1,
            SYSDATETIME(), @UserID
        );

        SELECT 1 AS Success, N'Ø·Ø±Ùâ€ŒØ­Ø³Ø§Ø¨ Ø§ÛŒØ¬Ø§Ø¯ Ø´Ø¯.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS PartyID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
        BEGIN SELECT 0 AS Success, N'Ø·Ø±Ùâ€ŒØ­Ø³Ø§Ø¨ ÛŒØ§ÙØª Ù†Ø´Ø¯.' AS Message; RETURN; END

        UPDATE dbo.CrmParties
        SET PartyNature = @PartyNature, TitleID = @TitleID, FirstName = @FirstName, LastName = @LastName,
            OfficialName = @OfficialName, TradeName = @TradeName, RegistrationNumber = @RegistrationNumber,
            EconomicCode = @EconomicCode, IdentifierNumber = @IdentifierNumber, IdentifierDate = @IdentifierDate,
            Description = @Description, DepartmentID = @DepartmentID,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE PartyID = @PartyID;

        SELECT 1 AS Success, N'Ø·Ø±Ùâ€ŒØ­Ø³Ø§Ø¨ ÙˆÛŒØ±Ø§ÛŒØ´ Ø´Ø¯.' AS Message, @PartyID AS PartyID;
    END
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetParty
ALTER PROCEDURE dbo.sp_Crm_GetParty
    @PartyID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT p.PartyID, p.PartyNature, p.TitleID, p.FirstName, p.LastName,
           p.OfficialName, p.TradeName, p.RegistrationNumber, p.EconomicCode,
           p.IdentifierNumber, p.IdentifierDate, p.Description,
           p.DepartmentID, p.IsActive, p.RowGuid,
           p.Date_InsertFirst, p.UserID_InsertFirst, p.Date_LastUpdate, p.UserID_LastUpdate,
           CASE WHEN p.PartyNature = N'INDIVIDUAL'
                THEN LTRIM(RTRIM(ISNULL(p.FirstName, N'') + N' ' + ISNULL(p.LastName, N'')))
                ELSE ISNULL(p.OfficialName, N'') END AS DisplayName,
           t.DisplayName AS TitleName,
           d.DisplayName AS DepartmentName,
           cu.FirstName + N' ' + cu.LastName AS CreatedByName,
           mu.FirstName + N' ' + mu.LastName AS ModifiedByName
    FROM dbo.CrmParties p
    LEFT JOIN dbo.CrmTitles t ON t.TitleID = p.TitleID
    LEFT JOIN dbo.CrmDepartments d ON d.DepartmentID = p.DepartmentID
    LEFT JOIN dbo.Users cu ON cu.UserID = p.UserID_InsertFirst
    LEFT JOIN dbo.Users mu ON mu.UserID = p.UserID_LastUpdate
    WHERE p.PartyID = @PartyID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetParties
ALTER PROCEDURE dbo.sp_Crm_GetParties
    @SearchText  NVARCHAR(200) = NULL,
    @IsActive    BIT           = NULL,
    @PartyNature NVARCHAR(20)  = NULL,
    @DepartmentID INT          = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT p.PartyID, p.PartyNature, p.TitleID, p.FirstName, p.LastName,
           p.OfficialName, p.TradeName, p.RegistrationNumber, p.EconomicCode,
           p.IdentifierNumber, p.IdentifierDate, p.Description,
           p.DepartmentID, p.IsActive,
           p.Date_InsertFirst, p.UserID_InsertFirst, p.Date_LastUpdate, p.UserID_LastUpdate,
           CASE WHEN p.PartyNature = N'INDIVIDUAL'
                THEN LTRIM(RTRIM(ISNULL(p.FirstName, N'') + N' ' + ISNULL(p.LastName, N'')))
                ELSE ISNULL(p.OfficialName, N'') END AS DisplayName,
           t.DisplayName AS TitleName,
           d.DisplayName AS DepartmentName,
           (SELECT COUNT(*) FROM dbo.CrmBrands b WHERE b.PartyID = p.PartyID AND b.IsActive = 1) AS ActiveBrandCount,
           (SELECT COUNT(*) FROM dbo.CrmAddresses ad WHERE ad.PartyID = p.PartyID AND ad.IsActive = 1) AS ActiveAddressCount,
           (SELECT COUNT(*) FROM dbo.CrmContacts c WHERE c.PartyID = p.PartyID AND c.IsActive = 1) AS ActiveContactCount
    FROM dbo.CrmParties p
    LEFT JOIN dbo.CrmTitles t ON t.TitleID = p.TitleID
    LEFT JOIN dbo.CrmDepartments d ON d.DepartmentID = p.DepartmentID
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR p.FirstName LIKE N'%' + @SearchText + N'%'
           OR p.LastName LIKE N'%' + @SearchText + N'%'
           OR p.OfficialName LIKE N'%' + @SearchText + N'%'
           OR p.TradeName LIKE N'%' + @SearchText + N'%'
           OR p.IdentifierNumber LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR p.IsActive = @IsActive)
      AND (@PartyNature IS NULL OR p.PartyNature = @PartyNature)
      AND (@DepartmentID IS NULL OR p.DepartmentID = @DepartmentID)
    ORDER BY p.Date_InsertFirst DESC;
END
GO

