/* ==========================================================================
   پچ خودکار شماره: 062 | نام: crm_brand_logo
   تاریخ: 2026-09-26 17:31:04 | شامل 5 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: CrmBrands
ALTER TABLE dbo.CrmBrands ADD LogoPath NVARCHAR(500) NULL, LogoMimeType NVARCHAR(100) NULL
GO

-- [ALTER_TABLE] روی TABLE: CrmBrands
ALTER TABLE dbo.CrmBrands ADD CONSTRAINT CK_CrmBrands_LogoPair CHECK ((LogoPath IS NULL AND LogoMimeType IS NULL) OR (LogoPath IS NOT NULL AND LogoMimeType IS NOT NULL))
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetBrands
ALTER PROCEDURE dbo.sp_Crm_GetBrands
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT b.BrandID, b.Name, b.Name AS DisplayName, b.Description, b.IsActive,
           b.LogoPath, b.LogoMimeType,
           b.Date_InsertFirst, b.UserID_InsertFirst, b.Date_LastUpdate, b.UserID_LastUpdate,
           (SELECT COUNT(DISTINCT pb.PartyID) FROM dbo.CrmPartyBrands pb
              JOIN dbo.CrmPartyBrandCategories pbc ON pbc.PartyBrandID = pb.PartyBrandID
             WHERE pb.BrandID = b.BrandID AND pbc.IsActive = 1) AS ActivePartyCount
    FROM dbo.CrmBrands b
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR b.Name LIKE N'%' + @SearchText + N'%'
           OR b.Description LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR b.IsActive = @IsActive)
    ORDER BY b.Name;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetBrand
ALTER PROCEDURE dbo.sp_Crm_GetBrand
    @BrandID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT b.BrandID, b.Name, b.Name AS DisplayName, b.Description, b.IsActive, b.RowGuid,
           b.LogoPath, b.LogoMimeType,
           b.Date_InsertFirst, b.UserID_InsertFirst, b.Date_LastUpdate, b.UserID_LastUpdate,
           cu.FirstName + N' ' + cu.LastName AS CreatedByName,
           mu.FirstName + N' ' + mu.LastName AS ModifiedByName
    FROM dbo.CrmBrands b
    LEFT JOIN dbo.Users cu ON cu.UserID = b.UserID_InsertFirst
    LEFT JOIN dbo.Users mu ON mu.UserID = b.UserID_LastUpdate
    WHERE b.BrandID = @BrandID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveBrand
-- @SetLogo = 1 → LogoPath/LogoMimeType با مقدارِ داده‌شده جایگزین می‌شوند (NULL = حذفِ لوگو)؛ @SetLogo = 0 → لوگو دست نمی‌خورد.
-- OldLogoPath برمی‌گردد تا لایهٔ PHP فایلِ قبلی را فقط بعد از ذخیرهٔ موفق حذف کند.
ALTER PROCEDURE dbo.sp_Crm_SaveBrand
    @BrandID      INT           = NULL,
    @Name         NVARCHAR(200),
    @Description  NVARCHAR(MAX) = NULL,
    @UserID       INT,
    @SetLogo      BIT           = 0,
    @LogoPath     NVARCHAR(500) = NULL,
    @LogoMimeType NVARCHAR(100) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    SET @Name = LTRIM(RTRIM(@Name));
    IF NULLIF(@Name, N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ برند الزامی است.' AS Message; RETURN; END
    IF EXISTS (SELECT 1 FROM dbo.CrmBrands WHERE Name = @Name AND BrandID <> ISNULL(@BrandID, 0))
    BEGIN SELECT 0 AS Success, N'برندی با این نام قبلاً ثبت شده است.' AS Message; RETURN; END
    IF @SetLogo = 1 AND ((@LogoPath IS NULL AND @LogoMimeType IS NOT NULL) OR (@LogoPath IS NOT NULL AND @LogoMimeType IS NULL))
    BEGIN SELECT 0 AS Success, N'اطلاعاتِ لوگو ناقص است.' AS Message; RETURN; END

    IF @BrandID IS NULL
    BEGIN
        INSERT INTO dbo.CrmBrands (Name, Description, LogoPath, LogoMimeType, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@Name, @Description,
                CASE WHEN @SetLogo = 1 THEN @LogoPath END, CASE WHEN @SetLogo = 1 THEN @LogoMimeType END,
                1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'برند ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS BrandID, CAST(NULL AS NVARCHAR(500)) AS OldLogoPath;
    END
    ELSE
    BEGIN
        DECLARE @OldLogoPath NVARCHAR(500), @Exists BIT = 0;
        SELECT @Exists = 1, @OldLogoPath = LogoPath FROM dbo.CrmBrands WHERE BrandID = @BrandID;
        IF @Exists = 0
        BEGIN SELECT 0 AS Success, N'برند یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmBrands
        SET Name = @Name, Description = @Description,
            LogoPath = CASE WHEN @SetLogo = 1 THEN @LogoPath ELSE LogoPath END,
            LogoMimeType = CASE WHEN @SetLogo = 1 THEN @LogoMimeType ELSE LogoMimeType END,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE BrandID = @BrandID;

        SELECT 1 AS Success, N'برند ویرایش شد.' AS Message, @BrandID AS BrandID,
               CASE WHEN @SetLogo = 1 THEN @OldLogoPath END AS OldLogoPath;
    END
END
GO

