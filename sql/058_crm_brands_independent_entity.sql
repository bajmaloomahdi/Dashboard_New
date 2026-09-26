/* ==========================================================================
   پچ خودکار شماره: 058 | نام: crm_brands_independent_entity
   تاریخ: 2026-09-25 15:15:20 | شامل 12 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: CrmBrands
ALTER TABLE dbo.CrmBrands DROP CONSTRAINT FK_CrmBrands_Party
GO

-- [DROP_INDEX] روی INDEX: IX_CrmBrands_PartyID
DROP INDEX IX_CrmBrands_PartyID ON dbo.CrmBrands
GO

-- [ALTER_TABLE] روی TABLE: CrmBrands
ALTER TABLE dbo.CrmBrands DROP COLUMN PartyID
GO

-- [ALTER_TABLE] روی TABLE: CrmBrands
ALTER TABLE dbo.CrmBrands ADD CONSTRAINT UQ_CrmBrands_Name UNIQUE (Name)
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetBrands
-- ---------- Brand SPs ----------
ALTER PROCEDURE dbo.sp_Crm_GetBrands
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT b.BrandID, b.Name, b.Name AS DisplayName, b.Description, b.IsActive,
           b.Date_InsertFirst, b.UserID_InsertFirst, b.Date_LastUpdate, b.UserID_LastUpdate,
           (SELECT COUNT(*) FROM dbo.CrmPartyBrands pb WHERE pb.BrandID = b.BrandID AND pb.IsActive = 1) AS ActivePartyCount
    FROM dbo.CrmBrands b
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR b.Name LIKE N'%' + @SearchText + N'%'
           OR b.Description LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR b.IsActive = @IsActive)
    ORDER BY b.Name;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetBrand
CREATE PROCEDURE dbo.sp_Crm_GetBrand
    @BrandID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT b.BrandID, b.Name, b.Name AS DisplayName, b.Description, b.IsActive, b.RowGuid,
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
ALTER PROCEDURE dbo.sp_Crm_SaveBrand
    @BrandID     INT           = NULL,
    @Name        NVARCHAR(200),
    @Description NVARCHAR(MAX) = NULL,
    @UserID      INT
AS
BEGIN
    SET NOCOUNT ON;

    SET @Name = LTRIM(RTRIM(@Name));
    IF NULLIF(@Name, N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ برند الزامی است.' AS Message; RETURN; END
    IF EXISTS (SELECT 1 FROM dbo.CrmBrands WHERE Name = @Name AND BrandID <> ISNULL(@BrandID, 0))
    BEGIN SELECT 0 AS Success, N'برندی با این نام قبلاً ثبت شده است.' AS Message; RETURN; END

    IF @BrandID IS NULL
    BEGIN
        INSERT INTO dbo.CrmBrands (Name, Description, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@Name, @Description, 1, SYSDATETIME(), @UserID);

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

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetPartyBrands
-- ---------- Party ↔ Brand SPs ----------
CREATE PROCEDURE dbo.sp_Crm_GetPartyBrands
    @PartyID INT = NULL,
    @BrandID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT pb.PartyBrandID, pb.PartyID, pb.BrandID, pb.IsActive,
           pb.Date_InsertFirst, pb.UserID_InsertFirst, pb.Date_LastUpdate, pb.UserID_LastUpdate,
           b.Name AS BrandName, b.Description AS BrandDescription, b.IsActive AS BrandIsActive,
           ISNULL(p.OfficialName, N'') AS PartyDisplayName, p.PartyNature, p.IsActive AS PartyIsActive
    FROM dbo.CrmPartyBrands pb
    JOIN dbo.CrmBrands b ON b.BrandID = pb.BrandID
    JOIN dbo.CrmParties p ON p.PartyID = pb.PartyID
    WHERE (@PartyID IS NOT NULL AND pb.PartyID = @PartyID)
       OR (@BrandID IS NOT NULL AND pb.BrandID = @BrandID)
    ORDER BY pb.IsActive DESC, b.Name, p.OfficialName;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SavePartyBrand
CREATE PROCEDURE dbo.sp_Crm_SavePartyBrand
    @PartyID INT,
    @BrandID INT,
    @UserID  INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حسابِ مربوطه یافت نشد.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.CrmBrands WHERE BrandID = @BrandID AND IsActive = 1)
    BEGIN SELECT 0 AS Success, N'برندِ انتخاب‌شده یافت نشد یا غیرفعال است.' AS Message; RETURN; END

    DECLARE @LinkID INT, @LinkActive BIT;
    SELECT @LinkID = PartyBrandID, @LinkActive = IsActive FROM dbo.CrmPartyBrands WHERE PartyID = @PartyID AND BrandID = @BrandID;

    IF @LinkID IS NOT NULL AND @LinkActive = 1
    BEGIN SELECT 0 AS Success, N'این برند قبلاً به این طرف‌حساب مرتبط شده است.' AS Message; RETURN; END

    IF @LinkID IS NOT NULL
    BEGIN
        UPDATE dbo.CrmPartyBrands
        SET IsActive = 1, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE PartyBrandID = @LinkID;

        SELECT 1 AS Success, N'ارتباطِ برند دوباره فعال شد.' AS Message, @LinkID AS PartyBrandID;
        RETURN;
    END

    INSERT INTO dbo.CrmPartyBrands (PartyID, BrandID, IsActive, Date_InsertFirst, UserID_InsertFirst)
    VALUES (@PartyID, @BrandID, 1, SYSDATETIME(), @UserID);

    SELECT 1 AS Success, N'برند به طرف‌حساب مرتبط شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS PartyBrandID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_TogglePartyBrandActive
CREATE PROCEDURE dbo.sp_Crm_TogglePartyBrandActive
    @PartyBrandID INT,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT, @BrandActive BIT;
    SELECT @Current = pb.IsActive, @BrandActive = b.IsActive
    FROM dbo.CrmPartyBrands pb JOIN dbo.CrmBrands b ON b.BrandID = pb.BrandID
    WHERE pb.PartyBrandID = @PartyBrandID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'ارتباط یافت نشد.' AS Message; RETURN; END
    IF @Current = 0 AND @BrandActive = 0
    BEGIN SELECT 0 AS Success, N'برند غیرفعال است؛ ابتدا برند را فعال کنید.' AS Message; RETURN; END

    UPDATE dbo.CrmPartyBrands
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE PartyBrandID = @PartyBrandID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'ارتباطِ برند غیرفعال شد.' ELSE N'ارتباطِ برند فعال شد.' END AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SyncPartyBrands
-- مجموعهٔ برندهایِ فعالِ یک طرف‌حساب را با فهرستِ داده‌شده هم‌سان می‌کند (هم‌الگو با sp_Crm_SaveRelationRoles)؛
-- حذفِ فیزیکی ندارد: ارتباطِ انتخاب‌نشده غیرفعال، و ارتباطِ غیرفعالِ انتخاب‌شده دوباره فعال می‌شود.
CREATE PROCEDURE dbo.sp_Crm_SyncPartyBrands
    @PartyID     INT,
    @BrandIDsCsv NVARCHAR(MAX) = NULL,
    @UserID      INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حسابِ مربوطه یافت نشد.' AS Message; RETURN; END

    DECLARE @Brands TABLE (BrandID INT PRIMARY KEY);
    INSERT INTO @Brands (BrandID)
    SELECT DISTINCT CAST(value AS INT) FROM STRING_SPLIT(ISNULL(@BrandIDsCsv, N''), ',') WHERE NULLIF(LTRIM(RTRIM(value)), N'') IS NOT NULL;

    -- برندِ غیرفعال فقط وقتی مجاز است که همین حالا ارتباطِ فعال داشته باشد (انتخابِ قبلی حفظ شود، انتخابِ تازه نه)
    IF EXISTS (
        SELECT 1 FROM @Brands s
        LEFT JOIN dbo.CrmBrands b ON b.BrandID = s.BrandID
        WHERE b.BrandID IS NULL
           OR (b.IsActive = 0 AND NOT EXISTS (
                SELECT 1 FROM dbo.CrmPartyBrands pb WHERE pb.PartyID = @PartyID AND pb.BrandID = s.BrandID AND pb.IsActive = 1))
    )
    BEGIN SELECT 0 AS Success, N'یک یا چند برندِ انتخاب‌شده نامعتبر یا غیرفعال است.' AS Message; RETURN; END

    BEGIN TRY
        BEGIN TRAN;

        UPDATE dbo.CrmPartyBrands
        SET IsActive = 0, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE PartyID = @PartyID AND IsActive = 1 AND BrandID NOT IN (SELECT BrandID FROM @Brands);

        UPDATE pb
        SET IsActive = 1, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        FROM dbo.CrmPartyBrands pb
        JOIN @Brands s ON s.BrandID = pb.BrandID
        WHERE pb.PartyID = @PartyID AND pb.IsActive = 0;

        INSERT INTO dbo.CrmPartyBrands (PartyID, BrandID, IsActive, Date_InsertFirst, UserID_InsertFirst)
        SELECT @PartyID, s.BrandID, 1, SYSDATETIME(), @UserID
        FROM @Brands s
        WHERE NOT EXISTS (SELECT 1 FROM dbo.CrmPartyBrands pb WHERE pb.PartyID = @PartyID AND pb.BrandID = s.BrandID);

        COMMIT TRAN;
        SELECT 1 AS Success, N'برندهایِ طرف‌حساب به‌روزرسانی شد.' AS Message;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRAN;
        SELECT 0 AS Success, N'خطا در ذخیرهٔ برندها: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetParties
-- ---------- Party list: brand count from the link table ----------
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
           (SELECT COUNT(*) FROM dbo.CrmPartyBrands pb JOIN dbo.CrmBrands b ON b.BrandID = pb.BrandID
             WHERE pb.PartyID = p.PartyID AND pb.IsActive = 1 AND b.IsActive = 1) AS ActiveBrandCount,
           (SELECT COUNT(*) FROM dbo.CrmAddresses ad WHERE ad.PartyID = p.PartyID AND ad.IsActive = 1) AS ActiveAddressCount,
           (SELECT COUNT(*) FROM dbo.CrmContacts c WHERE c.PartyID = p.PartyID AND c.IsActive = 1) AS ActiveContactCount,
           (SELECT COUNT(*) FROM dbo.CrmPartyClassifications pc WHERE pc.PartyID = p.PartyID AND pc.IsActive = 1) AS ClassificationCount
    FROM dbo.CrmParties p
    LEFT JOIN dbo.CrmDepartments d ON d.DepartmentID = p.DepartmentID
    LEFT JOIN dbo.CrmPartyTypes pt ON pt.PartyTypeID = p.PartyTypeID
    LEFT JOIN dbo.CrmActivities a ON a.ActivityID = p.ActivityID
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


/* ===== [MANUAL DML] منویِ صفحهٔ مستقلِ «برندها» زیرِ پوشهٔ CRM — DML است و توسطِ DDL-trigger ثبت نمی‌شود،
   پس دستی به انتهایِ این پچ اضافه شد (هم‌الگو با 045 مخاطبین). Idempotent با چکِ Url. ===== */
IF NOT EXISTS (SELECT 1 FROM dbo.Menu WHERE Url = N'/crm/brands-page')
BEGIN
    DECLARE @CrmFolderIDBrands INT;
    SELECT @CrmFolderIDBrands = MenuID FROM dbo.Menu WHERE MenuTitle = N'CRM' AND ParentID IS NULL;
    IF @CrmFolderIDBrands IS NOT NULL
        EXEC dbo.sp_InsertMenu @ParentID = @CrmFolderIDBrands, @MenuTitle = N'برندها', @MenuKind = N'PAGE', @Url = N'/crm/brands-page', @SortOrder = 5;

    EXEC dbo.sp_GrantAllMenusToRole @RoleID = 1;
END
GO
