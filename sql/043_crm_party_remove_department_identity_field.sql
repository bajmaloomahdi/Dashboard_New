/* ==========================================================================
   پچ خودکار شماره: 043 | نام: crm_party_remove_department_identity_field
   تاریخ: 2026-09-24 11:50:19 | شامل 6 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties DROP CONSTRAINT FK_CrmParties_Department
GO

-- [DROP_INDEX] روی INDEX: IX_CrmParties_DepartmentID
DROP INDEX IX_CrmParties_DepartmentID ON dbo.CrmParties
GO

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties DROP COLUMN DepartmentID
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
            Description, IsActive,
            Date_InsertFirst, UserID_InsertFirst
        )
        VALUES (
            @PartyNature, @TitleID, @FirstName, @LastName, @OfficialName, @TradeName,
            @RegistrationNumber, @EconomicCode, @IdentifierNumber, @IdentifierDate,
            @Description, 1,
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
            Description = @Description,
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
           p.IsActive, p.RowGuid,
           p.Date_InsertFirst, p.UserID_InsertFirst, p.Date_LastUpdate, p.UserID_LastUpdate,
           CASE WHEN p.PartyNature = N'INDIVIDUAL'
                THEN LTRIM(RTRIM(ISNULL(p.FirstName, N'') + N' ' + ISNULL(p.LastName, N'')))
                ELSE ISNULL(p.OfficialName, N'') END AS DisplayName,
           t.DisplayName AS TitleName,
           cu.FirstName + N' ' + cu.LastName AS CreatedByName,
           mu.FirstName + N' ' + mu.LastName AS ModifiedByName
    FROM dbo.CrmParties p
    LEFT JOIN dbo.CrmTitles t ON t.TitleID = p.TitleID
    LEFT JOIN dbo.Users cu ON cu.UserID = p.UserID_InsertFirst
    LEFT JOIN dbo.Users mu ON mu.UserID = p.UserID_LastUpdate
    WHERE p.PartyID = @PartyID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetParties
ALTER PROCEDURE dbo.sp_Crm_GetParties
    @SearchText  NVARCHAR(200) = NULL,
    @IsActive    BIT           = NULL,
    @PartyNature NVARCHAR(20)  = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT p.PartyID, p.PartyNature, p.TitleID, p.FirstName, p.LastName,
           p.OfficialName, p.TradeName, p.RegistrationNumber, p.EconomicCode,
           p.IdentifierNumber, p.IdentifierDate, p.Description,
           p.IsActive,
           p.Date_InsertFirst, p.UserID_InsertFirst, p.Date_LastUpdate, p.UserID_LastUpdate,
           CASE WHEN p.PartyNature = N'INDIVIDUAL'
                THEN LTRIM(RTRIM(ISNULL(p.FirstName, N'') + N' ' + ISNULL(p.LastName, N'')))
                ELSE ISNULL(p.OfficialName, N'') END AS DisplayName,
           t.DisplayName AS TitleName,
           (SELECT COUNT(*) FROM dbo.CrmBrands b WHERE b.PartyID = p.PartyID AND b.IsActive = 1) AS ActiveBrandCount,
           (SELECT COUNT(*) FROM dbo.CrmAddresses ad WHERE ad.PartyID = p.PartyID AND ad.IsActive = 1) AS ActiveAddressCount,
           (SELECT COUNT(*) FROM dbo.CrmContacts c WHERE c.PartyID = p.PartyID AND c.IsActive = 1) AS ActiveContactCount
    FROM dbo.CrmParties p
    LEFT JOIN dbo.CrmTitles t ON t.TitleID = p.TitleID
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR p.FirstName LIKE N'%' + @SearchText + N'%'
           OR p.LastName LIKE N'%' + @SearchText + N'%'
           OR p.OfficialName LIKE N'%' + @SearchText + N'%'
           OR p.TradeName LIKE N'%' + @SearchText + N'%'
           OR p.IdentifierNumber LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR p.IsActive = @IsActive)
      AND (@PartyNature IS NULL OR p.PartyNature = @PartyNature)
    ORDER BY p.Date_InsertFirst DESC;
END
GO

