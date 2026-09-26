/* ==========================================================================
   پچ خودکار شماره: 061 | نام: crm_address_latitude_longitude
   تاریخ: 2026-09-25 19:37:25 | شامل 5 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: CrmAddresses
ALTER TABLE dbo.CrmAddresses ADD Latitude DECIMAL(9,6) NULL, Longitude DECIMAL(9,6) NULL
GO

-- [ALTER_TABLE] روی TABLE: CrmAddresses
ALTER TABLE dbo.CrmAddresses ADD CONSTRAINT CK_CrmAddresses_GeoPair CHECK ((Latitude IS NULL AND Longitude IS NULL) OR (Latitude IS NOT NULL AND Longitude IS NOT NULL))
GO

-- [ALTER_TABLE] روی TABLE: CrmAddresses
ALTER TABLE dbo.CrmAddresses ADD CONSTRAINT CK_CrmAddresses_GeoRange CHECK ((Latitude IS NULL OR (Latitude >= -90 AND Latitude <= 90)) AND (Longitude IS NULL OR (Longitude >= -180 AND Longitude <= 180)))
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetAddresses
ALTER PROCEDURE dbo.sp_Crm_GetAddresses
    @PartyID  INT = NULL,
    @PersonID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT ad.AddressID, ad.PartyID, ad.PersonID, ad.AddressTitleID, ad.ProvinceID, ad.CountyID, ad.CityID,
           ad.NeighborhoodID, ad.MunicipalZoneID, ad.PostalCode, ad.AddressText, ad.PlateNumber, ad.Unit,
           ad.Latitude, ad.Longitude, ad.IsActive,
           ad.Date_InsertFirst, ad.UserID_InsertFirst, ad.Date_LastUpdate, ad.UserID_LastUpdate,
           at.DisplayName AS AddressTitleName, pr.DisplayName AS ProvinceName,
           co.DisplayName AS CountyName, ci.DisplayName AS CityName,
           nb.DisplayName AS NeighborhoodName, mz.DisplayName AS MunicipalZoneName
    FROM dbo.CrmAddresses ad
    JOIN dbo.CrmAddressTitles at ON at.AddressTitleID = ad.AddressTitleID
    JOIN dbo.CrmProvinces pr ON pr.ProvinceID = ad.ProvinceID
    JOIN dbo.CrmCounties co ON co.CountyID = ad.CountyID
    JOIN dbo.CrmCities ci ON ci.CityID = ad.CityID
    LEFT JOIN dbo.CrmNeighborhoods nb ON nb.NeighborhoodID = ad.NeighborhoodID
    LEFT JOIN dbo.CrmMunicipalZones mz ON mz.MunicipalZoneID = ad.MunicipalZoneID
    WHERE (@PartyID IS NOT NULL AND ad.PartyID = @PartyID)
       OR (@PersonID IS NOT NULL AND ad.PersonID = @PersonID)
    ORDER BY ad.AddressID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveAddress
ALTER PROCEDURE dbo.sp_Crm_SaveAddress
    @AddressID       INT           = NULL,
    @PartyID         INT           = NULL,
    @PersonID        INT           = NULL,
    @AddressTitleID  INT,
    @ProvinceID      INT,
    @CountyID        INT,
    @CityID          INT,
    @NeighborhoodID  INT           = NULL,
    @MunicipalZoneID INT           = NULL,
    @PostalCode      NVARCHAR(10)  = NULL,
    @AddressText     NVARCHAR(500),
    @PlateNumber     NVARCHAR(20)  = NULL,
    @Unit            NVARCHAR(20)  = NULL,
    @UserID          INT,
    @Latitude        DECIMAL(9,6)  = NULL,
    @Longitude       DECIMAL(9,6)  = NULL
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
    IF NOT EXISTS (SELECT 1 FROM dbo.CrmCounties WHERE CountyID = @CountyID AND ProvinceID = @ProvinceID)
    BEGIN SELECT 0 AS Success, N'شهرستانِ انتخاب‌شده متعلق به استانِ انتخاب‌شده نیست.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.CrmCities WHERE CityID = @CityID AND CountyID = @CountyID)
    BEGIN SELECT 0 AS Success, N'شهرِ انتخاب‌شده متعلق به شهرستانِ انتخاب‌شده نیست.' AS Message; RETURN; END
    IF @NeighborhoodID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmNeighborhoods WHERE NeighborhoodID = @NeighborhoodID AND CityID = @CityID)
    BEGIN SELECT 0 AS Success, N'محلهٔ انتخاب‌شده متعلق به شهرِ انتخاب‌شده نیست.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@AddressText)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'متنِ آدرس الزامی است.' AS Message; RETURN; END
    IF (@Latitude IS NULL AND @Longitude IS NOT NULL) OR (@Latitude IS NOT NULL AND @Longitude IS NULL)
    BEGIN SELECT 0 AS Success, N'عرض و طولِ جغرافیایی باید با هم ثبت یا با هم حذف شوند.' AS Message; RETURN; END
    IF @Latitude IS NOT NULL AND (@Latitude < -90 OR @Latitude > 90 OR @Longitude < -180 OR @Longitude > 180)
    BEGIN SELECT 0 AS Success, N'مختصاتِ جغرافیایی خارج از محدودهٔ معتبر است.' AS Message; RETURN; END

    IF @AddressID IS NULL
    BEGIN
        INSERT INTO dbo.CrmAddresses (
            PartyID, PersonID, AddressTitleID, ProvinceID, CountyID, CityID, NeighborhoodID, MunicipalZoneID,
            PostalCode, AddressText, PlateNumber, Unit, Latitude, Longitude, IsActive, Date_InsertFirst, UserID_InsertFirst
        )
        VALUES (
            @PartyID, @PersonID, @AddressTitleID, @ProvinceID, @CountyID, @CityID, @NeighborhoodID, @MunicipalZoneID,
            @PostalCode, @AddressText, @PlateNumber, @Unit, @Latitude, @Longitude, 1, SYSDATETIME(), @UserID
        );

        SELECT 1 AS Success, N'آدرس ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS AddressID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmAddresses WHERE AddressID = @AddressID)
        BEGIN SELECT 0 AS Success, N'آدرس یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmAddresses
        SET AddressTitleID = @AddressTitleID, ProvinceID = @ProvinceID, CountyID = @CountyID, CityID = @CityID,
            NeighborhoodID = @NeighborhoodID, MunicipalZoneID = @MunicipalZoneID, PostalCode = @PostalCode,
            AddressText = @AddressText, PlateNumber = @PlateNumber, Unit = @Unit,
            Latitude = @Latitude, Longitude = @Longitude,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE AddressID = @AddressID;

        SELECT 1 AS Success, N'آدرس ویرایش شد.' AS Message, @AddressID AS AddressID;
    END
END
GO

