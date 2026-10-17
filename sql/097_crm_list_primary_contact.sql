/* ==========================================================================
   پچ خودکار شماره: 097 | نام: crm_list_primary_contact
   تاریخ: 2026-10-17 13:40:23 | شامل 2 دستور SQL
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
           (SELECT COUNT(DISTINCT pb.BrandID) FROM dbo.CrmPartyBrands pb
              JOIN dbo.CrmPartyBrandCategories pbc ON pbc.PartyBrandID = pb.PartyBrandID
             WHERE pb.PartyID = p.PartyID AND pbc.IsActive = 1) AS ActiveBrandCount,
           (SELECT COUNT(*) FROM dbo.CrmAddresses ad WHERE ad.PartyID = p.PartyID AND ad.IsActive = 1) AS ActiveAddressCount,
           (SELECT COUNT(*) FROM dbo.CrmContacts c WHERE c.PartyID = p.PartyID AND c.IsActive = 1) AS ActiveContactCount,
           (SELECT COUNT(*) FROM dbo.CrmPartyClassifications pc WHERE pc.PartyID = p.PartyID AND pc.IsActive = 1) AS ClassificationCount,
           pc1.ContactValue AS PrimaryContactValue, pc1.Extension AS PrimaryContactExtension, pc1.TypeName AS PrimaryContactType
    FROM dbo.CrmParties p
    LEFT JOIN dbo.CrmDepartments d ON d.DepartmentID = p.DepartmentID
    LEFT JOIN dbo.CrmPartyTypes pt ON pt.PartyTypeID = p.PartyTypeID
    LEFT JOIN dbo.CrmActivities a ON a.ActivityID = p.ActivityID
    -- تماسِ اصلی برایِ کارتِ فهرست: «تماسِ اصلی»ِ فعال، وگرنه موبایل، تلفن، ایمیل و بعد بقیه
    OUTER APPLY (
        SELECT TOP 1 c.ContactValue, c.Extension, LTRIM(RTRIM(t.DisplayName)) AS TypeName
        FROM dbo.CrmContacts c
        JOIN dbo.CrmContactTypes t ON t.ContactTypeID = c.ContactTypeID
        WHERE c.PartyID = p.PartyID AND c.IsActive = 1
        ORDER BY c.IsPrimary DESC, CASE LTRIM(RTRIM(t.DisplayName)) WHEN N'موبایل' THEN 0 WHEN N'تلفن' THEN 1 WHEN N'ایمیل' THEN 2 ELSE 3 END, c.ContactID
    ) pc1
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
           LTRIM(per.FirstName + N' ' + per.LastName) AS DisplayName,
           ti.DisplayName AS TitleName,
           per.Date_InsertFirst, per.UserID_InsertFirst, per.Date_LastUpdate, per.UserID_LastUpdate,
           pc1.ContactValue AS PrimaryContactValue, pc1.Extension AS PrimaryContactExtension, pc1.TypeName AS PrimaryContactType
    FROM dbo.CrmPersons per
    LEFT JOIN dbo.CrmTitles ti ON ti.TitleID = per.TitleID
    -- تماسِ اصلی برایِ کارتِ فهرست: تماس‌هایِ خودِ مخاطب یا تماس‌هایِ طرف‌حساب‌ها که به او مرتبط شده‌اند؛
    -- اولویت با «تماسِ اصلی»، بعد تماسِ خودِ مخاطب، بعد موبایل، تلفن، ایمیل
    OUTER APPLY (
        SELECT TOP 1 c.ContactValue, c.Extension, LTRIM(RTRIM(t.DisplayName)) AS TypeName
        FROM dbo.CrmContacts c
        JOIN dbo.CrmContactTypes t ON t.ContactTypeID = c.ContactTypeID
        WHERE c.IsActive = 1 AND (c.PersonID = per.PersonID OR c.RelatedPersonID = per.PersonID)
        ORDER BY c.IsPrimary DESC, CASE WHEN c.PersonID = per.PersonID THEN 0 ELSE 1 END, CASE LTRIM(RTRIM(t.DisplayName)) WHEN N'موبایل' THEN 0 WHEN N'تلفن' THEN 1 WHEN N'ایمیل' THEN 2 ELSE 3 END, c.ContactID
    ) pc1
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR per.FirstName LIKE N'%' + @SearchText + N'%'
           OR per.LastName LIKE N'%' + @SearchText + N'%'
           OR LTRIM(per.FirstName + N' ' + per.LastName) LIKE N'%' + @SearchText + N'%'
           OR per.IdentifierNumber LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR per.IsActive = @IsActive)
    ORDER BY per.PersonID DESC;
END
GO

