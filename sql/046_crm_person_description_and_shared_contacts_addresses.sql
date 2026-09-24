/* ==========================================================================
   پچ خودکار شماره: 046 | نام: crm_person_description_and_shared_contacts_addresses
   تاریخ: 2026-09-24 14:02:13 | شامل 21 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: CrmPersons
ALTER TABLE dbo.CrmPersons ADD Description NVARCHAR(MAX) NULL
GO

-- [ALTER_TABLE] روی TABLE: CrmContacts
ALTER TABLE dbo.CrmContacts DROP CONSTRAINT FK_CrmContacts_Party
GO

-- [ALTER_TABLE] روی TABLE: CrmContacts
ALTER TABLE dbo.CrmContacts ALTER COLUMN PartyID INT NULL
GO

-- [ALTER_TABLE] روی TABLE: CrmContacts
ALTER TABLE dbo.CrmContacts ADD PersonID INT NULL
GO

-- [ALTER_TABLE] روی TABLE: CrmContacts
ALTER TABLE dbo.CrmContacts ADD CONSTRAINT FK_CrmContacts_Party FOREIGN KEY (PartyID) REFERENCES dbo.CrmParties(PartyID)
GO

-- [ALTER_TABLE] روی TABLE: CrmContacts
ALTER TABLE dbo.CrmContacts ADD CONSTRAINT FK_CrmContacts_Person FOREIGN KEY (PersonID) REFERENCES dbo.CrmPersons(PersonID)
GO

-- [ALTER_TABLE] روی TABLE: CrmContacts
ALTER TABLE dbo.CrmContacts ADD CONSTRAINT CK_CrmContacts_Owner CHECK (
    (PartyID IS NOT NULL AND PersonID IS NULL) OR (PartyID IS NULL AND PersonID IS NOT NULL)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmContacts_PersonID
CREATE INDEX IX_CrmContacts_PersonID ON dbo.CrmContacts (PersonID)
GO

-- [ALTER_TABLE] روی TABLE: CrmAddresses
ALTER TABLE dbo.CrmAddresses DROP CONSTRAINT FK_CrmAddresses_Party
GO

-- [ALTER_TABLE] روی TABLE: CrmAddresses
ALTER TABLE dbo.CrmAddresses ALTER COLUMN PartyID INT NULL
GO

-- [ALTER_TABLE] روی TABLE: CrmAddresses
ALTER TABLE dbo.CrmAddresses ADD PersonID INT NULL
GO

-- [ALTER_TABLE] روی TABLE: CrmAddresses
ALTER TABLE dbo.CrmAddresses ADD CONSTRAINT FK_CrmAddresses_Party FOREIGN KEY (PartyID) REFERENCES dbo.CrmParties(PartyID)
GO

-- [ALTER_TABLE] روی TABLE: CrmAddresses
ALTER TABLE dbo.CrmAddresses ADD CONSTRAINT FK_CrmAddresses_Person FOREIGN KEY (PersonID) REFERENCES dbo.CrmPersons(PersonID)
GO

-- [ALTER_TABLE] روی TABLE: CrmAddresses
ALTER TABLE dbo.CrmAddresses ADD CONSTRAINT CK_CrmAddresses_Owner CHECK (
    (PartyID IS NOT NULL AND PersonID IS NULL) OR (PartyID IS NULL AND PersonID IS NOT NULL)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmAddresses_PersonID
CREATE INDEX IX_CrmAddresses_PersonID ON dbo.CrmAddresses (PersonID)
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SavePerson
/* ===================== sp_Crm_SavePerson (+ Description) ===================== */

ALTER PROCEDURE dbo.sp_Crm_SavePerson
    @PersonID         INT           = NULL,
    @FirstName        NVARCHAR(100),
    @LastName         NVARCHAR(100),
    @TitleID          INT           = NULL,
    @IdentifierNumber NVARCHAR(20)  = NULL,
    @IdentifierDate   DATE          = NULL,
    @Description      NVARCHAR(MAX) = NULL,
    @UserID           INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@FirstName)), N'') IS NULL OR NULLIF(LTRIM(RTRIM(@LastName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نام و نامِ خانوادگی الزامی است.' AS Message; RETURN; END

    IF @PersonID IS NULL
    BEGIN
        INSERT INTO dbo.CrmPersons (FirstName, LastName, TitleID, IdentifierNumber, IdentifierDate, Description, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@FirstName, @LastName, @TitleID, @IdentifierNumber, @IdentifierDate, @Description, 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'شخص ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS PersonID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmPersons WHERE PersonID = @PersonID)
        BEGIN SELECT 0 AS Success, N'شخص یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmPersons
        SET FirstName = @FirstName, LastName = @LastName, TitleID = @TitleID,
            IdentifierNumber = @IdentifierNumber, IdentifierDate = @IdentifierDate, Description = @Description,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE PersonID = @PersonID;

        SELECT 1 AS Success, N'شخص ویرایش شد.' AS Message, @PersonID AS PersonID;
    END
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetPersons
/* ===================== sp_Crm_GetPersons (+ Description) ===================== */

ALTER PROCEDURE dbo.sp_Crm_GetPersons
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT per.PersonID, per.FirstName, per.LastName, per.TitleID, per.IdentifierNumber, per.IdentifierDate,
           per.Description, per.IsActive,
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

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetContacts
/* ===================== sp_Crm_GetContacts (Party OR Person owner) ===================== */

ALTER PROCEDURE dbo.sp_Crm_GetContacts
    @PartyID  INT = NULL,
    @PersonID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT c.ContactID, c.PartyID, c.PersonID, c.ContactTypeID, c.ContactValue, c.Extension, c.Description,
           c.IsPrimary, c.IsActive,
           c.Date_InsertFirst, c.UserID_InsertFirst, c.Date_LastUpdate, c.UserID_LastUpdate,
           ct.DisplayName AS ContactTypeName
    FROM dbo.CrmContacts c
    JOIN dbo.CrmContactTypes ct ON ct.ContactTypeID = c.ContactTypeID
    WHERE (@PartyID IS NOT NULL AND c.PartyID = @PartyID)
       OR (@PersonID IS NOT NULL AND c.PersonID = @PersonID)
    ORDER BY c.IsPrimary DESC, c.ContactID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveContact
/* ===================== sp_Crm_SaveContact (Party OR Person owner) ===================== */

ALTER PROCEDURE dbo.sp_Crm_SaveContact
    @ContactID     INT           = NULL,
    @PartyID       INT           = NULL,
    @PersonID      INT           = NULL,
    @ContactTypeID INT,
    @ContactValue  NVARCHAR(200),
    @Extension     NVARCHAR(20)  = NULL,
    @Description   NVARCHAR(200) = NULL,
    @IsPrimary     BIT           = 0,
    @UserID        INT
AS
BEGIN
    SET NOCOUNT ON;

    IF (@PartyID IS NULL AND @PersonID IS NULL) OR (@PartyID IS NOT NULL AND @PersonID IS NOT NULL)
    BEGIN SELECT 0 AS Success, N'دقیقاً یکی از طرف‌حساب یا مخاطب باید مشخص باشد.' AS Message; RETURN; END
    IF @PartyID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حساب انتخاب‌شده معتبر نیست.' AS Message; RETURN; END
    IF @PersonID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmPersons WHERE PersonID = @PersonID)
    BEGIN SELECT 0 AS Success, N'مخاطبِ انتخاب‌شده معتبر نیست.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.CrmContactTypes WHERE ContactTypeID = @ContactTypeID)
    BEGIN SELECT 0 AS Success, N'نوعِ تماس معتبر نیست.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@ContactValue)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'مقدارِ تماس الزامی است.' AS Message; RETURN; END

    BEGIN TRY
        BEGIN TRAN;

        IF @ContactID IS NULL
        BEGIN
            INSERT INTO dbo.CrmContacts (PartyID, PersonID, ContactTypeID, ContactValue, Extension, Description, IsPrimary, IsActive, Date_InsertFirst, UserID_InsertFirst)
            VALUES (@PartyID, @PersonID, @ContactTypeID, @ContactValue, @Extension, @Description, ISNULL(@IsPrimary, 0), 1, SYSDATETIME(), @UserID);

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

        -- تکِ اصلی بودن، به‌ازایِ همان مالک (طرف‌حساب یا مخاطب)
        IF ISNULL(@IsPrimary, 0) = 1
            UPDATE dbo.CrmContacts
            SET IsPrimary = 0
            WHERE ContactID <> @ContactID
              AND ((@PartyID IS NOT NULL AND PartyID = @PartyID) OR (@PersonID IS NOT NULL AND PersonID = @PersonID));

        COMMIT TRAN;
        SELECT 1 AS Success, N'اطلاعاتِ تماس ذخیره شد.' AS Message, @ContactID AS ContactID;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRAN;
        SELECT 0 AS Success, N'خطا در ذخیره: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetAddresses
/* ===================== sp_Crm_GetAddresses (Party OR Person owner) ===================== */

ALTER PROCEDURE dbo.sp_Crm_GetAddresses
    @PartyID  INT = NULL,
    @PersonID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT ad.AddressID, ad.PartyID, ad.PersonID, ad.AddressTitleID, ad.ProvinceID, ad.CityID, ad.CountyID,
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
    WHERE (@PartyID IS NOT NULL AND ad.PartyID = @PartyID)
       OR (@PersonID IS NOT NULL AND ad.PersonID = @PersonID)
    ORDER BY ad.AddressID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveAddress
/* ===================== sp_Crm_SaveAddress (Party OR Person owner) ===================== */

ALTER PROCEDURE dbo.sp_Crm_SaveAddress
    @AddressID       INT           = NULL,
    @PartyID         INT           = NULL,
    @PersonID        INT           = NULL,
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

    IF (@PartyID IS NULL AND @PersonID IS NULL) OR (@PartyID IS NOT NULL AND @PersonID IS NOT NULL)
    BEGIN SELECT 0 AS Success, N'دقیقاً یکی از طرف‌حساب یا مخاطب باید مشخص باشد.' AS Message; RETURN; END
    IF @PartyID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حساب انتخاب‌شده معتبر نیست.' AS Message; RETURN; END
    IF @PersonID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmPersons WHERE PersonID = @PersonID)
    BEGIN SELECT 0 AS Success, N'مخاطبِ انتخاب‌شده معتبر نیست.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.CrmAddressTitles WHERE AddressTitleID = @AddressTitleID)
    BEGIN SELECT 0 AS Success, N'عنوانِ آدرس معتبر نیست.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.CrmCities WHERE CityID = @CityID AND ProvinceID = @ProvinceID)
    BEGIN SELECT 0 AS Success, N'شهرِ انتخاب‌شده متعلق به استانِ انتخاب‌شده نیست.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.CrmCounties WHERE CountyID = @CountyID AND CityID = @CityID)
    BEGIN SELECT 0 AS Success, N'شهرستانِ انتخاب‌شده متعلق به شهرِ انتخاب‌شده نیست.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@AddressText)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'متنِ آدرس الزامی است.' AS Message; RETURN; END

    IF @AddressID IS NULL
    BEGIN
        INSERT INTO dbo.CrmAddresses (
            PartyID, PersonID, AddressTitleID, ProvinceID, CityID, CountyID, MunicipalZoneID,
            PostalCode, AddressText, PlateNumber, Unit, IsActive, Date_InsertFirst, UserID_InsertFirst
        )
        VALUES (
            @PartyID, @PersonID, @AddressTitleID, @ProvinceID, @CityID, @CountyID, @MunicipalZoneID,
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

