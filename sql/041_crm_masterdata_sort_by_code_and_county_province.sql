/* ==========================================================================
   پچ خودکار شماره: 041 | نام: crm_masterdata_sort_by_code_and_county_province
   تاریخ: 2026-09-24 11:02:53 | شامل 12 دستور SQL
   ========================================================================== */

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetDepartments
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

/* [DATA FIX] — Idempotent؛ DML است، توسطِ Triggerِ DDL ردیابی نمی‌شود، پس
   دستی اضافه شد (هم‌الگو با Seedِ patch 038). Phase 1 برایِ CrmContactTypes/
   CrmAddressTitles/CrmTitles کدهایِ ثابتِ حرفی (PHONE/HEAD_OFFICE/MR و...)
   Seed کرده بود که با ORDER BY CAST(Code AS INT) بالا ناسازگار است (خطایِ
   تبدیلِ نوع). این بخش آن‌ها را با همان الگویِ خودکارِ ۱۰۱-به-بعد (بر اساسِ
   SortOrderِ موجود) به کدِ عددی تبدیل می‌کند؛ فقط یک‌بار اجرا می‌شود چون بعد
   از اولین اجرا همهٔ کدها عددی خواهند بود. */

IF EXISTS (SELECT 1 FROM dbo.CrmContactTypes WHERE ISNUMERIC(Code) = 0)
BEGIN
    ;WITH cte AS (
        SELECT ContactTypeID, ROW_NUMBER() OVER (ORDER BY SortOrder, ContactTypeID) + 100 AS NewCode
        FROM dbo.CrmContactTypes
    )
    UPDATE ct SET ct.Code = CAST(cte.NewCode AS NVARCHAR(64))
    FROM dbo.CrmContactTypes ct JOIN cte ON cte.ContactTypeID = ct.ContactTypeID;
END

IF EXISTS (SELECT 1 FROM dbo.CrmAddressTitles WHERE ISNUMERIC(Code) = 0)
BEGIN
    ;WITH cte AS (
        SELECT AddressTitleID, ROW_NUMBER() OVER (ORDER BY SortOrder, AddressTitleID) + 100 AS NewCode
        FROM dbo.CrmAddressTitles
    )
    UPDATE t SET t.Code = CAST(cte.NewCode AS NVARCHAR(64))
    FROM dbo.CrmAddressTitles t JOIN cte ON cte.AddressTitleID = t.AddressTitleID;
END

IF EXISTS (SELECT 1 FROM dbo.CrmTitles WHERE ISNUMERIC(Code) = 0)
BEGIN
    ;WITH cte AS (
        SELECT TitleID, ROW_NUMBER() OVER (ORDER BY SortOrder, TitleID) + 100 AS NewCode
        FROM dbo.CrmTitles
    )
    UPDATE x SET x.Code = CAST(cte.NewCode AS NVARCHAR(64))
    FROM dbo.CrmTitles x JOIN cte ON cte.TitleID = x.TitleID;
END
GO

