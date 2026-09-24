/* ==========================================================================
   پچ خودکار شماره: 039 | نام: crm_phase2_party_brands_addresses_contacts_persons
   تاریخ: 2026-09-20 04:31:20 | شامل 37 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: CrmParties
CREATE TABLE dbo.CrmParties
(
    PartyID            INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmParties PRIMARY KEY,
    PartyNature        NVARCHAR(20)  NOT NULL, -- INDIVIDUAL / LEGAL
    TitleID            INT NULL,               -- individual only
    FirstName          NVARCHAR(100) NULL,     -- individual only
    LastName           NVARCHAR(100) NULL,     -- individual only
    OfficialName       NVARCHAR(200) NULL,     -- legal only
    TradeName          NVARCHAR(200) NULL,     -- legal only
    RegistrationNumber NVARCHAR(50)  NULL,     -- legal only
    EconomicCode       NVARCHAR(50)  NULL,     -- legal only
    IdentifierNumber   NVARCHAR(20)  NOT NULL, -- کد ملی (individual) / شناسه ملی (legal)
    IdentifierDate     DATE NULL,              -- تاریخ تولد (individual) / تاریخ ثبت (legal)
    Description        NVARCHAR(MAX) NULL,
    DepartmentID       INT NULL,
    PartyTypeID        INT NULL,
    ActivityID         INT NULL,
    IsActive           BIT NOT NULL CONSTRAINT DF_CrmParties_IsActive DEFAULT (1),
    RowGuid            UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmParties_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmParties_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate    DATETIME2 NULL,
    UserID_LastUpdate  INT NULL,
    CONSTRAINT UQ_CrmParties_Nature_Identifier UNIQUE (PartyNature, IdentifierNumber),
    CONSTRAINT FK_CrmParties_Title FOREIGN KEY (TitleID) REFERENCES dbo.CrmTitles(TitleID),
    CONSTRAINT FK_CrmParties_Department FOREIGN KEY (DepartmentID) REFERENCES dbo.CrmDepartments(DepartmentID),
    CONSTRAINT FK_CrmParties_PartyType FOREIGN KEY (PartyTypeID) REFERENCES dbo.CrmPartyTypes(PartyTypeID),
    CONSTRAINT FK_CrmParties_Activity FOREIGN KEY (ActivityID) REFERENCES dbo.CrmActivities(ActivityID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmParties_DepartmentID
CREATE INDEX IX_CrmParties_DepartmentID ON dbo.CrmParties(DepartmentID)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmParties_PartyTypeID
CREATE INDEX IX_CrmParties_PartyTypeID ON dbo.CrmParties(PartyTypeID)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmParties_ActivityID
CREATE INDEX IX_CrmParties_ActivityID ON dbo.CrmParties(ActivityID)
GO

-- [CREATE_TABLE] روی TABLE: CrmBrands
CREATE TABLE dbo.CrmBrands
(
    BrandID            INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmBrands PRIMARY KEY,
    PartyID            INT NOT NULL,
    Name               NVARCHAR(200) NOT NULL,
    Description        NVARCHAR(MAX) NULL,
    IsActive           BIT NOT NULL CONSTRAINT DF_CrmBrands_IsActive DEFAULT (1),
    RowGuid            UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmBrands_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmBrands_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate    DATETIME2 NULL,
    UserID_LastUpdate  INT NULL,
    CONSTRAINT FK_CrmBrands_Party FOREIGN KEY (PartyID) REFERENCES dbo.CrmParties(PartyID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmBrands_PartyID
CREATE INDEX IX_CrmBrands_PartyID ON dbo.CrmBrands(PartyID)
GO

-- [CREATE_TABLE] روی TABLE: CrmAddresses
CREATE TABLE dbo.CrmAddresses
(
    AddressID          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmAddresses PRIMARY KEY,
    PartyID            INT NOT NULL,
    AddressTitleID     INT NOT NULL,
    ProvinceID         INT NOT NULL,
    CityID             INT NOT NULL,
    CountyID           INT NOT NULL,
    MunicipalZoneID    INT NULL,
    PostalCode         NVARCHAR(10) NULL,
    AddressText        NVARCHAR(500) NOT NULL,
    PlateNumber        NVARCHAR(20) NULL,
    Unit               NVARCHAR(20) NULL,
    IsActive           BIT NOT NULL CONSTRAINT DF_CrmAddresses_IsActive DEFAULT (1),
    RowGuid            UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmAddresses_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmAddresses_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate    DATETIME2 NULL,
    UserID_LastUpdate  INT NULL,
    CONSTRAINT FK_CrmAddresses_Party FOREIGN KEY (PartyID) REFERENCES dbo.CrmParties(PartyID),
    CONSTRAINT FK_CrmAddresses_AddressTitle FOREIGN KEY (AddressTitleID) REFERENCES dbo.CrmAddressTitles(AddressTitleID),
    CONSTRAINT FK_CrmAddresses_Province FOREIGN KEY (ProvinceID) REFERENCES dbo.CrmProvinces(ProvinceID),
    CONSTRAINT FK_CrmAddresses_City FOREIGN KEY (CityID) REFERENCES dbo.CrmCities(CityID),
    CONSTRAINT FK_CrmAddresses_County FOREIGN KEY (CountyID) REFERENCES dbo.CrmCounties(CountyID),
    CONSTRAINT FK_CrmAddresses_MunicipalZone FOREIGN KEY (MunicipalZoneID) REFERENCES dbo.CrmMunicipalZones(MunicipalZoneID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmAddresses_PartyID
CREATE INDEX IX_CrmAddresses_PartyID ON dbo.CrmAddresses(PartyID)
GO

-- [CREATE_TABLE] روی TABLE: CrmContacts
CREATE TABLE dbo.CrmContacts
(
    ContactID          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmContacts PRIMARY KEY,
    PartyID            INT NOT NULL,
    ContactTypeID      INT NOT NULL,
    ContactValue       NVARCHAR(200) NOT NULL,
    Extension          NVARCHAR(20) NULL,
    Description        NVARCHAR(200) NULL,
    IsPrimary          BIT NOT NULL CONSTRAINT DF_CrmContacts_IsPrimary DEFAULT (0),
    IsActive           BIT NOT NULL CONSTRAINT DF_CrmContacts_IsActive DEFAULT (1),
    RowGuid            UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmContacts_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmContacts_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate    DATETIME2 NULL,
    UserID_LastUpdate  INT NULL,
    CONSTRAINT FK_CrmContacts_Party FOREIGN KEY (PartyID) REFERENCES dbo.CrmParties(PartyID),
    CONSTRAINT FK_CrmContacts_ContactType FOREIGN KEY (ContactTypeID) REFERENCES dbo.CrmContactTypes(ContactTypeID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmContacts_PartyID
CREATE INDEX IX_CrmContacts_PartyID ON dbo.CrmContacts(PartyID)
GO

-- [CREATE_TABLE] روی TABLE: CrmPersons
CREATE TABLE dbo.CrmPersons
(
    PersonID           INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmPersons PRIMARY KEY,
    FirstName          NVARCHAR(100) NOT NULL,
    LastName           NVARCHAR(100) NOT NULL,
    IsActive           BIT NOT NULL CONSTRAINT DF_CrmPersons_IsActive DEFAULT (1),
    RowGuid            UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmPersons_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmPersons_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate    DATETIME2 NULL,
    UserID_LastUpdate  INT NULL
)
GO

-- [CREATE_TABLE] روی TABLE: CrmPartyPersonRelations
CREATE TABLE dbo.CrmPartyPersonRelations
(
    RelationID         INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmPartyPersonRelations PRIMARY KEY,
    PartyID            INT NOT NULL,
    PersonID           INT NOT NULL,
    PositionID         INT NULL,
    IsPrimaryContact   BIT NOT NULL CONSTRAINT DF_CrmPartyPersonRelations_IsPrimary DEFAULT (0),
    IsActive           BIT NOT NULL CONSTRAINT DF_CrmPartyPersonRelations_IsActive DEFAULT (1),
    RowGuid            UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmPartyPersonRelations_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmPartyPersonRelations_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate    DATETIME2 NULL,
    UserID_LastUpdate  INT NULL,
    CONSTRAINT UQ_CrmPartyPersonRelations_Party_Person UNIQUE (PartyID, PersonID),
    CONSTRAINT FK_CrmPartyPersonRelations_Party FOREIGN KEY (PartyID) REFERENCES dbo.CrmParties(PartyID),
    CONSTRAINT FK_CrmPartyPersonRelations_Person FOREIGN KEY (PersonID) REFERENCES dbo.CrmPersons(PersonID),
    CONSTRAINT FK_CrmPartyPersonRelations_Position FOREIGN KEY (PositionID) REFERENCES dbo.CrmPositions(PositionID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmPartyPersonRelations_PartyID
CREATE INDEX IX_CrmPartyPersonRelations_PartyID ON dbo.CrmPartyPersonRelations(PartyID)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmPartyPersonRelations_PersonID
CREATE INDEX IX_CrmPartyPersonRelations_PersonID ON dbo.CrmPartyPersonRelations(PersonID)
GO

-- [CREATE_TABLE] روی TABLE: CrmPartyPersonRelationRoles
CREATE TABLE dbo.CrmPartyPersonRelationRoles
(
    RelationRoleID     INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmPartyPersonRelationRoles PRIMARY KEY,
    RelationID         INT NOT NULL,
    RoleID             INT NOT NULL,
    RowGuid            UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmPartyPersonRelationRoles_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmPartyPersonRelationRoles_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate    DATETIME2 NULL,
    UserID_LastUpdate  INT NULL,
    CONSTRAINT UQ_CrmPartyPersonRelationRoles_Relation_Role UNIQUE (RelationID, RoleID),
    CONSTRAINT FK_CrmPartyPersonRelationRoles_Relation FOREIGN KEY (RelationID) REFERENCES dbo.CrmPartyPersonRelations(RelationID),
    CONSTRAINT FK_CrmPartyPersonRelationRoles_Role FOREIGN KEY (RoleID) REFERENCES dbo.CrmContactRoles(ContactRoleID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmPartyPersonRelationRoles_RelationID
CREATE INDEX IX_CrmPartyPersonRelationRoles_RelationID ON dbo.CrmPartyPersonRelationRoles(RelationID)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetParties
-- =============================================================
-- CRM Phase 2 SPs: Parties, Brands, Addresses, Contacts, Persons, Relations
-- =============================================================

/* ===================== CrmParties ===================== */
CREATE PROCEDURE dbo.sp_Crm_GetParties
    @SearchText  NVARCHAR(200) = NULL,
    @IsActive    BIT           = NULL,
    @PartyNature NVARCHAR(20)  = NULL,
    @DepartmentID INT          = NULL,
    @PartyTypeID  INT          = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT p.PartyID, p.PartyNature, p.TitleID, p.FirstName, p.LastName,
           p.OfficialName, p.TradeName, p.RegistrationNumber, p.EconomicCode,
           p.IdentifierNumber, p.IdentifierDate, p.Description,
           p.DepartmentID, p.PartyTypeID, p.ActivityID, p.IsActive,
           p.Date_InsertFirst, p.UserID_InsertFirst, p.Date_LastUpdate, p.UserID_LastUpdate,
           CASE WHEN p.PartyNature = N'INDIVIDUAL'
                THEN LTRIM(RTRIM(ISNULL(p.FirstName, N'') + N' ' + ISNULL(p.LastName, N'')))
                ELSE ISNULL(p.OfficialName, N'') END AS DisplayName,
           t.DisplayName AS TitleName,
           d.DisplayName AS DepartmentName,
           pt.DisplayName AS PartyTypeName,
           a.DisplayName AS ActivityName,
           (SELECT COUNT(*) FROM dbo.CrmBrands b WHERE b.PartyID = p.PartyID AND b.IsActive = 1) AS ActiveBrandCount,
           (SELECT COUNT(*) FROM dbo.CrmAddresses ad WHERE ad.PartyID = p.PartyID AND ad.IsActive = 1) AS ActiveAddressCount,
           (SELECT COUNT(*) FROM dbo.CrmContacts c WHERE c.PartyID = p.PartyID AND c.IsActive = 1) AS ActiveContactCount
    FROM dbo.CrmParties p
    LEFT JOIN dbo.CrmTitles t ON t.TitleID = p.TitleID
    LEFT JOIN dbo.CrmDepartments d ON d.DepartmentID = p.DepartmentID
    LEFT JOIN dbo.CrmPartyTypes pt ON pt.PartyTypeID = p.PartyTypeID
    LEFT JOIN dbo.CrmActivities a ON a.ActivityID = p.ActivityID
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR p.FirstName LIKE N'%' + @SearchText + N'%'
           OR p.LastName LIKE N'%' + @SearchText + N'%'
           OR p.OfficialName LIKE N'%' + @SearchText + N'%'
           OR p.TradeName LIKE N'%' + @SearchText + N'%'
           OR p.IdentifierNumber LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR p.IsActive = @IsActive)
      AND (@PartyNature IS NULL OR p.PartyNature = @PartyNature)
      AND (@DepartmentID IS NULL OR p.DepartmentID = @DepartmentID)
      AND (@PartyTypeID IS NULL OR p.PartyTypeID = @PartyTypeID)
    ORDER BY p.Date_InsertFirst DESC;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetParty
CREATE PROCEDURE dbo.sp_Crm_GetParty
    @PartyID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT p.PartyID, p.PartyNature, p.TitleID, p.FirstName, p.LastName,
           p.OfficialName, p.TradeName, p.RegistrationNumber, p.EconomicCode,
           p.IdentifierNumber, p.IdentifierDate, p.Description,
           p.DepartmentID, p.PartyTypeID, p.ActivityID, p.IsActive, p.RowGuid,
           p.Date_InsertFirst, p.UserID_InsertFirst, p.Date_LastUpdate, p.UserID_LastUpdate,
           CASE WHEN p.PartyNature = N'INDIVIDUAL'
                THEN LTRIM(RTRIM(ISNULL(p.FirstName, N'') + N' ' + ISNULL(p.LastName, N'')))
                ELSE ISNULL(p.OfficialName, N'') END AS DisplayName,
           t.DisplayName AS TitleName,
           d.DisplayName AS DepartmentName,
           pt.DisplayName AS PartyTypeName,
           a.DisplayName AS ActivityName,
           cu.FirstName + N' ' + cu.LastName AS CreatedByName,
           mu.FirstName + N' ' + mu.LastName AS ModifiedByName
    FROM dbo.CrmParties p
    LEFT JOIN dbo.CrmTitles t ON t.TitleID = p.TitleID
    LEFT JOIN dbo.CrmDepartments d ON d.DepartmentID = p.DepartmentID
    LEFT JOIN dbo.CrmPartyTypes pt ON pt.PartyTypeID = p.PartyTypeID
    LEFT JOIN dbo.CrmActivities a ON a.ActivityID = p.ActivityID
    LEFT JOIN dbo.Users cu ON cu.UserID = p.UserID_InsertFirst
    LEFT JOIN dbo.Users mu ON mu.UserID = p.UserID_LastUpdate
    WHERE p.PartyID = @PartyID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SaveParty
CREATE PROCEDURE dbo.sp_Crm_SaveParty
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
    @PartyTypeID        INT           = NULL,
    @ActivityID         INT           = NULL,
    @UserID             INT
AS
BEGIN
    SET NOCOUNT ON;

    IF @PartyNature NOT IN (N'INDIVIDUAL', N'LEGAL')
    BEGIN SELECT 0 AS Success, N'ماهیتِ طرف‌حساب نامعتبر است.' AS Message; RETURN; END

    IF NULLIF(LTRIM(RTRIM(@IdentifierNumber)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'شناسه الزامی است.' AS Message; RETURN; END

    IF @PartyNature = N'INDIVIDUAL' AND (NULLIF(LTRIM(RTRIM(@FirstName)), N'') IS NULL OR NULLIF(LTRIM(RTRIM(@LastName)), N'') IS NULL)
    BEGIN SELECT 0 AS Success, N'نام و نامِ خانوادگی برایِ شخصِ حقیقی الزامی است.' AS Message; RETURN; END

    IF @PartyNature = N'LEGAL' AND NULLIF(LTRIM(RTRIM(@OfficialName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ رسمی برایِ شخصِ حقوقی الزامی است.' AS Message; RETURN; END

    IF @ActivityID IS NOT NULL AND @PartyTypeID IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM dbo.CrmActivities WHERE ActivityID = @ActivityID AND PartyTypeID = @PartyTypeID)
    BEGIN SELECT 0 AS Success, N'فعالیتِ انتخاب‌شده متعلق به نوعِ انتخاب‌شده نیست.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.CrmParties
               WHERE PartyNature = @PartyNature AND IdentifierNumber = @IdentifierNumber
                 AND (@PartyID IS NULL OR PartyID <> @PartyID))
    BEGIN SELECT 0 AS Success, N'طرف‌حسابی با همین شناسه از قبل ثبت شده است.' AS Message; RETURN; END

    IF @PartyID IS NULL
    BEGIN
        INSERT INTO dbo.CrmParties (
            PartyNature, TitleID, FirstName, LastName, OfficialName, TradeName,
            RegistrationNumber, EconomicCode, IdentifierNumber, IdentifierDate,
            Description, DepartmentID, PartyTypeID, ActivityID, IsActive,
            Date_InsertFirst, UserID_InsertFirst
        )
        VALUES (
            @PartyNature, @TitleID, @FirstName, @LastName, @OfficialName, @TradeName,
            @RegistrationNumber, @EconomicCode, @IdentifierNumber, @IdentifierDate,
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
        SET PartyNature = @PartyNature, TitleID = @TitleID, FirstName = @FirstName, LastName = @LastName,
            OfficialName = @OfficialName, TradeName = @TradeName, RegistrationNumber = @RegistrationNumber,
            EconomicCode = @EconomicCode, IdentifierNumber = @IdentifierNumber, IdentifierDate = @IdentifierDate,
            Description = @Description, DepartmentID = @DepartmentID, PartyTypeID = @PartyTypeID, ActivityID = @ActivityID,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE PartyID = @PartyID;

        SELECT 1 AS Success, N'طرف‌حساب ویرایش شد.' AS Message, @PartyID AS PartyID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_TogglePartyActive
CREATE PROCEDURE dbo.sp_Crm_TogglePartyActive
    @PartyID INT,
    @UserID  INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmParties WHERE PartyID = @PartyID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'طرف‌حساب یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmParties
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE PartyID = @PartyID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'طرف‌حساب غیرفعال شد.' ELSE N'طرف‌حساب فعال شد.' END AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetBrands
/* ===================== CrmBrands ===================== */
CREATE PROCEDURE dbo.sp_Crm_GetBrands
    @PartyID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT BrandID, PartyID, Name, Description, IsActive,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate
    FROM dbo.CrmBrands
    WHERE PartyID = @PartyID
    ORDER BY BrandID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SaveBrand
CREATE PROCEDURE dbo.sp_Crm_SaveBrand
    @BrandID     INT           = NULL,
    @PartyID     INT,
    @Name        NVARCHAR(200),
    @Description NVARCHAR(MAX) = NULL,
    @UserID      INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حسابِ مربوطه یافت نشد.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@Name)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ برند الزامی است.' AS Message; RETURN; END

    IF @BrandID IS NULL
    BEGIN
        INSERT INTO dbo.CrmBrands (PartyID, Name, Description, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@PartyID, @Name, @Description, 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'برند ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS BrandID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmBrands WHERE BrandID = @BrandID)
        BEGIN SELECT 0 AS Success, N'برند یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmBrands
        SET Name = @Name, Description = @Description,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE BrandID = @BrandID;

        SELECT 1 AS Success, N'برند ویرایش شد.' AS Message, @BrandID AS BrandID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_ToggleBrandActive
CREATE PROCEDURE dbo.sp_Crm_ToggleBrandActive
    @BrandID INT,
    @UserID  INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmBrands WHERE BrandID = @BrandID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'برند یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmBrands
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE BrandID = @BrandID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'برند غیرفعال شد.' ELSE N'برند فعال شد.' END AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetAddresses
/* ===================== CrmAddresses ===================== */
CREATE PROCEDURE dbo.sp_Crm_GetAddresses
    @PartyID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT ad.AddressID, ad.PartyID, ad.AddressTitleID, ad.ProvinceID, ad.CityID, ad.CountyID,
           ad.MunicipalZoneID, ad.PostalCode, ad.AddressText, ad.PlateNumber, ad.Unit, ad.IsActive,
           ad.Date_InsertFirst, ad.UserID_InsertFirst, ad.Date_LastUpdate, ad.UserID_LastUpdate,
           at.DisplayName AS AddressTitleName, pr.DisplayName AS ProvinceName,
           ci.DisplayName AS CityName, co.DisplayName AS CountyName, mz.DisplayName AS MunicipalZoneName
    FROM dbo.CrmAddresses ad
    JOIN dbo.CrmAddressTitles at ON at.AddressTitleID = ad.AddressTitleID
    JOIN dbo.CrmProvinces pr ON pr.ProvinceID = ad.ProvinceID
    JOIN dbo.CrmCities ci ON ci.CityID = ad.CityID
    JOIN dbo.CrmCounties co ON co.CountyID = ad.CountyID
    LEFT JOIN dbo.CrmMunicipalZones mz ON mz.MunicipalZoneID = ad.MunicipalZoneID
    WHERE ad.PartyID = @PartyID
    ORDER BY ad.AddressID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SaveAddress
CREATE PROCEDURE dbo.sp_Crm_SaveAddress
    @AddressID       INT           = NULL,
    @PartyID         INT,
    @AddressTitleID  INT,
    @ProvinceID      INT,
    @CityID          INT,
    @CountyID        INT,
    @MunicipalZoneID INT           = NULL,
    @PostalCode      NVARCHAR(10)  = NULL,
    @AddressText     NVARCHAR(500),
    @PlateNumber     NVARCHAR(20)  = NULL,
    @Unit            NVARCHAR(20)  = NULL,
    @UserID          INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حسابِ مربوطه یافت نشد.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.CrmAddressTitles WHERE AddressTitleID = @AddressTitleID)
    BEGIN SELECT 0 AS Success, N'عنوانِ آدرس نامعتبر است.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.CrmCities WHERE CityID = @CityID AND ProvinceID = @ProvinceID)
    BEGIN SELECT 0 AS Success, N'شهرِ انتخاب‌شده متعلق به استانِ انتخاب‌شده نیست.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.CrmCounties WHERE CountyID = @CountyID AND CityID = @CityID)
    BEGIN SELECT 0 AS Success, N'شهرستانِ انتخاب‌شده متعلق به شهرِ انتخاب‌شده نیست.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@AddressText)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'متنِ آدرس الزامی است.' AS Message; RETURN; END

    IF @AddressID IS NULL
    BEGIN
        INSERT INTO dbo.CrmAddresses (
            PartyID, AddressTitleID, ProvinceID, CityID, CountyID, MunicipalZoneID,
            PostalCode, AddressText, PlateNumber, Unit, IsActive, Date_InsertFirst, UserID_InsertFirst
        )
        VALUES (
            @PartyID, @AddressTitleID, @ProvinceID, @CityID, @CountyID, @MunicipalZoneID,
            @PostalCode, @AddressText, @PlateNumber, @Unit, 1, SYSDATETIME(), @UserID
        );

        SELECT 1 AS Success, N'آدرس ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS AddressID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmAddresses WHERE AddressID = @AddressID)
        BEGIN SELECT 0 AS Success, N'آدرس یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmAddresses
        SET AddressTitleID = @AddressTitleID, ProvinceID = @ProvinceID, CityID = @CityID, CountyID = @CountyID,
            MunicipalZoneID = @MunicipalZoneID, PostalCode = @PostalCode, AddressText = @AddressText,
            PlateNumber = @PlateNumber, Unit = @Unit,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE AddressID = @AddressID;

        SELECT 1 AS Success, N'آدرس ویرایش شد.' AS Message, @AddressID AS AddressID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_ToggleAddressActive
CREATE PROCEDURE dbo.sp_Crm_ToggleAddressActive
    @AddressID INT,
    @UserID    INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmAddresses WHERE AddressID = @AddressID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'آدرس یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmAddresses
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE AddressID = @AddressID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'آدرس غیرفعال شد.' ELSE N'آدرس فعال شد.' END AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetContacts
/* ===================== CrmContacts ===================== */
CREATE PROCEDURE dbo.sp_Crm_GetContacts
    @PartyID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT c.ContactID, c.PartyID, c.ContactTypeID, c.ContactValue, c.Extension, c.Description,
           c.IsPrimary, c.IsActive,
           c.Date_InsertFirst, c.UserID_InsertFirst, c.Date_LastUpdate, c.UserID_LastUpdate,
           ct.DisplayName AS ContactTypeName
    FROM dbo.CrmContacts c
    JOIN dbo.CrmContactTypes ct ON ct.ContactTypeID = c.ContactTypeID
    WHERE c.PartyID = @PartyID
    ORDER BY c.IsPrimary DESC, c.ContactID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SaveContact
CREATE PROCEDURE dbo.sp_Crm_SaveContact
    @ContactID     INT           = NULL,
    @PartyID       INT,
    @ContactTypeID INT,
    @ContactValue  NVARCHAR(200),
    @Extension     NVARCHAR(20)  = NULL,
    @Description   NVARCHAR(200) = NULL,
    @IsPrimary     BIT           = 0,
    @UserID        INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حسابِ مربوطه یافت نشد.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.CrmContactTypes WHERE ContactTypeID = @ContactTypeID)
    BEGIN SELECT 0 AS Success, N'نوعِ تماس نامعتبر است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@ContactValue)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'مقدارِ تماس الزامی است.' AS Message; RETURN; END

    BEGIN TRY
        BEGIN TRAN;

        IF @ContactID IS NULL
        BEGIN
            INSERT INTO dbo.CrmContacts (PartyID, ContactTypeID, ContactValue, Extension, Description, IsPrimary, IsActive, Date_InsertFirst, UserID_InsertFirst)
            VALUES (@PartyID, @ContactTypeID, @ContactValue, @Extension, @Description, ISNULL(@IsPrimary, 0), 1, SYSDATETIME(), @UserID);

            SET @ContactID = CAST(SCOPE_IDENTITY() AS INT);
        END
        ELSE
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM dbo.CrmContacts WHERE ContactID = @ContactID)
            BEGIN
                ROLLBACK TRAN;
                SELECT 0 AS Success, N'اطلاعاتِ تماس یافت نشد.' AS Message;
                RETURN;
            END

            UPDATE dbo.CrmContacts
            SET ContactTypeID = @ContactTypeID, ContactValue = @ContactValue, Extension = @Extension,
                Description = @Description, IsPrimary = ISNULL(@IsPrimary, 0),
                Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
            WHERE ContactID = @ContactID;
        END

        -- فقط یک تماسِ اصلی در هر طرف‌حساب
        IF ISNULL(@IsPrimary, 0) = 1
            UPDATE dbo.CrmContacts SET IsPrimary = 0 WHERE PartyID = @PartyID AND ContactID <> @ContactID;

        COMMIT TRAN;
        SELECT 1 AS Success, N'اطلاعاتِ تماس ذخیره شد.' AS Message, @ContactID AS ContactID;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRAN;
        SELECT 0 AS Success, N'خطا در ذخیره: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_ToggleContactActive
CREATE PROCEDURE dbo.sp_Crm_ToggleContactActive
    @ContactID INT,
    @UserID    INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmContacts WHERE ContactID = @ContactID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'اطلاعاتِ تماس یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmContacts
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE ContactID = @ContactID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'اطلاعاتِ تماس غیرفعال شد.' ELSE N'اطلاعاتِ تماس فعال شد.' END AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetPersons
/* ===================== CrmPersons ===================== */
CREATE PROCEDURE dbo.sp_Crm_GetPersons
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT PersonID, FirstName, LastName, IsActive,
           (FirstName + N' ' + LastName) AS DisplayName,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate
    FROM dbo.CrmPersons
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR FirstName LIKE N'%' + @SearchText + N'%'
           OR LastName LIKE N'%' + @SearchText + N'%'
           OR (FirstName + N' ' + LastName) LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR IsActive = @IsActive)
    ORDER BY PersonID DESC;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SavePerson
CREATE PROCEDURE dbo.sp_Crm_SavePerson
    @PersonID  INT           = NULL,
    @FirstName NVARCHAR(100),
    @LastName  NVARCHAR(100),
    @UserID    INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@FirstName)), N'') IS NULL OR NULLIF(LTRIM(RTRIM(@LastName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نام و نامِ خانوادگی الزامی است.' AS Message; RETURN; END

    IF @PersonID IS NULL
    BEGIN
        INSERT INTO dbo.CrmPersons (FirstName, LastName, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@FirstName, @LastName, 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'شخص ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS PersonID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmPersons WHERE PersonID = @PersonID)
        BEGIN SELECT 0 AS Success, N'شخص یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmPersons
        SET FirstName = @FirstName, LastName = @LastName,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE PersonID = @PersonID;

        SELECT 1 AS Success, N'شخص ویرایش شد.' AS Message, @PersonID AS PersonID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_TogglePersonActive
CREATE PROCEDURE dbo.sp_Crm_TogglePersonActive
    @PersonID INT,
    @UserID   INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmPersons WHERE PersonID = @PersonID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'شخص یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmPersons
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE PersonID = @PersonID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'شخص غیرفعال شد.' ELSE N'شخص فعال شد.' END AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetPartyRelations
/* ===================== CrmPartyPersonRelations ===================== */
CREATE PROCEDURE dbo.sp_Crm_GetPartyRelations
    @PartyID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT r.RelationID, r.PartyID, r.PersonID, r.PositionID, r.IsPrimaryContact, r.IsActive,
           r.Date_InsertFirst, r.UserID_InsertFirst, r.Date_LastUpdate, r.UserID_LastUpdate,
           per.FirstName AS PersonFirstName, per.LastName AS PersonLastName,
           (per.FirstName + N' ' + per.LastName) AS PersonName,
           pos.DisplayName AS PositionName,
           (
               SELECT STRING_AGG(cr.DisplayName, N'، ') WITHIN GROUP (ORDER BY cr.SortOrder)
               FROM dbo.CrmPartyPersonRelationRoles rr
               JOIN dbo.CrmContactRoles cr ON cr.ContactRoleID = rr.RoleID
               WHERE rr.RelationID = r.RelationID
           ) AS RoleNames
    FROM dbo.CrmPartyPersonRelations r
    JOIN dbo.CrmPersons per ON per.PersonID = r.PersonID
    LEFT JOIN dbo.CrmPositions pos ON pos.PositionID = r.PositionID
    WHERE r.PartyID = @PartyID
    ORDER BY r.IsPrimaryContact DESC, r.RelationID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetRelationRoleIds
CREATE PROCEDURE dbo.sp_Crm_GetRelationRoleIds
    @RelationID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT RoleID FROM dbo.CrmPartyPersonRelationRoles WHERE RelationID = @RelationID ORDER BY RoleID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SaveRelation
CREATE PROCEDURE dbo.sp_Crm_SaveRelation
    @RelationID       INT = NULL,
    @PartyID          INT,
    @PersonID         INT,
    @PositionID       INT = NULL,
    @IsPrimaryContact BIT = 0,
    @UserID           INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حسابِ مربوطه یافت نشد.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.CrmPersons WHERE PersonID = @PersonID)
    BEGIN SELECT 0 AS Success, N'شخصِ انتخاب‌شده یافت نشد.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.CrmPartyPersonRelations
               WHERE PartyID = @PartyID AND PersonID = @PersonID AND (@RelationID IS NULL OR RelationID <> @RelationID))
    BEGIN SELECT 0 AS Success, N'این شخص از قبل به‌عنوانِ مخاطبِ همین طرف‌حساب ثبت شده است.' AS Message; RETURN; END

    BEGIN TRY
        BEGIN TRAN;

        IF @RelationID IS NULL
        BEGIN
            INSERT INTO dbo.CrmPartyPersonRelations (PartyID, PersonID, PositionID, IsPrimaryContact, IsActive, Date_InsertFirst, UserID_InsertFirst)
            VALUES (@PartyID, @PersonID, @PositionID, ISNULL(@IsPrimaryContact, 0), 1, SYSDATETIME(), @UserID);

            SET @RelationID = CAST(SCOPE_IDENTITY() AS INT);
        END
        ELSE
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM dbo.CrmPartyPersonRelations WHERE RelationID = @RelationID)
            BEGIN
                ROLLBACK TRAN;
                SELECT 0 AS Success, N'رابطه یافت نشد.' AS Message;
                RETURN;
            END

            UPDATE dbo.CrmPartyPersonRelations
            SET PersonID = @PersonID, PositionID = @PositionID, IsPrimaryContact = ISNULL(@IsPrimaryContact, 0),
                Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
            WHERE RelationID = @RelationID;
        END

        -- فقط یک مخاطبِ اصلی در هر طرف‌حساب
        IF ISNULL(@IsPrimaryContact, 0) = 1
            UPDATE dbo.CrmPartyPersonRelations SET IsPrimaryContact = 0 WHERE PartyID = @PartyID AND RelationID <> @RelationID;

        COMMIT TRAN;
        SELECT 1 AS Success, N'رابطه ذخیره شد.' AS Message, @RelationID AS RelationID;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRAN;
        SELECT 0 AS Success, N'خطا در ذخیره: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_ToggleRelationActive
CREATE PROCEDURE dbo.sp_Crm_ToggleRelationActive
    @RelationID INT,
    @UserID     INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmPartyPersonRelations WHERE RelationID = @RelationID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'رابطه یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmPartyPersonRelations
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE RelationID = @RelationID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'وضعیتِ رابطه غیرفعال شد.' ELSE N'وضعیتِ رابطه فعال شد.' END AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SaveRelationRoles
CREATE PROCEDURE dbo.sp_Crm_SaveRelationRoles
    @RelationID  INT,
    @RoleIDsCsv  NVARCHAR(MAX) = NULL, -- e.g. '1,2,3' یا NULL/خالی برایِ پاک‌کردنِ همه
    @UserID      INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmPartyPersonRelations WHERE RelationID = @RelationID)
    BEGIN SELECT 0 AS Success, N'رابطه یافت نشد.' AS Message; RETURN; END

    DECLARE @Roles TABLE (RoleID INT);
    INSERT INTO @Roles (RoleID)
    SELECT DISTINCT CAST(value AS INT) FROM STRING_SPLIT(ISNULL(@RoleIDsCsv, N''), ',') WHERE NULLIF(LTRIM(RTRIM(value)), N'') IS NOT NULL;

    IF EXISTS (SELECT 1 FROM @Roles r WHERE NOT EXISTS (SELECT 1 FROM dbo.CrmContactRoles cr WHERE cr.ContactRoleID = r.RoleID))
    BEGIN SELECT 0 AS Success, N'یک یا چند نقشِ انتخاب‌شده نامعتبر است.' AS Message; RETURN; END

    BEGIN TRY
        BEGIN TRAN;

        DELETE FROM dbo.CrmPartyPersonRelationRoles
        WHERE RelationID = @RelationID AND RoleID NOT IN (SELECT RoleID FROM @Roles);

        INSERT INTO dbo.CrmPartyPersonRelationRoles (RelationID, RoleID, Date_InsertFirst, UserID_InsertFirst)
        SELECT @RelationID, r.RoleID, SYSDATETIME(), @UserID
        FROM @Roles r
        WHERE NOT EXISTS (
            SELECT 1 FROM dbo.CrmPartyPersonRelationRoles rr WHERE rr.RelationID = @RelationID AND rr.RoleID = r.RoleID
        );

        COMMIT TRAN;
        SELECT 1 AS Success, N'نقش‌ها به‌روزرسانی شد.' AS Message;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRAN;
        SELECT 0 AS Success, N'خطا در ذخیرهٔ نقش‌ها: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

/* [DATA SEED] — Idempotent؛ DML است، توسطِ Triggerِ DDL ردیابی نمی‌شود — هم‌الگو با patch 038 */

IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE PermissionCode = N'CRM_VIEW')
    INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive)
    VALUES (N'CRM_VIEW', N'مشاهدهٔ CRM', N'CRM', N'مشاهدهٔ فهرست و جزئیاتِ طرف‌حساب‌ها', 2, 1);

IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE PermissionCode = N'CRM_MANAGE_PARTIES')
    INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive)
    VALUES (N'CRM_MANAGE_PARTIES', N'مدیریتِ طرف‌حساب‌ها', N'CRM', N'ایجاد/ویرایش/فعال‌سازیِ طرف‌حساب، برند، آدرس، اطلاعاتِ تماس و مخاطبین', 3, 1);
GO

IF EXISTS (SELECT 1 FROM dbo.Roles WHERE RoleID = 1)
BEGIN
    INSERT INTO dbo.RolePermissions (RoleID, PermissionID, CanAccess, IsActive)
    SELECT 1, p.PermissionID, 1, 1
    FROM dbo.Permissions p
    WHERE p.PermissionCode IN (N'CRM_VIEW', N'CRM_MANAGE_PARTIES')
      AND NOT EXISTS (SELECT 1 FROM dbo.RolePermissions rp WHERE rp.RoleID = 1 AND rp.PermissionID = p.PermissionID);
END
GO

-- منویِ «طرف‌حساب‌ها» زیرِ پوشهٔ CRM (از پچ ۰۳۸)
IF NOT EXISTS (SELECT 1 FROM dbo.Menu WHERE Url = N'/crm/parties')
BEGIN
    DECLARE @CrmFolderID2 INT;
    SELECT @CrmFolderID2 = MenuID FROM dbo.Menu WHERE MenuTitle = N'CRM' AND ParentID IS NULL;
    IF @CrmFolderID2 IS NOT NULL
        EXEC dbo.sp_InsertMenu @ParentID = @CrmFolderID2, @MenuTitle = N'طرف‌حساب‌ها', @MenuKind = N'PAGE', @Url = N'/crm/parties', @SortOrder = 0;

    EXEC dbo.sp_GrantAllMenusToRole @RoleID = 1;
END
GO

