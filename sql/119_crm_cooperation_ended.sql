/* ==========================================================================
   پچ خودکار شماره: 119 | نام: crm_cooperation_ended
   تاریخ: 2026-10-23 10:59:41 | شامل 8 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties ADD CooperationEndedAt DATETIME2(0) NULL
GO

-- [ALTER_TABLE] روی TABLE: CrmPersons
ALTER TABLE dbo.CrmPersons ADD CooperationEndedAt DATETIME2(0) NULL
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveParty
ALTER PROCEDURE dbo.sp_Crm_SaveParty
    @PartyID            INT           = NULL,
    @PartyNature        NVARCHAR(20),
    @OfficialName       NVARCHAR(200),
    @TradeName          NVARCHAR(200) = NULL,
    @RegistrationNumber NVARCHAR(50)  = NULL,
    @EconomicCode       NVARCHAR(50)  = NULL,
    @IdentifierNumber   NVARCHAR(20)  = NULL,
    @IdentifierDate     DATE          = NULL,
    @Description        NVARCHAR(MAX) = NULL,
    @DepartmentID       INT           = NULL,
    @PartyTypeID        INT           = NULL,
    @ActivityID         INT           = NULL,
    @UserID             INT,
    @AllowDuplicateName BIT           = 0, -- پچِ ۱۱۴: ۱ = کاربر هشدارِ نامِ تکراری را دیده و ثبت را تأیید کرده است
    -- پچِ ۱۱۹: قطعِ همکاری — NULL = بدونِ تغییر، ۱ = قطعِ همکاری در @CooperationEndedAt، ۰ = برقراریِ دوبارهٔ همکاری
    @CooperationEnded   BIT           = NULL,
    @CooperationEndedAt DATETIME2(0)  = NULL
AS
BEGIN
    SET NOCOUNT ON;

    IF @CooperationEnded = 1 AND @CooperationEndedAt IS NULL
    BEGIN SELECT 0 AS Success, N'تاریخ و ساعتِ قطعِ همکاری الزامی است.' AS Message; RETURN; END

    IF @PartyNature NOT IN (N'INDIVIDUAL', N'LEGAL')
    BEGIN SELECT 0 AS Success, N'ماهیتِ طرف‌حساب نامعتبر است.' AS Message; RETURN; END

    IF NULLIF(LTRIM(RTRIM(@OfficialName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نام الزامی است.' AS Message; RETURN; END

    IF @PartyNature = N'LEGAL' AND NULLIF(LTRIM(RTRIM(@IdentifierNumber)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'شناسهٔ ملی الزامی است.' AS Message; RETURN; END

    IF @PartyNature = N'INDIVIDUAL' SET @IdentifierNumber = NULL;

    IF @ActivityID IS NOT NULL AND @PartyTypeID IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM dbo.CrmActivities WHERE ActivityID = @ActivityID AND PartyTypeID = @PartyTypeID)
    BEGIN SELECT 0 AS Success, N'فعالیتِ انتخاب‌شده متعلق به نوعِ انتخاب‌شده نیست.' AS Message; RETURN; END

    IF @IdentifierNumber IS NOT NULL AND EXISTS (
        SELECT 1 FROM dbo.CrmParties
        WHERE IdentifierNumber = @IdentifierNumber
          AND (@PartyID IS NULL OR PartyID <> @PartyID)
    )
    BEGIN SELECT 0 AS Success, N'طرف‌حسابی با این شناسه از قبل ثبت شده است.' AS Message; RETURN; END

    -- نامِ تکراری (بینِ همهٔ طرف‌حساب‌ها، فعال و غیرفعال؛ پس از یکسان‌سازی): بدونِ تأییدِ کاربر ثبت نمی‌شود و
    -- هشدار + تعداد و تا ۵ موردِ مشابه برمی‌گردد؛ با @AllowDuplicateName = 1 ثبت/ویرایش انجام می‌شود (پچِ ۱۱۴)
    DECLARE @DupParty NVARCHAR(200), @DupPartyActive BIT;
    SELECT TOP 1 @DupParty = OfficialName, @DupPartyActive = IsActive
    FROM dbo.CrmParties
    WHERE (@PartyID IS NULL OR PartyID <> @PartyID)
      AND dbo.fn_Crm_NormalizeName(OfficialName) = dbo.fn_Crm_NormalizeName(@OfficialName)
    ORDER BY IsActive DESC, PartyID;
    IF @DupParty IS NOT NULL AND ISNULL(@AllowDuplicateName, 0) = 0
       /* ویرایش بدونِ تغییرِ نام (مثلاً رکوردی که قبلاً با تأیید ثبت شده) دوباره هشدار نمی‌دهد */
       AND (@PartyID IS NULL OR NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID AND dbo.fn_Crm_NormalizeName(OfficialName) = dbo.fn_Crm_NormalizeName(@OfficialName)))
    BEGIN
        SELECT 0 AS Success, N'DUPLICATE_NAME' AS ErrorCode,
               N'طرف‌حسابی با نامِ «' + @DupParty + N'» از قبل ثبت شده است' + CASE WHEN @DupPartyActive = 0 THEN N' (غیرفعال)' ELSE N'' END + N'. آیا با همین نام ثبت شود؟' AS Message,
               (SELECT COUNT(*) FROM dbo.CrmParties WHERE (@PartyID IS NULL OR PartyID <> @PartyID) AND dbo.fn_Crm_NormalizeName(OfficialName) = dbo.fn_Crm_NormalizeName(@OfficialName)) AS DuplicateCount,
               (SELECT TOP 5 PartyID AS id, OfficialName AS name, CAST(IsActive AS BIT) AS isActive FROM dbo.CrmParties WHERE (@PartyID IS NULL OR PartyID <> @PartyID) AND dbo.fn_Crm_NormalizeName(OfficialName) = dbo.fn_Crm_NormalizeName(@OfficialName) ORDER BY IsActive DESC, PartyID FOR JSON PATH) AS Duplicates;
        RETURN;
    END

    IF @PartyID IS NULL
    BEGIN
        INSERT INTO dbo.CrmParties (
            PartyNature, OfficialName, TradeName, RegistrationNumber, EconomicCode, IdentifierNumber, IdentifierDate,
            Description, DepartmentID, PartyTypeID, ActivityID, IsActive,
            Date_InsertFirst, UserID_InsertFirst, CooperationEndedAt
        )
        VALUES (
            @PartyNature, @OfficialName, @TradeName, @RegistrationNumber, @EconomicCode, @IdentifierNumber, @IdentifierDate,
            @Description, @DepartmentID, @PartyTypeID, @ActivityID, 1,
            SYSDATETIME(), @UserID, CASE WHEN @CooperationEnded = 1 THEN @CooperationEndedAt END
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
            CooperationEndedAt = CASE WHEN @CooperationEnded IS NULL THEN CooperationEndedAt WHEN @CooperationEnded = 1 THEN @CooperationEndedAt END,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE PartyID = @PartyID;

        SELECT 1 AS Success, N'طرف‌حساب ویرایش شد.' AS Message, @PartyID AS PartyID;
    END
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SavePerson
ALTER PROCEDURE dbo.sp_Crm_SavePerson
    @PersonID         INT           = NULL,
    @FirstName        NVARCHAR(100) = NULL,
    @LastName         NVARCHAR(100),
    @TitleID          INT           = NULL,
    @IdentifierNumber NVARCHAR(20)  = NULL,
    @IdentifierDate   DATE          = NULL,
    @Description      NVARCHAR(MAX) = NULL,
    @UserID           INT,
    @AllowDuplicateName BIT           = 0, -- پچِ ۱۱۴: ۱ = کاربر هشدارِ نامِ تکراری را دیده و ثبت را تأیید کرده است
    -- پچِ ۱۱۹: قطعِ همکاری — NULL = بدونِ تغییر، ۱ = قطعِ همکاری در @CooperationEndedAt، ۰ = برقراریِ دوبارهٔ همکاری
    @CooperationEnded   BIT           = NULL,
    @CooperationEndedAt DATETIME2(0)  = NULL
AS
BEGIN
    SET NOCOUNT ON;

    IF @CooperationEnded = 1 AND @CooperationEndedAt IS NULL
    BEGIN SELECT 0 AS Success, N'تاریخ و ساعتِ قطعِ همکاری الزامی است.' AS Message; RETURN; END

    -- نام اختیاری است (خالی ذخیره می‌شود)؛ نامِ خانوادگی و عنوان الزامی‌اند.
    SET @FirstName = ISNULL(LTRIM(RTRIM(@FirstName)), N'');
    SET @LastName = LTRIM(RTRIM(@LastName));

    IF NULLIF(@LastName, N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ خانوادگی الزامی است.' AS Message; RETURN; END
    IF @TitleID IS NULL
    BEGIN SELECT 0 AS Success, N'عنوان الزامی است.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.CrmTitles WHERE TitleID = @TitleID)
    BEGIN SELECT 0 AS Success, N'عنوانِ انتخاب‌شده معتبر نیست.' AS Message; RETURN; END

    -- نام و نامِ خانوادگیِ تکراری (بینِ همهٔ مخاطبین، فعال و غیرفعال؛ پس از یکسان‌سازی؛ عنوان در مقایسه نیست):
    -- بدونِ تأییدِ کاربر ثبت نمی‌شود و هشدار + تعداد و تا ۵ موردِ مشابه برمی‌گردد؛ با @AllowDuplicateName = 1 ثبت می‌شود (پچِ ۱۱۴)
    DECLARE @DupPerson NVARCHAR(220), @DupPersonActive BIT;
    SELECT TOP 1 @DupPerson = LTRIM(FirstName + N' ' + LastName), @DupPersonActive = IsActive
    FROM dbo.CrmPersons
    WHERE (@PersonID IS NULL OR PersonID <> @PersonID)
      AND dbo.fn_Crm_NormalizeName(FirstName + N' ' + LastName) = dbo.fn_Crm_NormalizeName(@FirstName + N' ' + @LastName)
    ORDER BY IsActive DESC, PersonID;
    IF @DupPerson IS NOT NULL AND ISNULL(@AllowDuplicateName, 0) = 0
       /* ویرایش بدونِ تغییرِ نام دوباره هشدار نمی‌دهد */
       AND (@PersonID IS NULL OR NOT EXISTS (SELECT 1 FROM dbo.CrmPersons WHERE PersonID = @PersonID AND dbo.fn_Crm_NormalizeName(FirstName + N' ' + LastName) = dbo.fn_Crm_NormalizeName(@FirstName + N' ' + @LastName)))
    BEGIN
        SELECT 0 AS Success, N'DUPLICATE_NAME' AS ErrorCode,
               N'مخاطبی با نامِ «' + @DupPerson + N'» از قبل ثبت شده است' + CASE WHEN @DupPersonActive = 0 THEN N' (غیرفعال)' ELSE N'' END + N'. آیا با همین نام ثبت شود؟' AS Message,
               (SELECT COUNT(*) FROM dbo.CrmPersons WHERE (@PersonID IS NULL OR PersonID <> @PersonID) AND dbo.fn_Crm_NormalizeName(FirstName + N' ' + LastName) = dbo.fn_Crm_NormalizeName(@FirstName + N' ' + @LastName)) AS DuplicateCount,
               (SELECT TOP 5 PersonID AS id, LTRIM(FirstName + N' ' + LastName) AS name, CAST(IsActive AS BIT) AS isActive FROM dbo.CrmPersons WHERE (@PersonID IS NULL OR PersonID <> @PersonID) AND dbo.fn_Crm_NormalizeName(FirstName + N' ' + LastName) = dbo.fn_Crm_NormalizeName(@FirstName + N' ' + @LastName) ORDER BY IsActive DESC, PersonID FOR JSON PATH) AS Duplicates;
        RETURN;
    END

    IF @PersonID IS NULL
    BEGIN
        INSERT INTO dbo.CrmPersons (FirstName, LastName, TitleID, IdentifierNumber, IdentifierDate, Description, IsActive, Date_InsertFirst, UserID_InsertFirst, CooperationEndedAt)
        VALUES (@FirstName, @LastName, @TitleID, @IdentifierNumber, @IdentifierDate, @Description, 1, SYSDATETIME(), @UserID, CASE WHEN @CooperationEnded = 1 THEN @CooperationEndedAt END);

        SELECT 1 AS Success, N'شخص ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS PersonID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmPersons WHERE PersonID = @PersonID)
        BEGIN SELECT 0 AS Success, N'شخص یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmPersons
        SET FirstName = @FirstName, LastName = @LastName, TitleID = @TitleID,
            IdentifierNumber = @IdentifierNumber, IdentifierDate = @IdentifierDate, Description = @Description,
            CooperationEndedAt = CASE WHEN @CooperationEnded IS NULL THEN CooperationEndedAt WHEN @CooperationEnded = 1 THEN @CooperationEndedAt END,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE PersonID = @PersonID;

        SELECT 1 AS Success, N'شخص ویرایش شد.' AS Message, @PersonID AS PersonID;
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
           p.DepartmentID, p.PartyTypeID, p.ActivityID, p.IsActive, p.RowGuid, p.CooperationEndedAt, -- پچِ ۱۱۹
           p.Date_InsertFirst, p.UserID_InsertFirst, p.Date_LastUpdate, p.UserID_LastUpdate,
           ISNULL(p.OfficialName, N'') AS DisplayName,
           d.DisplayName AS DepartmentName,
           pt.DisplayName AS PartyTypeName,
           a.DisplayName AS ActivityName,
           cu.FirstName + N' ' + cu.LastName AS CreatedByName,
           mu.FirstName + N' ' + mu.LastName AS ModifiedByName,
           (SELECT COUNT(*) FROM dbo.CrmPartyClassifications pc WHERE pc.PartyID = p.PartyID AND pc.IsActive = 1) AS ClassificationCount
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
           p.DepartmentID, p.PartyTypeID, p.ActivityID, p.IsActive, p.CooperationEndedAt, -- پچِ ۱۱۹
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
           pa1.ProvinceName AS PrimaryAddressProvince, pa1.CountyName AS PrimaryAddressCounty, pa1.CityName AS PrimaryAddressCity,
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
        SELECT TOP 1 gp.DisplayName AS ProvinceName, gco.DisplayName AS CountyName, gc.DisplayName AS CityName, ad.AddressText, adt.DisplayName AS TitleName
        FROM dbo.CrmAddresses ad
        LEFT JOIN dbo.CrmProvinces gp ON gp.ProvinceID = ad.ProvinceID
        LEFT JOIN dbo.CrmCounties gco ON gco.CountyID = ad.CountyID -- پچِ ۱۱۸
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

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetPerson
ALTER PROCEDURE dbo.sp_Crm_GetPerson
    @PersonID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT per.PersonID, per.FirstName, per.LastName, per.TitleID, per.IdentifierNumber, per.IdentifierDate,
           per.Description, per.IsActive, per.RowGuid, per.CooperationEndedAt, -- پچِ ۱۱۹
           LTRIM(per.FirstName + N' ' + per.LastName) AS DisplayName,
           t.DisplayName AS TitleName,
           per.Date_InsertFirst, per.UserID_InsertFirst, per.Date_LastUpdate, per.UserID_LastUpdate,
           cu.FirstName + N' ' + cu.LastName AS CreatedByName,
           mu.FirstName + N' ' + mu.LastName AS ModifiedByName
    FROM dbo.CrmPersons per
    LEFT JOIN dbo.CrmTitles t ON t.TitleID = per.TitleID
    LEFT JOIN dbo.Users cu ON cu.UserID = per.UserID_InsertFirst
    LEFT JOIN dbo.Users mu ON mu.UserID = per.UserID_LastUpdate
    WHERE per.PersonID = @PersonID;
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
           per.Description, per.IsActive, per.CooperationEndedAt, -- پچِ ۱۱۹
           LTRIM(per.FirstName + N' ' + per.LastName) AS DisplayName,
           ti.DisplayName AS TitleName,
           per.Date_InsertFirst, per.UserID_InsertFirst, per.Date_LastUpdate, per.UserID_LastUpdate,
           pc1.ContactValue AS PrimaryContactValue, pc1.Extension AS PrimaryContactExtension, pc1.TypeName AS PrimaryContactType,
           pa1.ProvinceName AS PrimaryAddressProvince, pa1.CityName AS PrimaryAddressCity,
           NULLIF(pa1.AddressText, N'') AS PrimaryAddressText, pa1.TitleName AS PrimaryAddressTitle,
           ( -- طرف‌حساب‌هایی که مخاطب با آن‌ها رابطهٔ فعال دارد
               SELECT STRING_AGG(rp.OfficialName, N'، ') WITHIN GROUP (ORDER BY r.IsPrimaryContact DESC, r.RelationID)
               FROM dbo.CrmPartyPersonRelations r
               JOIN dbo.CrmParties rp ON rp.PartyID = r.PartyID
               WHERE r.PersonID = per.PersonID AND r.IsActive = 1
           ) AS RelatedPartyNames
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
    -- آدرسِ اصلی برایِ نمایِ جدولی: اولین آدرسِ فعال (به ترتیبِ ثبت)
    OUTER APPLY (
        SELECT TOP 1 gp.DisplayName AS ProvinceName, gc.DisplayName AS CityName, ad.AddressText, adt.DisplayName AS TitleName
        FROM dbo.CrmAddresses ad
        LEFT JOIN dbo.CrmProvinces gp ON gp.ProvinceID = ad.ProvinceID
        LEFT JOIN dbo.CrmCities gc ON gc.CityID = ad.CityID
        LEFT JOIN dbo.CrmAddressTitles adt ON adt.AddressTitleID = ad.AddressTitleID
        WHERE ad.PersonID = per.PersonID AND ad.IsActive = 1
        ORDER BY ad.AddressID
    ) pa1
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR per.FirstName LIKE N'%' + @SearchText + N'%'
           OR per.LastName LIKE N'%' + @SearchText + N'%'
           OR LTRIM(per.FirstName + N' ' + per.LastName) LIKE N'%' + @SearchText + N'%'
           OR per.IdentifierNumber LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR per.IsActive = @IsActive)
    ORDER BY per.PersonID DESC;
END
GO

