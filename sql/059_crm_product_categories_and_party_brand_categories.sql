/* ==========================================================================
   پچ خودکار شماره: 059 | نام: crm_product_categories_and_party_brand_categories
   تاریخ: 2026-09-25 15:48:26 | شامل 12 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: CrmProductCategories
CREATE TABLE dbo.CrmProductCategories
(
    ProductCategoryID INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmProductCategories PRIMARY KEY,
    ParentCategoryID  INT NULL,
    Code              NVARCHAR(64)  NOT NULL,
    DisplayName       NVARCHAR(200) NOT NULL,
    SortOrder         INT NOT NULL CONSTRAINT DF_CrmProductCategories_SortOrder DEFAULT (0),
    IsActive          BIT NOT NULL CONSTRAINT DF_CrmProductCategories_IsActive DEFAULT (1),
    RowGuid           UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmProductCategories_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmProductCategories_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate     DATETIME2 NULL,
    UserID_LastUpdate   INT NULL,
    CONSTRAINT UQ_CrmProductCategories_Code UNIQUE (Code),
    CONSTRAINT UQ_CrmProductCategories_Parent_Name UNIQUE (ParentCategoryID, DisplayName),
    CONSTRAINT FK_CrmProductCategories_Parent FOREIGN KEY (ParentCategoryID) REFERENCES dbo.CrmProductCategories(ProductCategoryID),
    CONSTRAINT CK_CrmProductCategories_NotSelfParent CHECK (ParentCategoryID IS NULL OR ParentCategoryID <> ProductCategoryID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmProductCategories_ParentCategoryID
CREATE INDEX IX_CrmProductCategories_ParentCategoryID ON dbo.CrmProductCategories(ParentCategoryID)
GO

-- [CREATE_TABLE] روی TABLE: CrmPartyBrandCategories
CREATE TABLE dbo.CrmPartyBrandCategories
(
    PartyBrandCategoryID INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmPartyBrandCategories PRIMARY KEY,
    PartyBrandID         INT NOT NULL,
    ProductCategoryID    INT NOT NULL,
    EntryDate            DATE NOT NULL,
    ExitDate             DATE NULL,
    SharePercent         DECIMAL(5,2) NOT NULL,
    IsActive             BIT NOT NULL CONSTRAINT DF_CrmPartyBrandCategories_IsActive DEFAULT (1),
    RowGuid              UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmPartyBrandCategories_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmPartyBrandCategories_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate     DATETIME2 NULL,
    UserID_LastUpdate   INT NULL,
    CONSTRAINT FK_CrmPartyBrandCategories_PartyBrand FOREIGN KEY (PartyBrandID) REFERENCES dbo.CrmPartyBrands(PartyBrandID),
    CONSTRAINT FK_CrmPartyBrandCategories_Category FOREIGN KEY (ProductCategoryID) REFERENCES dbo.CrmProductCategories(ProductCategoryID),
    CONSTRAINT CK_CrmPartyBrandCategories_Percent CHECK (SharePercent >= 0 AND SharePercent <= 100),
    CONSTRAINT CK_CrmPartyBrandCategories_Dates CHECK (ExitDate IS NULL OR ExitDate >= EntryDate)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmPartyBrandCategories_PartyBrandID
CREATE INDEX IX_CrmPartyBrandCategories_PartyBrandID ON dbo.CrmPartyBrandCategories(PartyBrandID)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmPartyBrandCategories_ProductCategoryID
CREATE INDEX IX_CrmPartyBrandCategories_ProductCategoryID ON dbo.CrmPartyBrandCategories(ProductCategoryID)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetProductCategories
-- ---------- 3. SPهایِ دسته‌بندی ----------
CREATE PROCEDURE dbo.sp_Crm_GetProductCategories
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    WITH Tree AS (
        SELECT c.ProductCategoryID, CAST(c.DisplayName AS NVARCHAR(MAX)) AS Path, 0 AS Level
        FROM dbo.CrmProductCategories c
        WHERE c.ParentCategoryID IS NULL
        UNION ALL
        SELECT c.ProductCategoryID, CAST(t.Path + N' / ' + c.DisplayName AS NVARCHAR(MAX)), t.Level + 1
        FROM dbo.CrmProductCategories c
        JOIN Tree t ON t.ProductCategoryID = c.ParentCategoryID
    )
    SELECT c.ProductCategoryID, c.ParentCategoryID, c.Code, c.DisplayName, c.SortOrder, c.IsActive,
           c.Date_InsertFirst, c.UserID_InsertFirst, c.Date_LastUpdate, c.UserID_LastUpdate,
           p.DisplayName AS ParentName, t.Path, t.Level,
           (SELECT COUNT(*) FROM dbo.CrmProductCategories ch WHERE ch.ParentCategoryID = c.ProductCategoryID) AS ChildCount,
           (SELECT COUNT(*) FROM dbo.CrmProductCategories ch WHERE ch.ParentCategoryID = c.ProductCategoryID AND ch.IsActive = 1) AS ActiveChildCount,
           (SELECT COUNT(*) FROM dbo.CrmPartyBrandCategories pbc WHERE pbc.ProductCategoryID = c.ProductCategoryID AND pbc.IsActive = 1) AS ActiveUsageCount
    FROM dbo.CrmProductCategories c
    JOIN Tree t ON t.ProductCategoryID = c.ProductCategoryID
    LEFT JOIN dbo.CrmProductCategories p ON p.ProductCategoryID = c.ParentCategoryID
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR c.DisplayName LIKE N'%' + @SearchText + N'%'
           OR c.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR c.IsActive = @IsActive)
    ORDER BY t.Path
    OPTION (MAXRECURSION 0);
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SaveProductCategory
CREATE PROCEDURE dbo.sp_Crm_SaveProductCategory
    @ProductCategoryID INT           = NULL,
    @ParentCategoryID  INT           = NULL,
    @DisplayName       NVARCHAR(200),
    @SortOrder         INT           = 0,
    @UserID            INT
AS
BEGIN
    SET NOCOUNT ON;

    SET @DisplayName = LTRIM(RTRIM(@DisplayName));
    IF NULLIF(@DisplayName, N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ دسته‌بندی الزامی است.' AS Message; RETURN; END

    DECLARE @CurrentParent INT, @Exists BIT = 0;
    IF @ProductCategoryID IS NOT NULL
    BEGIN
        SELECT @Exists = 1, @CurrentParent = ParentCategoryID FROM dbo.CrmProductCategories WHERE ProductCategoryID = @ProductCategoryID;
        IF @Exists = 0
        BEGIN SELECT 0 AS Success, N'دسته‌بندی یافت نشد.' AS Message; RETURN; END
    END

    IF @ParentCategoryID IS NOT NULL
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmProductCategories WHERE ProductCategoryID = @ParentCategoryID)
        BEGIN SELECT 0 AS Success, N'دستهٔ والد یافت نشد.' AS Message; RETURN; END

        -- والدِ جدید (در ایجاد یا تغییرِ والد) باید فعال باشد
        IF (@ProductCategoryID IS NULL OR ISNULL(@CurrentParent, 0) <> @ParentCategoryID)
           AND EXISTS (SELECT 1 FROM dbo.CrmProductCategories WHERE ProductCategoryID = @ParentCategoryID AND IsActive = 0)
        BEGIN SELECT 0 AS Success, N'زیرِ دستهٔ غیرفعال نمی‌توان دسته تعریف یا منتقل کرد.' AS Message; RETURN; END

        -- جلوگیری از حلقه: والدِ جدید نباید خودِ دسته یا یکی از زیرمجموعه‌هایش باشد
        IF @ProductCategoryID IS NOT NULL
        BEGIN
            ;WITH Sub AS (
                SELECT ProductCategoryID FROM dbo.CrmProductCategories WHERE ProductCategoryID = @ProductCategoryID
                UNION ALL
                SELECT c.ProductCategoryID FROM dbo.CrmProductCategories c JOIN Sub s ON c.ParentCategoryID = s.ProductCategoryID
            )
            SELECT @Exists = 1 FROM Sub WHERE ProductCategoryID = @ParentCategoryID OPTION (MAXRECURSION 0);
            IF @@ROWCOUNT > 0
            BEGIN SELECT 0 AS Success, N'دسته را نمی‌توان زیرِ خودش یا یکی از زیرمجموعه‌هایش قرار داد.' AS Message; RETURN; END
        END
    END

    IF EXISTS (SELECT 1 FROM dbo.CrmProductCategories
               WHERE DisplayName = @DisplayName
                 AND ((ParentCategoryID IS NULL AND @ParentCategoryID IS NULL) OR ParentCategoryID = @ParentCategoryID)
                 AND ProductCategoryID <> ISNULL(@ProductCategoryID, 0))
    BEGIN SELECT 0 AS Success, N'دسته‌ای با این نام زیرِ همین والد وجود دارد.' AS Message; RETURN; END

    IF @ProductCategoryID IS NULL
    BEGIN
        DECLARE @NextCode INT;
        SELECT @NextCode = ISNULL(MAX(CAST(Code AS INT)), 100) + 1 FROM dbo.CrmProductCategories WHERE ISNUMERIC(Code) = 1;
        IF @NextCode < 101 SET @NextCode = 101;

        INSERT INTO dbo.CrmProductCategories (ParentCategoryID, Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@ParentCategoryID, CAST(@NextCode AS NVARCHAR(64)), @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'دسته‌بندی ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS ProductCategoryID;
    END
    ELSE
    BEGIN
        UPDATE dbo.CrmProductCategories
        SET ParentCategoryID = @ParentCategoryID, DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE ProductCategoryID = @ProductCategoryID;

        SELECT 1 AS Success, N'دسته‌بندی ویرایش شد.' AS Message, @ProductCategoryID AS ProductCategoryID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_ToggleProductCategoryActive
CREATE PROCEDURE dbo.sp_Crm_ToggleProductCategoryActive
    @ProductCategoryID INT,
    @UserID            INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT, @ParentID INT;
    SELECT @Current = IsActive, @ParentID = ParentCategoryID FROM dbo.CrmProductCategories WHERE ProductCategoryID = @ProductCategoryID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'دسته‌بندی یافت نشد.' AS Message; RETURN; END

    IF @Current = 1 AND EXISTS (SELECT 1 FROM dbo.CrmProductCategories WHERE ParentCategoryID = @ProductCategoryID AND IsActive = 1)
    BEGIN SELECT 0 AS Success, N'این دسته دارایِ زیرمجموعهٔ فعال است؛ ابتدا آن‌ها را غیرفعال کنید.' AS Message; RETURN; END

    IF @Current = 0 AND @ParentID IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.CrmProductCategories WHERE ProductCategoryID = @ParentID AND IsActive = 0)
    BEGIN SELECT 0 AS Success, N'دستهٔ والد غیرفعال است؛ ابتدا والد را فعال کنید.' AS Message; RETURN; END

    UPDATE dbo.CrmProductCategories
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE ProductCategoryID = @ProductCategoryID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'دسته‌بندی غیرفعال شد.' ELSE N'دسته‌بندی فعال شد.' END AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetPartyBrandCategories
-- ---------- 4. SPهایِ دسته/تاریخ/درصدِ ارتباطِ Party↔Brand ----------
CREATE PROCEDURE dbo.sp_Crm_GetPartyBrandCategories
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
           b.Name AS BrandName, b.IsActive AS BrandIsActive, pb.IsActive AS PartyBrandIsActive,
           c.DisplayName AS CategoryName, c.IsActive AS CategoryIsActive, t.Path AS CategoryPath,
           ISNULL(p.OfficialName, N'') AS PartyDisplayName, p.PartyNature
    FROM dbo.CrmPartyBrandCategories pbc
    JOIN dbo.CrmPartyBrands pb ON pb.PartyBrandID = pbc.PartyBrandID
    JOIN dbo.CrmBrands b ON b.BrandID = pb.BrandID
    JOIN dbo.CrmParties p ON p.PartyID = pb.PartyID
    JOIN dbo.CrmProductCategories c ON c.ProductCategoryID = pbc.ProductCategoryID
    JOIN Tree t ON t.ProductCategoryID = pbc.ProductCategoryID
    WHERE (@PartyID IS NOT NULL AND pb.PartyID = @PartyID)
       OR (@BrandID IS NOT NULL AND pb.BrandID = @BrandID)
    ORDER BY t.Path, pbc.IsActive DESC, pbc.EntryDate, b.Name
    OPTION (MAXRECURSION 0);
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_CheckPartyBrandCategoryRules
/*
  قواعدِ مشترکِ ثبت/ویرایش/فعال‌سازیِ یک ردیف — فقط داخلِ تراکنشِ فراخوان و بعد از sp_getapplock صدا زده می‌شود.
  بازه‌ها بسته‌اند: [EntryDate, ExitDate]؛ ExitDate خالی = ادامه‌دار. ردیف‌هایِ IsActive = 1 شمرده می‌شوند.
  بیشینهٔ مجموعِ درصد در یک بازه همیشه در یکی از تاریخ‌هایِ شروع رخ می‌دهد، پس فقط همان نقاط داخلِ بازهٔ ردیف بررسی می‌شوند.
*/
CREATE PROCEDURE dbo.sp_Crm_CheckPartyBrandCategoryRules
    @PartyID           INT,
    @BrandID           INT,
    @ProductCategoryID INT,
    @EntryDate         DATE,
    @ExitDate          DATE,
    @SharePercent      DECIMAL(5,2),
    @ExcludeID         INT = NULL,
    @Message           NVARCHAR(400) OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET @Message = NULL;

    DECLARE @MaxDate DATE = '9999-12-31';
    DECLARE @Others TABLE (BrandID INT, EntryDate DATE, ExitDate DATE, SharePercent DECIMAL(5,2));

    INSERT INTO @Others (BrandID, EntryDate, ExitDate, SharePercent)
    SELECT pb.BrandID, pbc.EntryDate, ISNULL(pbc.ExitDate, @MaxDate), pbc.SharePercent
    FROM dbo.CrmPartyBrandCategories pbc
    JOIN dbo.CrmPartyBrands pb ON pb.PartyBrandID = pbc.PartyBrandID
    WHERE pb.PartyID = @PartyID
      AND pbc.ProductCategoryID = @ProductCategoryID
      AND pbc.IsActive = 1
      AND pbc.PartyBrandCategoryID <> ISNULL(@ExcludeID, 0)
      AND pbc.EntryDate <= ISNULL(@ExitDate, @MaxDate)
      AND @EntryDate <= ISNULL(pbc.ExitDate, @MaxDate);

    IF EXISTS (SELECT 1 FROM @Others WHERE BrandID = @BrandID)
    BEGIN
        SET @Message = N'این برند در همین دسته‌بندی برایِ این طرف‌حساب یک بازهٔ زمانیِ هم‌پوشان دارد.';
        RETURN;
    END

    DECLARE @PeakDate DATE, @PeakTotal DECIMAL(9,2);
    SELECT TOP 1 @PeakDate = pt.D, @PeakTotal = @SharePercent + ISNULL(SUM(o.SharePercent), 0)
    FROM (SELECT @EntryDate AS D UNION SELECT EntryDate FROM @Others WHERE EntryDate > @EntryDate) pt
    LEFT JOIN @Others o ON o.EntryDate <= pt.D AND o.ExitDate >= pt.D
    GROUP BY pt.D
    ORDER BY @SharePercent + ISNULL(SUM(o.SharePercent), 0) DESC, pt.D;

    IF @PeakTotal > 100
        SET @Message = N'مجموعِ درصدِ برندهایِ این طرف‌حساب در این دسته‌بندی از تاریخِ '
                     + CONVERT(NVARCHAR(10), @PeakDate, 23) + N' به '
                     + CAST(CAST(@PeakTotal AS DECIMAL(9,2)) AS NVARCHAR(20)) + N'٪ می‌رسد و نباید بیشتر از ۱۰۰٪ شود.';
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SavePartyBrandCategory
CREATE PROCEDURE dbo.sp_Crm_SavePartyBrandCategory
    @PartyBrandCategoryID INT          = NULL,
    @PartyID              INT          = NULL,
    @BrandID              INT          = NULL,
    @ProductCategoryID    INT          = NULL,
    @EntryDate            DATE,
    @ExitDate             DATE         = NULL,
    @SharePercent         DECIMAL(5,2),
    @UserID               INT
AS
BEGIN
    SET NOCOUNT ON;

    IF @EntryDate IS NULL
    BEGIN SELECT 0 AS Success, N'تاریخِ ورود الزامی است.' AS Message; RETURN; END
    IF @ExitDate IS NOT NULL AND @ExitDate < @EntryDate
    BEGIN SELECT 0 AS Success, N'تاریخِ خروج نمی‌تواند قبل از تاریخِ ورود باشد.' AS Message; RETURN; END
    IF @SharePercent IS NULL OR @SharePercent < 0 OR @SharePercent > 100
    BEGIN SELECT 0 AS Success, N'درصد باید بین ۰ تا ۱۰۰ باشد.' AS Message; RETURN; END

    DECLARE @IsActive BIT = 1, @PartyBrandID INT;

    IF @PartyBrandCategoryID IS NOT NULL
    BEGIN
        -- ویرایش: برند و دسته ثابت‌اند؛ فقط تاریخ‌ها و درصد تغییر می‌کنند
        SELECT @PartyID = pb.PartyID, @BrandID = pb.BrandID, @ProductCategoryID = pbc.ProductCategoryID, @IsActive = pbc.IsActive
        FROM dbo.CrmPartyBrandCategories pbc JOIN dbo.CrmPartyBrands pb ON pb.PartyBrandID = pbc.PartyBrandID
        WHERE pbc.PartyBrandCategoryID = @PartyBrandCategoryID;
        IF @PartyID IS NULL
        BEGIN SELECT 0 AS Success, N'ردیف یافت نشد.' AS Message; RETURN; END
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
        BEGIN SELECT 0 AS Success, N'طرف‌حسابِ مربوطه یافت نشد.' AS Message; RETURN; END
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmBrands WHERE BrandID = @BrandID AND IsActive = 1)
        BEGIN SELECT 0 AS Success, N'برندِ انتخاب‌شده یافت نشد یا غیرفعال است.' AS Message; RETURN; END
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmProductCategories WHERE ProductCategoryID = @ProductCategoryID AND IsActive = 1)
        BEGIN SELECT 0 AS Success, N'دسته‌بندیِ انتخاب‌شده یافت نشد یا غیرفعال است.' AS Message; RETURN; END
    END

    DECLARE @Resource NVARCHAR(255) = N'CrmPartyBrandCategory:' + CAST(@PartyID AS NVARCHAR(20)) + N':' + CAST(@ProductCategoryID AS NVARCHAR(20));
    DECLARE @Msg NVARCHAR(400), @Lock INT, @NewID INT;

    BEGIN TRY
        BEGIN TRAN;

        EXEC @Lock = sp_getapplock @Resource = @Resource, @LockMode = 'Exclusive', @LockOwner = 'Transaction', @LockTimeout = 10000;
        IF @Lock < 0
        BEGIN
            COMMIT TRAN; -- هیچ نوشتنی انجام نشده؛ فقط قفل آزاد می‌شود (ROLLBACK تراکنشِ بیرونیِ فراخوان را هم برمی‌گرداند)
            SELECT 0 AS Success, N'سامانه مشغول است؛ لطفاً دوباره تلاش کنید.' AS Message; RETURN;
        END

        -- ردیفِ غیرفعال در مجموع شمرده نمی‌شود؛ قواعد هنگامِ فعال‌سازیِ دوباره بررسی می‌شوند
        IF @IsActive = 1
        BEGIN
            EXEC dbo.sp_Crm_CheckPartyBrandCategoryRules @PartyID, @BrandID, @ProductCategoryID, @EntryDate, @ExitDate, @SharePercent, @PartyBrandCategoryID, @Msg OUTPUT;
            IF @Msg IS NOT NULL
            BEGIN
                COMMIT TRAN; -- هیچ نوشتنی انجام نشده؛ فقط قفل آزاد می‌شود (ROLLBACK تراکنشِ بیرونیِ فراخوان را هم برمی‌گرداند)
                SELECT 0 AS Success, @Msg AS Message; RETURN;
            END
        END

        IF @PartyBrandCategoryID IS NULL
        BEGIN
            -- ارتباطِ Party↔Brand اگر نبود ساخته، و اگر غیرفعال بود دوباره فعال می‌شود
            SELECT @PartyBrandID = PartyBrandID FROM dbo.CrmPartyBrands WHERE PartyID = @PartyID AND BrandID = @BrandID;
            IF @PartyBrandID IS NULL
            BEGIN
                INSERT INTO dbo.CrmPartyBrands (PartyID, BrandID, IsActive, Date_InsertFirst, UserID_InsertFirst)
                VALUES (@PartyID, @BrandID, 1, SYSDATETIME(), @UserID);
                SET @PartyBrandID = CAST(SCOPE_IDENTITY() AS INT);
            END
            ELSE
                UPDATE dbo.CrmPartyBrands SET IsActive = 1, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
                WHERE PartyBrandID = @PartyBrandID AND IsActive = 0;

            INSERT INTO dbo.CrmPartyBrandCategories (PartyBrandID, ProductCategoryID, EntryDate, ExitDate, SharePercent, IsActive, Date_InsertFirst, UserID_InsertFirst)
            VALUES (@PartyBrandID, @ProductCategoryID, @EntryDate, @ExitDate, @SharePercent, 1, SYSDATETIME(), @UserID);
            SET @NewID = CAST(SCOPE_IDENTITY() AS INT);

            COMMIT TRAN;
            SELECT 1 AS Success, N'برند در دسته‌بندی ثبت شد.' AS Message, @NewID AS PartyBrandCategoryID;
        END
        ELSE
        BEGIN
            UPDATE dbo.CrmPartyBrandCategories
            SET EntryDate = @EntryDate, ExitDate = @ExitDate, SharePercent = @SharePercent,
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

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_TogglePartyBrandCategoryActive
CREATE PROCEDURE dbo.sp_Crm_TogglePartyBrandCategoryActive
    @PartyBrandCategoryID INT,
    @UserID               INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT, @PartyID INT, @BrandID INT, @CategoryID INT, @EntryDate DATE, @ExitDate DATE, @Percent DECIMAL(5,2),
            @BrandActive BIT, @CategoryActive BIT;

    SELECT @Current = pbc.IsActive, @PartyID = pb.PartyID, @BrandID = pb.BrandID, @CategoryID = pbc.ProductCategoryID,
           @EntryDate = pbc.EntryDate, @ExitDate = pbc.ExitDate, @Percent = pbc.SharePercent,
           @BrandActive = b.IsActive, @CategoryActive = c.IsActive
    FROM dbo.CrmPartyBrandCategories pbc
    JOIN dbo.CrmPartyBrands pb ON pb.PartyBrandID = pbc.PartyBrandID
    JOIN dbo.CrmBrands b ON b.BrandID = pb.BrandID
    JOIN dbo.CrmProductCategories c ON c.ProductCategoryID = pbc.ProductCategoryID
    WHERE pbc.PartyBrandCategoryID = @PartyBrandCategoryID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'ردیف یافت نشد.' AS Message; RETURN; END
    IF @Current = 0 AND @BrandActive = 0
    BEGIN SELECT 0 AS Success, N'برند غیرفعال است؛ ابتدا برند را فعال کنید.' AS Message; RETURN; END
    IF @Current = 0 AND @CategoryActive = 0
    BEGIN SELECT 0 AS Success, N'دسته‌بندی غیرفعال است؛ ابتدا دسته‌بندی را فعال کنید.' AS Message; RETURN; END

    DECLARE @Resource NVARCHAR(255) = N'CrmPartyBrandCategory:' + CAST(@PartyID AS NVARCHAR(20)) + N':' + CAST(@CategoryID AS NVARCHAR(20));
    DECLARE @Msg NVARCHAR(400), @Lock INT;

    BEGIN TRY
        BEGIN TRAN;

        EXEC @Lock = sp_getapplock @Resource = @Resource, @LockMode = 'Exclusive', @LockOwner = 'Transaction', @LockTimeout = 10000;
        IF @Lock < 0
        BEGIN
            COMMIT TRAN; -- هیچ نوشتنی انجام نشده؛ فقط قفل آزاد می‌شود (ROLLBACK تراکنشِ بیرونیِ فراخوان را هم برمی‌گرداند)
            SELECT 0 AS Success, N'سامانه مشغول است؛ لطفاً دوباره تلاش کنید.' AS Message; RETURN;
        END

        IF @Current = 0
        BEGIN
            EXEC dbo.sp_Crm_CheckPartyBrandCategoryRules @PartyID, @BrandID, @CategoryID, @EntryDate, @ExitDate, @Percent, @PartyBrandCategoryID, @Msg OUTPUT;
            IF @Msg IS NOT NULL
            BEGIN
                COMMIT TRAN; -- هیچ نوشتنی انجام نشده؛ فقط قفل آزاد می‌شود (ROLLBACK تراکنشِ بیرونیِ فراخوان را هم برمی‌گرداند)
                SELECT 0 AS Success, @Msg AS Message; RETURN;
            END
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


/* ===== [MANUAL DML] منویِ صفحهٔ مستقلِ «دسته‌بندی محصولات» زیرِ پوشهٔ CRM — DML است و توسطِ DDL-trigger ثبت نمی‌شود،
   پس دستی به انتهایِ این پچ اضافه شد (هم‌الگو با 045 و 058). Idempotent با چکِ Url. ===== */
IF NOT EXISTS (SELECT 1 FROM dbo.Menu WHERE Url = N'/crm/product-categories')
BEGIN
    DECLARE @CrmFolderIDProductCategories INT;
    SELECT @CrmFolderIDProductCategories = MenuID FROM dbo.Menu WHERE MenuTitle = N'CRM' AND ParentID IS NULL;
    IF @CrmFolderIDProductCategories IS NOT NULL
        EXEC dbo.sp_InsertMenu @ParentID = @CrmFolderIDProductCategories, @MenuTitle = N'دسته‌بندی محصولات', @MenuKind = N'PAGE', @Url = N'/crm/product-categories', @SortOrder = 6;

    EXEC dbo.sp_GrantAllMenusToRole @RoleID = 1;
END
GO
