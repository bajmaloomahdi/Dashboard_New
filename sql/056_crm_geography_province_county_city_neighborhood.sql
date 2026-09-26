/* ==========================================================================
   پچ خودکار شماره: 056 | نام: crm_geography_province_county_city_neighborhood
   تاریخ: 2026-09-25 14:49:36 | شامل 61 دستور SQL
   ========================================================================== */

-- [RENAME] روی TABLE: CrmCities
EXEC sp_rename 'dbo.CrmCities', 'CrmGeoSwap_A'
GO

-- [RENAME] روی TABLE: CrmCounties
EXEC sp_rename 'dbo.CrmCounties', 'CrmGeoSwap_B'
GO

-- [RENAME] روی PRIMARY KEY CONSTRAINT: PK_CrmCities
EXEC sp_rename 'dbo.PK_CrmCities', 'PK_CrmGeoSwap_A', 'OBJECT'
GO

-- [RENAME] روی UNIQUE KEY CONSTRAINT: UQ_CrmCities_ProvinceID_Code
EXEC sp_rename 'dbo.UQ_CrmCities_ProvinceID_Code', 'UQ_CrmGeoSwap_A', 'OBJECT'
GO

-- [RENAME] روی FOREIGN KEY CONSTRAINT: FK_CrmCities_Province
EXEC sp_rename 'dbo.FK_CrmCities_Province', 'FK_CrmGeoSwap_A', 'OBJECT'
GO

-- [RENAME] روی DEFAULT: DF_CrmCities_SortOrder
EXEC sp_rename 'dbo.DF_CrmCities_SortOrder', 'DF_CrmGeoSwap_A_SortOrder', 'OBJECT'
GO

-- [RENAME] روی DEFAULT: DF_CrmCities_IsActive
EXEC sp_rename 'dbo.DF_CrmCities_IsActive', 'DF_CrmGeoSwap_A_IsActive', 'OBJECT'
GO

-- [RENAME] روی DEFAULT: DF_CrmCities_RowGuid
EXEC sp_rename 'dbo.DF_CrmCities_RowGuid', 'DF_CrmGeoSwap_A_RowGuid', 'OBJECT'
GO

-- [RENAME] روی DEFAULT: DF_CrmCities_DateInsert
EXEC sp_rename 'dbo.DF_CrmCities_DateInsert', 'DF_CrmGeoSwap_A_DateInsert', 'OBJECT'
GO

-- [RENAME] روی PRIMARY KEY CONSTRAINT: PK_CrmCounties
EXEC sp_rename 'dbo.PK_CrmCounties', 'PK_CrmGeoSwap_B', 'OBJECT'
GO

-- [RENAME] روی UNIQUE KEY CONSTRAINT: UQ_CrmCounties_CityID_Code
EXEC sp_rename 'dbo.UQ_CrmCounties_CityID_Code', 'UQ_CrmGeoSwap_B', 'OBJECT'
GO

-- [RENAME] روی FOREIGN KEY CONSTRAINT: FK_CrmCounties_City
EXEC sp_rename 'dbo.FK_CrmCounties_City', 'FK_CrmGeoSwap_B', 'OBJECT'
GO

-- [RENAME] روی DEFAULT: DF_CrmCounties_SortOrder
EXEC sp_rename 'dbo.DF_CrmCounties_SortOrder', 'DF_CrmGeoSwap_B_SortOrder', 'OBJECT'
GO

-- [RENAME] روی DEFAULT: DF_CrmCounties_IsActive
EXEC sp_rename 'dbo.DF_CrmCounties_IsActive', 'DF_CrmGeoSwap_B_IsActive', 'OBJECT'
GO

-- [RENAME] روی DEFAULT: DF_CrmCounties_RowGuid
EXEC sp_rename 'dbo.DF_CrmCounties_RowGuid', 'DF_CrmGeoSwap_B_RowGuid', 'OBJECT'
GO

-- [RENAME] روی DEFAULT: DF_CrmCounties_DateInsert
EXEC sp_rename 'dbo.DF_CrmCounties_DateInsert', 'DF_CrmGeoSwap_B_DateInsert', 'OBJECT'
GO

-- [RENAME] روی FOREIGN KEY CONSTRAINT: FK_CrmAddresses_City
EXEC sp_rename 'dbo.FK_CrmAddresses_City', 'FK_CrmAddresses_GeoSwap_A', 'OBJECT'
GO

-- [RENAME] روی FOREIGN KEY CONSTRAINT: FK_CrmAddresses_County
EXEC sp_rename 'dbo.FK_CrmAddresses_County', 'FK_CrmAddresses_GeoSwap_B', 'OBJECT'
GO

-- [RENAME] روی COLUMN: CityID
EXEC sp_rename 'dbo.CrmGeoSwap_A.CityID', 'CountyID', 'COLUMN'
GO

-- [RENAME] روی COLUMN: CountyID
EXEC sp_rename 'dbo.CrmGeoSwap_B.CountyID', 'GeoSwap_TmpID', 'COLUMN'
GO

-- [RENAME] روی COLUMN: CityID
EXEC sp_rename 'dbo.CrmGeoSwap_B.CityID', 'CountyID', 'COLUMN'
GO

-- [RENAME] روی COLUMN: GeoSwap_TmpID
EXEC sp_rename 'dbo.CrmGeoSwap_B.GeoSwap_TmpID', 'CityID', 'COLUMN'
GO

-- [RENAME] روی COLUMN: CityID
EXEC sp_rename 'dbo.CrmAddresses.CityID', 'GeoSwap_TmpID', 'COLUMN'
GO

-- [RENAME] روی COLUMN: CountyID
EXEC sp_rename 'dbo.CrmAddresses.CountyID', 'CityID', 'COLUMN'
GO

-- [RENAME] روی COLUMN: GeoSwap_TmpID
EXEC sp_rename 'dbo.CrmAddresses.GeoSwap_TmpID', 'CountyID', 'COLUMN'
GO

-- [RENAME] روی TABLE: CrmGeoSwap_A
EXEC sp_rename 'dbo.CrmGeoSwap_A', 'CrmCounties'
GO

-- [RENAME] روی TABLE: CrmGeoSwap_B
EXEC sp_rename 'dbo.CrmGeoSwap_B', 'CrmCities'
GO

-- [RENAME] روی PRIMARY KEY CONSTRAINT: PK_CrmGeoSwap_A
EXEC sp_rename 'dbo.PK_CrmGeoSwap_A', 'PK_CrmCounties', 'OBJECT'
GO

-- [RENAME] روی UNIQUE KEY CONSTRAINT: UQ_CrmGeoSwap_A
EXEC sp_rename 'dbo.UQ_CrmGeoSwap_A', 'UQ_CrmCounties_ProvinceID_Code', 'OBJECT'
GO

-- [RENAME] روی FOREIGN KEY CONSTRAINT: FK_CrmGeoSwap_A
EXEC sp_rename 'dbo.FK_CrmGeoSwap_A', 'FK_CrmCounties_Province', 'OBJECT'
GO

-- [RENAME] روی DEFAULT: DF_CrmGeoSwap_A_SortOrder
EXEC sp_rename 'dbo.DF_CrmGeoSwap_A_SortOrder', 'DF_CrmCounties_SortOrder', 'OBJECT'
GO

-- [RENAME] روی DEFAULT: DF_CrmGeoSwap_A_IsActive
EXEC sp_rename 'dbo.DF_CrmGeoSwap_A_IsActive', 'DF_CrmCounties_IsActive', 'OBJECT'
GO

-- [RENAME] روی DEFAULT: DF_CrmGeoSwap_A_RowGuid
EXEC sp_rename 'dbo.DF_CrmGeoSwap_A_RowGuid', 'DF_CrmCounties_RowGuid', 'OBJECT'
GO

-- [RENAME] روی DEFAULT: DF_CrmGeoSwap_A_DateInsert
EXEC sp_rename 'dbo.DF_CrmGeoSwap_A_DateInsert', 'DF_CrmCounties_DateInsert', 'OBJECT'
GO

-- [RENAME] روی INDEX: IX_CrmCities_ProvinceID
EXEC sp_rename 'dbo.CrmCounties.IX_CrmCities_ProvinceID', 'IX_CrmCounties_ProvinceID', 'INDEX'
GO

-- [RENAME] روی PRIMARY KEY CONSTRAINT: PK_CrmGeoSwap_B
EXEC sp_rename 'dbo.PK_CrmGeoSwap_B', 'PK_CrmCities', 'OBJECT'
GO

-- [RENAME] روی UNIQUE KEY CONSTRAINT: UQ_CrmGeoSwap_B
EXEC sp_rename 'dbo.UQ_CrmGeoSwap_B', 'UQ_CrmCities_CountyID_Code', 'OBJECT'
GO

-- [RENAME] روی FOREIGN KEY CONSTRAINT: FK_CrmGeoSwap_B
EXEC sp_rename 'dbo.FK_CrmGeoSwap_B', 'FK_CrmCities_County', 'OBJECT'
GO

-- [RENAME] روی DEFAULT: DF_CrmGeoSwap_B_SortOrder
EXEC sp_rename 'dbo.DF_CrmGeoSwap_B_SortOrder', 'DF_CrmCities_SortOrder', 'OBJECT'
GO

-- [RENAME] روی DEFAULT: DF_CrmGeoSwap_B_IsActive
EXEC sp_rename 'dbo.DF_CrmGeoSwap_B_IsActive', 'DF_CrmCities_IsActive', 'OBJECT'
GO

-- [RENAME] روی DEFAULT: DF_CrmGeoSwap_B_RowGuid
EXEC sp_rename 'dbo.DF_CrmGeoSwap_B_RowGuid', 'DF_CrmCities_RowGuid', 'OBJECT'
GO

-- [RENAME] روی DEFAULT: DF_CrmGeoSwap_B_DateInsert
EXEC sp_rename 'dbo.DF_CrmGeoSwap_B_DateInsert', 'DF_CrmCities_DateInsert', 'OBJECT'
GO

-- [RENAME] روی INDEX: IX_CrmCounties_CityID
EXEC sp_rename 'dbo.CrmCities.IX_CrmCounties_CityID', 'IX_CrmCities_CountyID', 'INDEX'
GO

-- [RENAME] روی FOREIGN KEY CONSTRAINT: FK_CrmAddresses_GeoSwap_A
EXEC sp_rename 'dbo.FK_CrmAddresses_GeoSwap_A', 'FK_CrmAddresses_County', 'OBJECT'
GO

-- [RENAME] روی FOREIGN KEY CONSTRAINT: FK_CrmAddresses_GeoSwap_B
EXEC sp_rename 'dbo.FK_CrmAddresses_GeoSwap_B', 'FK_CrmAddresses_City', 'OBJECT'
GO

-- [CREATE_TABLE] روی TABLE: CrmNeighborhoods
CREATE TABLE dbo.CrmNeighborhoods
(
    NeighborhoodID INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmNeighborhoods PRIMARY KEY,
    CityID         INT NOT NULL,
    Code           NVARCHAR(64)  NOT NULL,
    DisplayName    NVARCHAR(200) NOT NULL,
    SortOrder      INT NOT NULL CONSTRAINT DF_CrmNeighborhoods_SortOrder DEFAULT (0),
    IsActive       BIT NOT NULL CONSTRAINT DF_CrmNeighborhoods_IsActive DEFAULT (1),
    RowGuid        UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmNeighborhoods_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmNeighborhoods_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate     DATETIME2 NULL,
    UserID_LastUpdate   INT NULL,
    CONSTRAINT UQ_CrmNeighborhoods_CityID_Code UNIQUE (CityID, Code),
    CONSTRAINT FK_CrmNeighborhoods_City FOREIGN KEY (CityID) REFERENCES dbo.CrmCities(CityID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmNeighborhoods_CityID
CREATE INDEX IX_CrmNeighborhoods_CityID ON dbo.CrmNeighborhoods(CityID)
GO

-- [ALTER_TABLE] روی TABLE: CrmAddresses
ALTER TABLE dbo.CrmAddresses ADD NeighborhoodID INT NULL
GO

-- [ALTER_TABLE] روی TABLE: CrmAddresses
ALTER TABLE dbo.CrmAddresses ADD CONSTRAINT FK_CrmAddresses_Neighborhood FOREIGN KEY (NeighborhoodID) REFERENCES dbo.CrmNeighborhoods(NeighborhoodID)
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetCounties
-- ---------- 7. SPs: County (child of Province) ----------
ALTER PROCEDURE dbo.sp_Crm_GetCounties
    @ProvinceID INT           = NULL,
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT co.CountyID, co.ProvinceID, co.Code, co.DisplayName, co.SortOrder, co.IsActive,
           co.Date_InsertFirst, co.UserID_InsertFirst, co.Date_LastUpdate, co.UserID_LastUpdate,
           p.DisplayName AS ProvinceName
    FROM dbo.CrmCounties co
    JOIN dbo.CrmProvinces p ON p.ProvinceID = co.ProvinceID
    WHERE (@ProvinceID IS NULL OR co.ProvinceID = @ProvinceID)
      AND (@SearchText IS NULL OR @SearchText = N''
           OR co.DisplayName LIKE N'%' + @SearchText + N'%'
           OR co.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR co.IsActive = @IsActive)
    ORDER BY CAST(co.Code AS INT);
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveCounty
ALTER PROCEDURE dbo.sp_Crm_SaveCounty
    @CountyID     INT           = NULL,
    @ProvinceID   INT,
    @DisplayName  NVARCHAR(200),
    @SortOrder    INT           = 0,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmProvinces WHERE ProvinceID = @ProvinceID)
    BEGIN SELECT 0 AS Success, N'استانِ انتخاب‌شده معتبر نیست.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF @CountyID IS NULL
    BEGIN
        DECLARE @NextCode INT;
        SELECT @NextCode = ISNULL(MAX(CAST(Code AS INT)), 100) + 1 FROM dbo.CrmCounties WHERE ISNUMERIC(Code) = 1;
        IF @NextCode < 101 SET @NextCode = 101;

        INSERT INTO dbo.CrmCounties (ProvinceID, Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@ProvinceID, CAST(@NextCode AS NVARCHAR(64)), @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'شهرستان ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS CountyID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmCounties WHERE CountyID = @CountyID)
        BEGIN SELECT 0 AS Success, N'شهرستان یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmCounties
        SET ProvinceID = @ProvinceID, DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE CountyID = @CountyID;

        SELECT 1 AS Success, N'شهرستان ویرایش شد.' AS Message, @CountyID AS CountyID;
    END
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_ToggleCountyActive
ALTER PROCEDURE dbo.sp_Crm_ToggleCountyActive
    @CountyID INT,
    @UserID   INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmCounties WHERE CountyID = @CountyID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'شهرستان یافت نشد.' AS Message; RETURN; END

    IF @Current = 1 AND EXISTS (SELECT 1 FROM dbo.CrmCities c WHERE c.CountyID = @CountyID AND c.IsActive = 1)
    BEGIN SELECT 0 AS Success, N'این شهرستان دارایِ یک یا چند شهرِ فعال است؛ ابتدا آن‌ها را غیرفعال کنید.' AS Message; RETURN; END

    UPDATE dbo.CrmCounties
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE CountyID = @CountyID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'شهرستان غیرفعال شد.' ELSE N'شهرستان فعال شد.' END AS Message;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetCities
-- ---------- 8. SPs: City (child of County) ----------
ALTER PROCEDURE dbo.sp_Crm_GetCities
    @CountyID   INT           = NULL,
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT c.CityID, c.CountyID, c.Code, c.DisplayName, c.SortOrder, c.IsActive,
           c.Date_InsertFirst, c.UserID_InsertFirst, c.Date_LastUpdate, c.UserID_LastUpdate,
           co.DisplayName AS CountyName,
           co.ProvinceID  AS ProvinceID,
           p.DisplayName  AS ProvinceName
    FROM dbo.CrmCities c
    JOIN dbo.CrmCounties co ON co.CountyID = c.CountyID
    JOIN dbo.CrmProvinces p ON p.ProvinceID = co.ProvinceID
    WHERE (@CountyID IS NULL OR c.CountyID = @CountyID)
      AND (@SearchText IS NULL OR @SearchText = N''
           OR c.DisplayName LIKE N'%' + @SearchText + N'%'
           OR c.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR c.IsActive = @IsActive)
    ORDER BY CAST(c.Code AS INT);
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveCity
ALTER PROCEDURE dbo.sp_Crm_SaveCity
    @CityID       INT           = NULL,
    @CountyID     INT,
    @DisplayName  NVARCHAR(200),
    @SortOrder    INT           = 0,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmCounties WHERE CountyID = @CountyID)
    BEGIN SELECT 0 AS Success, N'شهرستانِ انتخاب‌شده معتبر نیست.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF @CityID IS NULL
    BEGIN
        DECLARE @NextCode INT;
        SELECT @NextCode = ISNULL(MAX(CAST(Code AS INT)), 100) + 1 FROM dbo.CrmCities WHERE ISNUMERIC(Code) = 1;
        IF @NextCode < 101 SET @NextCode = 101;

        INSERT INTO dbo.CrmCities (CountyID, Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@CountyID, CAST(@NextCode AS NVARCHAR(64)), @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'شهر ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS CityID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmCities WHERE CityID = @CityID)
        BEGIN SELECT 0 AS Success, N'شهر یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmCities
        SET CountyID = @CountyID, DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE CityID = @CityID;

        SELECT 1 AS Success, N'شهر ویرایش شد.' AS Message, @CityID AS CityID;
    END
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_ToggleCityActive
ALTER PROCEDURE dbo.sp_Crm_ToggleCityActive
    @CityID INT,
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmCities WHERE CityID = @CityID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'شهر یافت نشد.' AS Message; RETURN; END

    IF @Current = 1 AND EXISTS (SELECT 1 FROM dbo.CrmNeighborhoods n WHERE n.CityID = @CityID AND n.IsActive = 1)
    BEGIN SELECT 0 AS Success, N'این شهر دارایِ یک یا چند محلهٔ فعال است؛ ابتدا آن‌ها را غیرفعال کنید.' AS Message; RETURN; END

    UPDATE dbo.CrmCities
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE CityID = @CityID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'شهر غیرفعال شد.' ELSE N'شهر فعال شد.' END AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetNeighborhoods
-- ---------- 9. SPs: Neighborhood (child of City) ----------
CREATE PROCEDURE dbo.sp_Crm_GetNeighborhoods
    @CityID     INT           = NULL,
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT n.NeighborhoodID, n.CityID, n.Code, n.DisplayName, n.SortOrder, n.IsActive,
           n.Date_InsertFirst, n.UserID_InsertFirst, n.Date_LastUpdate, n.UserID_LastUpdate,
           c.DisplayName  AS CityName,
           c.CountyID     AS CountyID,
           co.DisplayName AS CountyName
    FROM dbo.CrmNeighborhoods n
    JOIN dbo.CrmCities c ON c.CityID = n.CityID
    JOIN dbo.CrmCounties co ON co.CountyID = c.CountyID
    WHERE (@CityID IS NULL OR n.CityID = @CityID)
      AND (@SearchText IS NULL OR @SearchText = N''
           OR n.DisplayName LIKE N'%' + @SearchText + N'%'
           OR n.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR n.IsActive = @IsActive)
    ORDER BY CAST(n.Code AS INT);
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SaveNeighborhood
CREATE PROCEDURE dbo.sp_Crm_SaveNeighborhood
    @NeighborhoodID INT           = NULL,
    @CityID         INT,
    @DisplayName    NVARCHAR(200),
    @SortOrder      INT           = 0,
    @UserID         INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmCities WHERE CityID = @CityID)
    BEGIN SELECT 0 AS Success, N'شهرِ انتخاب‌شده معتبر نیست.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF @NeighborhoodID IS NULL
    BEGIN
        DECLARE @NextCode INT;
        SELECT @NextCode = ISNULL(MAX(CAST(Code AS INT)), 100) + 1 FROM dbo.CrmNeighborhoods WHERE ISNUMERIC(Code) = 1;
        IF @NextCode < 101 SET @NextCode = 101;

        INSERT INTO dbo.CrmNeighborhoods (CityID, Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@CityID, CAST(@NextCode AS NVARCHAR(64)), @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'محله ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS NeighborhoodID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmNeighborhoods WHERE NeighborhoodID = @NeighborhoodID)
        BEGIN SELECT 0 AS Success, N'محله یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmNeighborhoods
        SET CityID = @CityID, DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE NeighborhoodID = @NeighborhoodID;

        SELECT 1 AS Success, N'محله ویرایش شد.' AS Message, @NeighborhoodID AS NeighborhoodID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_ToggleNeighborhoodActive
CREATE PROCEDURE dbo.sp_Crm_ToggleNeighborhoodActive
    @NeighborhoodID INT,
    @UserID         INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmNeighborhoods WHERE NeighborhoodID = @NeighborhoodID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'محله یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmNeighborhoods
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE NeighborhoodID = @NeighborhoodID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'محله غیرفعال شد.' ELSE N'محله فعال شد.' END AS Message;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_ToggleProvinceActive
-- ---------- 10. SPs: Province toggle now checks Counties ----------
ALTER PROCEDURE dbo.sp_Crm_ToggleProvinceActive
    @ProvinceID INT,
    @UserID     INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmProvinces WHERE ProvinceID = @ProvinceID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'استان یافت نشد.' AS Message; RETURN; END

    IF @Current = 1 AND EXISTS (SELECT 1 FROM dbo.CrmCounties co WHERE co.ProvinceID = @ProvinceID AND co.IsActive = 1)
    BEGIN SELECT 0 AS Success, N'این استان دارایِ یک یا چند شهرستانِ فعال است؛ ابتدا آن‌ها را غیرفعال کنید.' AS Message; RETURN; END

    UPDATE dbo.CrmProvinces
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE ProvinceID = @ProvinceID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'استان غیرفعال شد.' ELSE N'استان فعال شد.' END AS Message;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetAddresses
-- ---------- 11. SPs: Address (Province → County → City → Neighborhood?) ----------
ALTER PROCEDURE dbo.sp_Crm_GetAddresses
    @PartyID  INT = NULL,
    @PersonID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT ad.AddressID, ad.PartyID, ad.PersonID, ad.AddressTitleID, ad.ProvinceID, ad.CountyID, ad.CityID,
           ad.NeighborhoodID, ad.MunicipalZoneID, ad.PostalCode, ad.AddressText, ad.PlateNumber, ad.Unit, ad.IsActive,
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
    IF NOT EXISTS (SELECT 1 FROM dbo.CrmCounties WHERE CountyID = @CountyID AND ProvinceID = @ProvinceID)
    BEGIN SELECT 0 AS Success, N'شهرستانِ انتخاب‌شده متعلق به استانِ انتخاب‌شده نیست.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.CrmCities WHERE CityID = @CityID AND CountyID = @CountyID)
    BEGIN SELECT 0 AS Success, N'شهرِ انتخاب‌شده متعلق به شهرستانِ انتخاب‌شده نیست.' AS Message; RETURN; END
    IF @NeighborhoodID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmNeighborhoods WHERE NeighborhoodID = @NeighborhoodID AND CityID = @CityID)
    BEGIN SELECT 0 AS Success, N'محلهٔ انتخاب‌شده متعلق به شهرِ انتخاب‌شده نیست.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@AddressText)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'متنِ آدرس الزامی است.' AS Message; RETURN; END

    IF @AddressID IS NULL
    BEGIN
        INSERT INTO dbo.CrmAddresses (
            PartyID, PersonID, AddressTitleID, ProvinceID, CountyID, CityID, NeighborhoodID, MunicipalZoneID,
            PostalCode, AddressText, PlateNumber, Unit, IsActive, Date_InsertFirst, UserID_InsertFirst
        )
        VALUES (
            @PartyID, @PersonID, @AddressTitleID, @ProvinceID, @CountyID, @CityID, @NeighborhoodID, @MunicipalZoneID,
            @PostalCode, @AddressText, @PlateNumber, @Unit, 1, SYSDATETIME(), @UserID
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
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE AddressID = @AddressID;

        SELECT 1 AS Success, N'آدرس ویرایش شد.' AS Message, @AddressID AS AddressID;
    END
END
GO

