/* ==========================================================================
   پچ خودکار شماره: 060 | نام: crm_party_brand_share_optional_category_active_unique
   تاریخ: 2026-09-25 16:39:37 | شامل 17 دستور SQL
   ========================================================================== */


-- [MANUAL] ایندکسِ یکتایِ فیلترشده (UX_CrmPartyBrandCategories_Active) به QUOTED_IDENTIFIER ON نیاز دارد (هم‌الگو با 049/050)
SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;
GO
-- [ALTER_TABLE] روی TABLE: CrmPartyBrandCategories
ALTER TABLE dbo.CrmPartyBrandCategories DROP CONSTRAINT FK_CrmPartyBrandCategories_Category
GO

-- [DROP_INDEX] روی INDEX: IX_CrmPartyBrandCategories_ProductCategoryID
DROP INDEX IX_CrmPartyBrandCategories_ProductCategoryID ON dbo.CrmPartyBrandCategories
GO

-- [ALTER_TABLE] روی TABLE: CrmPartyBrandCategories
ALTER TABLE dbo.CrmPartyBrandCategories ALTER COLUMN ProductCategoryID INT NULL
GO

-- [ALTER_TABLE] روی TABLE: CrmPartyBrandCategories
ALTER TABLE dbo.CrmPartyBrandCategories ADD CONSTRAINT FK_CrmPartyBrandCategories_Category FOREIGN KEY (ProductCategoryID) REFERENCES dbo.CrmProductCategories(ProductCategoryID)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmPartyBrandCategories_ProductCategoryID
CREATE INDEX IX_CrmPartyBrandCategories_ProductCategoryID ON dbo.CrmPartyBrandCategories(ProductCategoryID)
GO

-- [ALTER_TABLE] روی TABLE: CrmPartyBrandCategories
ALTER TABLE dbo.CrmPartyBrandCategories DROP CONSTRAINT CK_CrmPartyBrandCategories_Dates
GO

-- [ALTER_TABLE] روی TABLE: CrmPartyBrandCategories
ALTER TABLE dbo.CrmPartyBrandCategories ALTER COLUMN EntryDate DATE NULL
GO

-- [ALTER_TABLE] روی TABLE: CrmPartyBrandCategories
ALTER TABLE dbo.CrmPartyBrandCategories ADD CONSTRAINT CK_CrmPartyBrandCategories_Dates CHECK (ExitDate IS NULL OR EntryDate IS NULL OR ExitDate >= EntryDate)
GO

-- [ALTER_TABLE] روی TABLE: CrmPartyBrandCategories
ALTER TABLE dbo.CrmPartyBrandCategories ADD CONSTRAINT DF_CrmPartyBrandCategories_SharePercent DEFAULT (0) FOR SharePercent
GO

-- [CREATE_INDEX] روی INDEX: UX_CrmPartyBrandCategories_Active
CREATE UNIQUE INDEX UX_CrmPartyBrandCategories_Active ON dbo.CrmPartyBrandCategories(PartyBrandID, ProductCategoryID) WHERE IsActive = 1
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetPartyBrandCategories
-- ---------- 3. خواندن ----------
ALTER PROCEDURE dbo.sp_Crm_GetPartyBrandCategories
    @PartyID INT = NULL,
    @BrandID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    WITH Tree AS (
        SELECT c.ProductCategoryID, CAST(c.DisplayName AS NVARCHAR(MAX)) AS Path
        FROM dbo.CrmProductCategories c WHERE c.ParentCategoryID IS NULL
        UNION ALL
        SELECT c.ProductCategoryID, CAST(t.Path + N' / ' + c.DisplayName AS NVARCHAR(MAX))
        FROM dbo.CrmProductCategories c JOIN Tree t ON t.ProductCategoryID = c.ParentCategoryID
    )
    SELECT pbc.PartyBrandCategoryID, pbc.PartyBrandID, pb.PartyID, pb.BrandID, pbc.ProductCategoryID,
           pbc.EntryDate, pbc.ExitDate, pbc.SharePercent, pbc.IsActive,
           pbc.Date_InsertFirst, pbc.UserID_InsertFirst, pbc.Date_LastUpdate, pbc.UserID_LastUpdate,
           b.Name AS BrandName, b.IsActive AS BrandIsActive,
           c.DisplayName AS CategoryName, c.IsActive AS CategoryIsActive, t.Path AS CategoryPath,
           ISNULL(p.OfficialName, N'') AS PartyDisplayName, p.PartyNature
    FROM dbo.CrmPartyBrandCategories pbc
    JOIN dbo.CrmPartyBrands pb ON pb.PartyBrandID = pbc.PartyBrandID
    JOIN dbo.CrmBrands b ON b.BrandID = pb.BrandID
    JOIN dbo.CrmParties p ON p.PartyID = pb.PartyID
    LEFT JOIN dbo.CrmProductCategories c ON c.ProductCategoryID = pbc.ProductCategoryID
    LEFT JOIN Tree t ON t.ProductCategoryID = pbc.ProductCategoryID
    WHERE (@PartyID IS NOT NULL AND pb.PartyID = @PartyID)
       OR (@BrandID IS NOT NULL AND pb.BrandID = @BrandID)
    ORDER BY pbc.IsActive DESC, CASE WHEN t.Path IS NULL THEN 1 ELSE 0 END, t.Path, b.Name, pbc.PartyBrandCategoryID
    OPTION (MAXRECURSION 0);
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetBrandParties
/* طرف‌حساب‌هایی که برایشان حداقل یک ردیفِ فعال با این برند تعریف شده (صفحهٔ برند) */
CREATE PROCEDURE dbo.sp_Crm_GetBrandParties
    @BrandID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT p.PartyID, ISNULL(p.OfficialName, N'') AS PartyDisplayName, p.PartyNature, p.IsActive AS PartyIsActive,
           COUNT(*) AS ActiveRowCount
    FROM dbo.CrmPartyBrandCategories pbc
    JOIN dbo.CrmPartyBrands pb ON pb.PartyBrandID = pbc.PartyBrandID
    JOIN dbo.CrmParties p ON p.PartyID = pb.PartyID
    WHERE pb.BrandID = @BrandID AND pbc.IsActive = 1
    GROUP BY p.PartyID, p.OfficialName, p.PartyNature, p.IsActive
    ORDER BY p.OfficialName;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_CheckPartyBrandCategoryRules
-- ---------- 4. قواعد ----------
/*
  فقط داخلِ تراکنشِ فراخوان و بعد از sp_getapplock (سطحِ طرف‌حساب) صدا زده می‌شود؛ برایِ ردیفِ «فعال» اجرا می‌شود.
  ۱) یکتاییِ فعال: همان Party+Brand+Category (NULL = NULL) فقط یک ردیفِ فعال.
  ۲) سقفِ ۱۰۰٪ فقط وقتی Category مشخص است: در هیچ روزی مجموعِ ردیف‌هایِ فعالِ هم‌پوشانِ همان Party+Category > 100 نشود.
     بازه‌ها بسته‌اند؛ EntryDate خالی = از همیشه، ExitDate خالی = تا همیشه. بیشینه در یکی از نقاطِ شروع رخ می‌دهد.
*/
ALTER PROCEDURE dbo.sp_Crm_CheckPartyBrandCategoryRules
    @PartyID           INT,
    @PartyBrandID      INT,
    @ProductCategoryID INT           = NULL,
    @EntryDate         DATE          = NULL,
    @ExitDate          DATE          = NULL,
    @SharePercent      DECIMAL(5,2),
    @ExcludeID         INT           = NULL,
    @Message           NVARCHAR(400) OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET @Message = NULL;

    IF EXISTS (
        SELECT 1 FROM dbo.CrmPartyBrandCategories
        WHERE PartyBrandID = @PartyBrandID AND IsActive = 1
          AND PartyBrandCategoryID <> ISNULL(@ExcludeID, 0)
          AND ((ProductCategoryID IS NULL AND @ProductCategoryID IS NULL) OR ProductCategoryID = @ProductCategoryID))
    BEGIN
        SET @Message = CASE WHEN @ProductCategoryID IS NULL
            THEN N'این برند بدونِ دسته‌بندی قبلاً برایِ این طرف‌حساب به‌صورتِ فعال ثبت شده است.'
            ELSE N'این برند در این دسته‌بندی قبلاً برایِ این طرف‌حساب به‌صورتِ فعال ثبت شده است.' END;
        RETURN;
    END

    IF @ProductCategoryID IS NULL RETURN; -- ردیفِ بدونِ دسته مشمولِ سقفِ ۱۰۰٪ نیست

    DECLARE @MinDate DATE = '0001-01-01', @MaxDate DATE = '9999-12-31';
    DECLARE @E DATE = ISNULL(@EntryDate, @MinDate), @X DATE = ISNULL(@ExitDate, @MaxDate);
    DECLARE @Others TABLE (EntryDate DATE, ExitDate DATE, SharePercent DECIMAL(5,2));

    INSERT INTO @Others (EntryDate, ExitDate, SharePercent)
    SELECT ISNULL(pbc.EntryDate, @MinDate), ISNULL(pbc.ExitDate, @MaxDate), pbc.SharePercent
    FROM dbo.CrmPartyBrandCategories pbc
    JOIN dbo.CrmPartyBrands pb ON pb.PartyBrandID = pbc.PartyBrandID
    WHERE pb.PartyID = @PartyID
      AND pbc.ProductCategoryID = @ProductCategoryID
      AND pbc.IsActive = 1
      AND pbc.PartyBrandCategoryID <> ISNULL(@ExcludeID, 0)
      AND ISNULL(pbc.EntryDate, @MinDate) <= @X
      AND @E <= ISNULL(pbc.ExitDate, @MaxDate);

    DECLARE @PeakDate DATE, @PeakTotal DECIMAL(9,2);
    SELECT TOP 1 @PeakDate = pt.D, @PeakTotal = @SharePercent + ISNULL(SUM(o.SharePercent), 0)
    FROM (SELECT @E AS D UNION SELECT EntryDate FROM @Others WHERE EntryDate > @E) pt
    LEFT JOIN @Others o ON o.EntryDate <= pt.D AND o.ExitDate >= pt.D
    GROUP BY pt.D
    ORDER BY @SharePercent + ISNULL(SUM(o.SharePercent), 0) DESC, pt.D;

    IF @PeakTotal > 100
        SET @Message = N'مجموعِ درصدِ برندهایِ این طرف‌حساب در این دسته‌بندی '
                     + CASE WHEN @PeakDate = @MinDate THEN N'(از ابتدا)' ELSE N'از تاریخِ ' + CONVERT(NVARCHAR(10), @PeakDate, 23) END
                     + N' به ' + CAST(CAST(@PeakTotal AS DECIMAL(9,2)) AS NVARCHAR(20)) + N'٪ می‌رسد و نباید بیشتر از ۱۰۰٪ شود.';
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SavePartyBrandCategory
-- ---------- 5. ثبت/ویرایش: برند اجباری؛ دسته/تاریخ‌ها اختیاری؛ درصد پیش‌فرض ۰؛ در ویرایش هر پنج مقدار قابلِ تغییر ----------
ALTER PROCEDURE dbo.sp_Crm_SavePartyBrandCategory
    @PartyBrandCategoryID INT          = NULL,
    @PartyID              INT          = NULL,
    @BrandID              INT          = NULL,
    @ProductCategoryID    INT          = NULL,
    @EntryDate            DATE         = NULL,
    @ExitDate             DATE         = NULL,
    @SharePercent         DECIMAL(5,2) = 0,
    @UserID               INT
AS
BEGIN
    SET NOCOUNT ON;

    SET @SharePercent = ISNULL(@SharePercent, 0);
    IF @BrandID IS NULL
    BEGIN SELECT 0 AS Success, N'برند الزامی است.' AS Message; RETURN; END
    IF @EntryDate IS NOT NULL AND @ExitDate IS NOT NULL AND @ExitDate < @EntryDate
    BEGIN SELECT 0 AS Success, N'تاریخِ خروج نمی‌تواند قبل از تاریخِ ورود باشد.' AS Message; RETURN; END
    IF @SharePercent < 0 OR @SharePercent > 100
    BEGIN SELECT 0 AS Success, N'درصد باید بین ۰ تا ۱۰۰ باشد.' AS Message; RETURN; END

    DECLARE @IsActive BIT = 1, @CurBrandID INT, @CurCategoryID INT, @Exists BIT = 0;

    IF @PartyBrandCategoryID IS NOT NULL
    BEGIN
        SELECT @Exists = 1, @PartyID = pb.PartyID, @CurBrandID = pb.BrandID, @CurCategoryID = pbc.ProductCategoryID, @IsActive = pbc.IsActive
        FROM dbo.CrmPartyBrandCategories pbc JOIN dbo.CrmPartyBrands pb ON pb.PartyBrandID = pbc.PartyBrandID
        WHERE pbc.PartyBrandCategoryID = @PartyBrandCategoryID;
        IF @Exists = 0
        BEGIN SELECT 0 AS Success, N'ردیف یافت نشد.' AS Message; RETURN; END
    END
    ELSE IF NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حسابِ مربوطه یافت نشد.' AS Message; RETURN; END

    -- مقدارِ تازه (ایجاد یا تغییر در ویرایش) باید فعال باشد؛ مقدارِ فعلیِ بدون تغییر حتی اگر غیرفعال شده باشد مجاز است
    IF (@PartyBrandCategoryID IS NULL OR @BrandID <> @CurBrandID)
       AND NOT EXISTS (SELECT 1 FROM dbo.CrmBrands WHERE BrandID = @BrandID AND IsActive = 1)
    BEGIN SELECT 0 AS Success, N'برندِ انتخاب‌شده یافت نشد یا غیرفعال است.' AS Message; RETURN; END
    IF @ProductCategoryID IS NOT NULL
       AND (@PartyBrandCategoryID IS NULL OR ISNULL(@CurCategoryID, 0) <> @ProductCategoryID)
       AND NOT EXISTS (SELECT 1 FROM dbo.CrmProductCategories WHERE ProductCategoryID = @ProductCategoryID AND IsActive = 1)
    BEGIN SELECT 0 AS Success, N'دسته‌بندیِ انتخاب‌شده یافت نشد یا غیرفعال است.' AS Message; RETURN; END

    DECLARE @Resource NVARCHAR(255) = N'CrmPartyBrandCategory:' + CAST(@PartyID AS NVARCHAR(20));
    DECLARE @Msg NVARCHAR(400), @Lock INT, @PartyBrandID INT, @NewID INT;

    BEGIN TRY
        BEGIN TRAN;

        EXEC @Lock = sp_getapplock @Resource = @Resource, @LockMode = 'Exclusive', @LockOwner = 'Transaction', @LockTimeout = 10000;
        IF @Lock < 0
        BEGIN
            COMMIT TRAN; -- هیچ نوشتنی انجام نشده؛ فقط قفل آزاد می‌شود (ROLLBACK تراکنشِ بیرونیِ فراخوان را هم برمی‌گرداند)
            SELECT 0 AS Success, N'سامانه مشغول است؛ لطفاً دوباره تلاش کنید.' AS Message; RETURN;
        END

        SELECT @PartyBrandID = PartyBrandID FROM dbo.CrmPartyBrands WHERE PartyID = @PartyID AND BrandID = @BrandID;

        -- ردیفِ غیرفعال در قواعد شمرده نمی‌شود؛ قواعد هنگامِ فعال‌سازیِ دوباره بررسی می‌شوند
        IF @IsActive = 1
        BEGIN
            EXEC dbo.sp_Crm_CheckPartyBrandCategoryRules @PartyID, @PartyBrandID, @ProductCategoryID, @EntryDate, @ExitDate, @SharePercent, @PartyBrandCategoryID, @Msg OUTPUT;
            IF @Msg IS NOT NULL
            BEGIN
                COMMIT TRAN; -- هیچ نوشتنی انجام نشده؛ فقط قفل آزاد می‌شود
                SELECT 0 AS Success, @Msg AS Message; RETURN;
            END
        END

        -- ارتباطِ داخلیِ Party↔Brand: اگر نبود ساخته، اگر غیرفعال بود (برایِ ردیفِ فعال) فعال می‌شود؛ ارتباطِ قبلی هرگز حذف نمی‌شود
        IF @PartyBrandID IS NULL
        BEGIN
            INSERT INTO dbo.CrmPartyBrands (PartyID, BrandID, IsActive, Date_InsertFirst, UserID_InsertFirst)
            VALUES (@PartyID, @BrandID, 1, SYSDATETIME(), @UserID);
            SET @PartyBrandID = CAST(SCOPE_IDENTITY() AS INT);
        END
        ELSE IF @IsActive = 1
            UPDATE dbo.CrmPartyBrands SET IsActive = 1, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
            WHERE PartyBrandID = @PartyBrandID AND IsActive = 0;

        IF @PartyBrandCategoryID IS NULL
        BEGIN
            INSERT INTO dbo.CrmPartyBrandCategories (PartyBrandID, ProductCategoryID, EntryDate, ExitDate, SharePercent, IsActive, Date_InsertFirst, UserID_InsertFirst)
            VALUES (@PartyBrandID, @ProductCategoryID, @EntryDate, @ExitDate, @SharePercent, 1, SYSDATETIME(), @UserID);
            SET @NewID = CAST(SCOPE_IDENTITY() AS INT);

            COMMIT TRAN;
            SELECT 1 AS Success, N'برند برایِ طرف‌حساب ثبت شد.' AS Message, @NewID AS PartyBrandCategoryID;
        END
        ELSE
        BEGIN
            UPDATE dbo.CrmPartyBrandCategories
            SET PartyBrandID = @PartyBrandID, ProductCategoryID = @ProductCategoryID,
                EntryDate = @EntryDate, ExitDate = @ExitDate, SharePercent = @SharePercent,
                Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
            WHERE PartyBrandCategoryID = @PartyBrandCategoryID;

            COMMIT TRAN;
            SELECT 1 AS Success, N'ردیف ویرایش شد.' AS Message, @PartyBrandCategoryID AS PartyBrandCategoryID;
        END
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRAN;
        SELECT 0 AS Success, N'خطا در ذخیره: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_TogglePartyBrandCategoryActive
-- ---------- 6. فعال/غیرفعال: فعال‌سازیِ ردیفِ تاریخی فقط اگر ردیفِ فعالِ دیگری از همان ترکیب نباشد و سقف رعایت شود ----------
ALTER PROCEDURE dbo.sp_Crm_TogglePartyBrandCategoryActive
    @PartyBrandCategoryID INT,
    @UserID               INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT, @PartyID INT, @PartyBrandID INT, @CategoryID INT, @EntryDate DATE, @ExitDate DATE, @Percent DECIMAL(5,2),
            @BrandActive BIT, @CategoryActive BIT;

    SELECT @Current = pbc.IsActive, @PartyID = pb.PartyID, @PartyBrandID = pbc.PartyBrandID, @CategoryID = pbc.ProductCategoryID,
           @EntryDate = pbc.EntryDate, @ExitDate = pbc.ExitDate, @Percent = pbc.SharePercent,
           @BrandActive = b.IsActive, @CategoryActive = c.IsActive
    FROM dbo.CrmPartyBrandCategories pbc
    JOIN dbo.CrmPartyBrands pb ON pb.PartyBrandID = pbc.PartyBrandID
    JOIN dbo.CrmBrands b ON b.BrandID = pb.BrandID
    LEFT JOIN dbo.CrmProductCategories c ON c.ProductCategoryID = pbc.ProductCategoryID
    WHERE pbc.PartyBrandCategoryID = @PartyBrandCategoryID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'ردیف یافت نشد.' AS Message; RETURN; END
    IF @Current = 0 AND @BrandActive = 0
    BEGIN SELECT 0 AS Success, N'برند غیرفعال است؛ ابتدا برند را فعال کنید.' AS Message; RETURN; END
    IF @Current = 0 AND @CategoryID IS NOT NULL AND @CategoryActive = 0
    BEGIN SELECT 0 AS Success, N'دسته‌بندی غیرفعال است؛ ابتدا دسته‌بندی را فعال کنید.' AS Message; RETURN; END

    DECLARE @Resource NVARCHAR(255) = N'CrmPartyBrandCategory:' + CAST(@PartyID AS NVARCHAR(20));
    DECLARE @Msg NVARCHAR(400), @Lock INT;

    BEGIN TRY
        BEGIN TRAN;

        EXEC @Lock = sp_getapplock @Resource = @Resource, @LockMode = 'Exclusive', @LockOwner = 'Transaction', @LockTimeout = 10000;
        IF @Lock < 0
        BEGIN
            COMMIT TRAN;
            SELECT 0 AS Success, N'سامانه مشغول است؛ لطفاً دوباره تلاش کنید.' AS Message; RETURN;
        END

        IF @Current = 0
        BEGIN
            EXEC dbo.sp_Crm_CheckPartyBrandCategoryRules @PartyID, @PartyBrandID, @CategoryID, @EntryDate, @ExitDate, @Percent, @PartyBrandCategoryID, @Msg OUTPUT;
            IF @Msg IS NOT NULL
            BEGIN
                COMMIT TRAN;
                SELECT 0 AS Success, @Msg AS Message; RETURN;
            END

            UPDATE dbo.CrmPartyBrands SET IsActive = 1, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
            WHERE PartyBrandID = @PartyBrandID AND IsActive = 0;
        END

        UPDATE dbo.CrmPartyBrandCategories
        SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE PartyBrandCategoryID = @PartyBrandCategoryID;

        COMMIT TRAN;
        SELECT 1 AS Success,
               CASE WHEN @Current = 1 THEN N'ردیف غیرفعال شد.' ELSE N'ردیف فعال شد.' END AS Message;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRAN;
        SELECT 0 AS Success, N'خطا در تغییرِ وضعیت: ' + ERROR_MESSAGE() AS Message;
    END CATCH
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

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetBrands
ALTER PROCEDURE dbo.sp_Crm_GetBrands
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT b.BrandID, b.Name, b.Name AS DisplayName, b.Description, b.IsActive,
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


/* ===== [MANUAL DML] (گزینهٔ B — حفظِ وضعیتِ ارتباط) هر ارتباطِ Party↔Brand که ردیفِ فعالی در CrmPartyBrandCategories ندارد،
   یک ردیفِ «برند بدونِ دسته، ۰٪، بدونِ تاریخ» می‌گیرد تا در گریدِ تبِ برندها دیده شود؛ وضعیتِ ردیف = وضعیتِ ارتباط
   (ارتباطِ غیرفعال → ردیفِ تاریخیِ غیرفعال، تا تصمیمِ قبلیِ غیرفعال‌سازی حفظ شود). Idempotent. ===== */
SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;
INSERT INTO dbo.CrmPartyBrandCategories (PartyBrandID, ProductCategoryID, EntryDate, ExitDate, SharePercent, IsActive, Date_InsertFirst, UserID_InsertFirst)
SELECT pb.PartyBrandID, NULL, NULL, NULL, 0, pb.IsActive, SYSDATETIME(), NULL
FROM dbo.CrmPartyBrands pb
WHERE NOT EXISTS (SELECT 1 FROM dbo.CrmPartyBrandCategories x WHERE x.PartyBrandID = pb.PartyBrandID AND x.IsActive = 1)
  AND NOT EXISTS (SELECT 1 FROM dbo.CrmPartyBrandCategories x WHERE x.PartyBrandID = pb.PartyBrandID AND x.ProductCategoryID IS NULL);
GO
