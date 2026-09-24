/* ==========================================================================
   پچ خودکار شماره: 048 | نام: crm_party_reintroduce_nature
   تاریخ: 2026-09-24 16:22:27 | شامل 6 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties ADD PartyNature NVARCHAR(20) NOT NULL CONSTRAINT DF_CrmParties_PartyNature DEFAULT (N'LEGAL')
GO

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties DROP CONSTRAINT DF_CrmParties_PartyNature
GO

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties ADD CONSTRAINT CK_CrmParties_PartyNature CHECK (PartyNature IN (N'INDIVIDUAL', N'LEGAL'))
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveParty
ALTER PROCEDURE dbo.sp_Crm_SaveParty
    @PartyID            INT           = NULL,
    @PartyNature        NVARCHAR(20),
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

    IF @PartyNature NOT IN (N'INDIVIDUAL', N'LEGAL')
    BEGIN SELECT 0 AS Success, N'ماهیتِ طرف‌حساب نامعتبر است.' AS Message; RETURN; END

    IF NULLIF(LTRIM(RTRIM(@OfficialName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نام الزامی است.' AS Message; RETURN; END

    IF NULLIF(LTRIM(RTRIM(@IdentifierNumber)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'شناسه الزامی است.' AS Message; RETURN; END

    IF @ActivityID IS NOT NULL AND @PartyTypeID IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM dbo.CrmActivities WHERE ActivityID = @ActivityID AND PartyTypeID = @PartyTypeID)
    BEGIN SELECT 0 AS Success, N'فعالیتِ انتخاب‌شده متعلق به نوعِ انتخاب‌شده نیست.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.CrmParties
               WHERE IdentifierNumber = @IdentifierNumber
                 AND (@PartyID IS NULL OR PartyID <> @PartyID))
    BEGIN SELECT 0 AS Success, N'طرف‌حسابی با این شناسه از قبل ثبت شده است.' AS Message; RETURN; END

    IF @PartyID IS NULL
    BEGIN
        INSERT INTO dbo.CrmParties (
            PartyNature, OfficialName, TradeName, RegistrationNumber, EconomicCode, IdentifierNumber, IdentifierDate,
            Description, DepartmentID, PartyTypeID, ActivityID, IsActive,
            Date_InsertFirst, UserID_InsertFirst
        )
        VALUES (
            @PartyNature, @OfficialName, @TradeName, @RegistrationNumber, @EconomicCode, @IdentifierNumber, @IdentifierDate,
            @Description, @DepartmentID, @PartyTypeID, @ActivityID, 1,
            SYSDATETIME(), @UserID
        );

        SELECT 1 AS Success, N'طرف‌حساب ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS PartyID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
        BEGIN SELECT 0 AS Success, N'طرف‌حساب یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmParties
        SET PartyNature = @PartyNature, OfficialName = @OfficialName, TradeName = @TradeName, RegistrationNumber = @RegistrationNumber,
            EconomicCode = @EconomicCode, IdentifierNumber = @IdentifierNumber, IdentifierDate = @IdentifierDate,
            Description = @Description, DepartmentID = @DepartmentID, PartyTypeID = @PartyTypeID, ActivityID = @ActivityID,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE PartyID = @PartyID;

        SELECT 1 AS Success, N'طرف‌حساب ویرایش شد.' AS Message, @PartyID AS PartyID;
    END
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
ALTER PROCEDURE dbo.sp_Crm_GetParties
    @SearchText   NVARCHAR(200) = NULL,
    @IsActive     BIT           = NULL,
    @DepartmentID INT           = NULL,
    @PartyNature  NVARCHAR(20)  = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT p.PartyID, p.PartyNature, p.OfficialName, p.TradeName, p.RegistrationNumber, p.EconomicCode,
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
      AND (@PartyNature IS NULL OR p.PartyNature = @PartyNature)
    ORDER BY p.Date_InsertFirst DESC;
END
GO

