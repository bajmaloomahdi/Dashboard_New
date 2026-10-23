/* ==========================================================================
   پچ خودکار شماره: 115 | نام: crm_party_list_classifications
   تاریخ: 2026-10-23 08:51:18 | شامل 1 دستور SQL
   ========================================================================== */

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetParties
-- ---------- 7. شمارش‌ها از رویِ ردیف‌هایِ فعالِ گرید ----------
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
           -- پچِ ۱۱۵: همهٔ نوع‌ها و فعالیت‌هایِ طبقه‌بندی‌هایِ فعالِ طرف‌حساب (به ترتیبِ ثبت) برایِ ستون‌هایِ جدایِ گرید
           (SELECT N'[' + STRING_AGG(N'"' + STRING_ESCAPE(x.n, 'json') + N'"', N',') WITHIN GROUP (ORDER BY x.o) + N']'
              FROM (SELECT LTRIM(RTRIM(t.DisplayName)) AS n, MIN(c.ClassificationID) AS o
                      FROM dbo.CrmPartyClassifications c JOIN dbo.CrmPartyTypes t ON t.PartyTypeID = c.PartyTypeID
                     WHERE c.PartyID = p.PartyID AND c.IsActive = 1 GROUP BY LTRIM(RTRIM(t.DisplayName))) x) AS PartyTypeNames,
           (SELECT N'[' + STRING_AGG(N'"' + STRING_ESCAPE(x.n, 'json') + N'"', N',') WITHIN GROUP (ORDER BY x.o) + N']'
              FROM (SELECT LTRIM(RTRIM(t.DisplayName)) AS n, MIN(c.ClassificationID) AS o
                      FROM dbo.CrmPartyClassifications c JOIN dbo.CrmActivities t ON t.ActivityID = c.ActivityID
                     WHERE c.PartyID = p.PartyID AND c.IsActive = 1 GROUP BY LTRIM(RTRIM(t.DisplayName))) x) AS ActivityNames,
           (SELECT COUNT(DISTINCT pb.BrandID) FROM dbo.CrmPartyBrands pb
              JOIN dbo.CrmPartyBrandCategories pbc ON pbc.PartyBrandID = pb.PartyBrandID
             WHERE pb.PartyID = p.PartyID AND pbc.IsActive = 1) AS ActiveBrandCount,
           (SELECT COUNT(*) FROM dbo.CrmAddresses ad WHERE ad.PartyID = p.PartyID AND ad.IsActive = 1) AS ActiveAddressCount,
           (SELECT COUNT(*) FROM dbo.CrmContacts c WHERE c.PartyID = p.PartyID AND c.IsActive = 1) AS ActiveContactCount,
           (SELECT COUNT(*) FROM dbo.CrmPartyClassifications pc WHERE pc.PartyID = p.PartyID AND pc.IsActive = 1) AS ClassificationCount,
           pc1.ContactValue AS PrimaryContactValue, pc1.Extension AS PrimaryContactExtension, pc1.TypeName AS PrimaryContactType,
           LTRIM(RTRIM(ISNULL(pcti.DisplayName + N' ', N'') + LTRIM(pcper.FirstName + N' ' + pcper.LastName))) AS PrimaryContactPersonName,
           pa1.ProvinceName AS PrimaryAddressProvince, pa1.CityName AS PrimaryAddressCity,
           NULLIF(pa1.AddressText, N'') AS PrimaryAddressText, pa1.TitleName AS PrimaryAddressTitle
    FROM dbo.CrmParties p
    LEFT JOIN dbo.CrmDepartments d ON d.DepartmentID = p.DepartmentID
    LEFT JOIN dbo.CrmPartyTypes pt ON pt.PartyTypeID = p.PartyTypeID
    LEFT JOIN dbo.CrmActivities a ON a.ActivityID = p.ActivityID
    -- تماسِ اصلی برایِ کارتِ فهرست: «تماسِ اصلی»ِ فعال، وگرنه موبایل، تلفن، ایمیل و بعد بقیه
    OUTER APPLY (
        SELECT TOP 1 c.ContactValue, c.Extension, LTRIM(RTRIM(t.DisplayName)) AS TypeName, c.RelatedPersonID
        FROM dbo.CrmContacts c
        JOIN dbo.CrmContactTypes t ON t.ContactTypeID = c.ContactTypeID
        WHERE c.PartyID = p.PartyID AND c.IsActive = 1
        ORDER BY c.IsPrimary DESC, CASE LTRIM(RTRIM(t.DisplayName)) WHEN N'موبایل' THEN 0 WHEN N'تلفن' THEN 1 WHEN N'ایمیل' THEN 2 ELSE 3 END, c.ContactID
    ) pc1
    -- نامِ مخاطب بالایِ تماسِ اصلی: مخاطبی که همین تماس به او مرتبط است، وگرنه «مخاطبِ اصلی»ِ فعالِ طرف‌حساب
    OUTER APPLY (
        SELECT TOP 1 x.PersonID
        FROM (
            SELECT pc1.RelatedPersonID AS PersonID, 0 AS Ord WHERE pc1.RelatedPersonID IS NOT NULL
            UNION ALL
            SELECT r.PersonID, 1 FROM dbo.CrmPartyPersonRelations r
            WHERE r.PartyID = p.PartyID AND r.IsActive = 1 AND r.IsPrimaryContact = 1
        ) x
        ORDER BY x.Ord
    ) pcx
    LEFT JOIN dbo.CrmPersons pcper ON pcper.PersonID = pcx.PersonID
    LEFT JOIN dbo.CrmTitles pcti ON pcti.TitleID = pcper.TitleID
    -- آدرسِ اصلی برایِ نمایِ جدولی: اولین آدرسِ فعال (به ترتیبِ ثبت)
    OUTER APPLY (
        SELECT TOP 1 gp.DisplayName AS ProvinceName, gc.DisplayName AS CityName, ad.AddressText, adt.DisplayName AS TitleName
        FROM dbo.CrmAddresses ad
        LEFT JOIN dbo.CrmProvinces gp ON gp.ProvinceID = ad.ProvinceID
        LEFT JOIN dbo.CrmCities gc ON gc.CityID = ad.CityID
        LEFT JOIN dbo.CrmAddressTitles adt ON adt.AddressTitleID = ad.AddressTitleID
        WHERE ad.PartyID = p.PartyID AND ad.IsActive = 1
        ORDER BY ad.AddressID
    ) pa1
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

