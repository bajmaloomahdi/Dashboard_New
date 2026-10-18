/* ==========================================================================
   پچ خودکار شماره: 100 | نام: crm_city_population
   تاریخ: 2026-10-18 09:38:57 | شامل 5 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: CrmCities
ALTER TABLE dbo.CrmCities ADD Population INT NULL CONSTRAINT CK_CrmCities_Population CHECK (Population IS NULL OR Population >= 0)
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveCity
ALTER PROCEDURE dbo.sp_Crm_SaveCity
    @CityID       INT           = NULL,
    @CountyID     INT,
    @DisplayName  NVARCHAR(200),
    @SortOrder    INT           = 0,
    @UserID       INT,
    @Population   INT           = NULL  -- جمعیتِ شهر (اختیاری)؛ جمعیتِ شهرستان/استان از جمعِ شهرها محاسبه می‌شود
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmCounties WHERE CountyID = @CountyID)
    BEGIN SELECT 0 AS Success, N'شهرستانِ انتخاب‌شده معتبر نیست.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END
    IF @Population < 0
    BEGIN SELECT 0 AS Success, N'جمعیت نمی‌تواند منفی باشد.' AS Message; RETURN; END

    IF @CityID IS NULL
    BEGIN
        DECLARE @NextCode INT;
        SELECT @NextCode = ISNULL(MAX(CAST(Code AS INT)), 100) + 1 FROM dbo.CrmCities WHERE ISNUMERIC(Code) = 1;
        IF @NextCode < 101 SET @NextCode = 101;

        INSERT INTO dbo.CrmCities (CountyID, Code, DisplayName, SortOrder, Population, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@CountyID, CAST(@NextCode AS NVARCHAR(64)), @DisplayName, ISNULL(@SortOrder, 0), @Population, 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'شهر ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS CityID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmCities WHERE CityID = @CityID)
        BEGIN SELECT 0 AS Success, N'شهر یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmCities
        SET CountyID = @CountyID, DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0), Population = @Population,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE CityID = @CityID;

        SELECT 1 AS Success, N'شهر ویرایش شد.' AS Message, @CityID AS CityID;
    END
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetCities
ALTER PROCEDURE dbo.sp_Crm_GetCities
    @CountyID   INT           = NULL,
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT c.CityID, c.CountyID, c.Code, c.DisplayName, c.SortOrder, c.IsActive, c.Population,
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
    ORDER BY CASE WHEN c.SortOrder > 0 THEN c.SortOrder ELSE CAST(c.Code AS INT) END, CAST(c.Code AS INT), c.CityID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetCounties
ALTER PROCEDURE dbo.sp_Crm_GetCounties
    @ProvinceID INT           = NULL,
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT co.CountyID, co.ProvinceID, co.Code, co.DisplayName, co.SortOrder, co.IsActive,
           co.Date_InsertFirst, co.UserID_InsertFirst, co.Date_LastUpdate, co.UserID_LastUpdate,
           p.DisplayName AS ProvinceName,
           -- جمعیتِ شهرستان = جمعِ جمعیتِ شهرهایش (همهٔ شهرها؛ شهرِ بدونِ جمعیت در جمع حساب نمی‌شود)
           (SELECT SUM(CAST(ci.Population AS BIGINT)) FROM dbo.CrmCities ci WHERE ci.CountyID = co.CountyID) AS Population
    FROM dbo.CrmCounties co
    JOIN dbo.CrmProvinces p ON p.ProvinceID = co.ProvinceID
    WHERE (@ProvinceID IS NULL OR co.ProvinceID = @ProvinceID)
      AND (@SearchText IS NULL OR @SearchText = N''
           OR co.DisplayName LIKE N'%' + @SearchText + N'%'
           OR co.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR co.IsActive = @IsActive)
    ORDER BY CASE WHEN co.SortOrder > 0 THEN co.SortOrder ELSE CAST(co.Code AS INT) END, CAST(co.Code AS INT), co.CountyID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetProvinces
ALTER PROCEDURE dbo.sp_Crm_GetProvinces
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT ProvinceID, Code, DisplayName, SortOrder, IsActive,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate,
           -- جمعیتِ استان = جمعِ جمعیتِ شهرستان‌هایش = جمعِ همهٔ شهرهایِ آن شهرستان‌ها
           (SELECT SUM(CAST(ci.Population AS BIGINT))
              FROM dbo.CrmCities ci JOIN dbo.CrmCounties co ON co.CountyID = ci.CountyID
             WHERE co.ProvinceID = p.ProvinceID) AS Population
    FROM dbo.CrmProvinces p
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR p.DisplayName LIKE N'%' + @SearchText + N'%'
           OR p.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR p.IsActive = @IsActive)
    ORDER BY CASE WHEN p.SortOrder > 0 THEN p.SortOrder ELSE CAST(p.Code AS INT) END, CAST(p.Code AS INT), p.ProvinceID;
END
GO

