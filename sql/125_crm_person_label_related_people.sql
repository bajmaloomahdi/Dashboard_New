/* ==========================================================================
   پچ خودکار شماره: 125 | نام: crm_person_label_related_people
   تاریخ: 2026-10-27 16:00:53 | شامل 10 دستور SQL
   ========================================================================== */

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_DeleteRecord
ALTER PROCEDURE dbo.sp_Crm_DeleteRecord
    @EntityCode NVARCHAR(50),
    @RecordID   INT,
    @UserID     INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Map TABLE (Code NVARCHAR(50) NULL, TableName SYSNAME PRIMARY KEY, Label NVARCHAR(100));
    INSERT INTO @Map (Code, TableName, Label) VALUES
        (N'PARTY',                N'CrmParties',                      N'طرف‌حساب'),
        (N'PERSON',               N'CrmPersons',                      N'شخص'),
        (N'BRAND',                N'CrmBrands',                       N'برند'),
        (NULL,                   N'CrmPartyBrands',                  N'برندِ طرف‌حساب'),
        (N'PARTY_BRAND_CATEGORY', N'CrmPartyBrandCategories',         N'گروهِ کالاییِ برند'),
        (N'ADDRESS',              N'CrmAddresses',                    N'آدرس'),
        (N'CONTACT',              N'CrmContacts',                     N'اطلاعاتِ تماس'),
        (N'RELATION',             N'CrmPartyPersonRelations',         N'فرد مرتبط'),
        (N'CLASSIFICATION',       N'CrmPartyClassifications',         N'دسته‌بندیِ طرف‌حساب'),
        (N'INTERACTION',          N'CrmInteractions',                 N'تعامل'),
        (N'PRODUCT_CATEGORY',     N'CrmProductCategories',            N'گروهِ کالایی'),
        (N'DEPARTMENT',           N'CrmDepartments',                  N'دپارتمان'),
        (N'PARTY_TYPE',           N'CrmPartyTypes',                   N'نوع'),
        (N'ACTIVITY',             N'CrmActivities',                   N'فعالیت'),
        (N'TITLE',                N'CrmTitles',                       N'عنوان'),
        (N'POSITION',             N'CrmPositions',                    N'سمت'),
        (N'CONTACT_ROLE',         N'CrmContactRoles',                 N'نقش'),
        (N'CONTACT_TYPE',         N'CrmContactTypes',                 N'نوعِ تماس'),
        (N'ADDRESS_TITLE',        N'CrmAddressTitles',                N'عنوانِ آدرس'),
        (N'INTERACTION_TYPE',     N'CrmInteractionTypes',             N'نوعِ تعامل'),
        (N'OWNERSHIP_TYPE',       N'CrmOwnershipTypes',               N'نوعِ مالکیت'),
        (N'PROVINCE',             N'CrmProvinces',                    N'استان'),
        (N'COUNTY',               N'CrmCounties',                     N'شهرستان'),
        (N'CITY',                 N'CrmCities',                       N'شهر'),
        (N'NEIGHBORHOOD',         N'CrmNeighborhoods',                N'محله'),
        (N'MUNICIPAL_ZONE',       N'CrmMunicipalZones',               N'منطقهٔ شهرداری'),
        -- فقط برچسب (در پیامِ وابستگی‌ها)، از این مسیر حذف نمی‌شوند
        (NULL, N'CrmPartyImages',                  N'تصاویر'),
        (NULL, N'CrmPartyAttachments',             N'فایل‌هایِ پیوست'),
        (NULL, N'CrmInteractionAttachments',       N'پیوست‌هایِ تعامل'),
        (NULL, N'CrmPartyRoles',                   N'نقش‌هایِ طرف‌حساب'),
        (NULL, N'CrmPartyPositions',               N'سمت‌هایِ طرف‌حساب'),
        (NULL, N'CrmPartyPersonRelationRoles',     N'نقش‌هایِ فرد مرتبط'),
        (NULL, N'CrmPartyPersonRelationPositions', N'سمت‌هایِ فرد مرتبط'),
        (NULL, N'CrmPartySupplementaryInfo',       N'اطلاعاتِ تکمیلی'),
        (NULL, N'Projects',                        N'پروژه‌ها'),
        (NULL, N'ProjectContractors',              N'پیمانکارانِ پروژه');

    DECLARE @Table SYSNAME, @Label NVARCHAR(100);
    SELECT @Table = TableName, @Label = Label FROM @Map WHERE Code = @EntityCode;
    IF @Table IS NULL
    BEGIN SELECT 0 AS Success, N'نوعِ اطلاعاتِ انتخاب‌شده برایِ حذف معتبر نیست.' AS Message; RETURN; END

    DECLARE @TableID INT = OBJECT_ID(N'dbo.' + QUOTENAME(@Table));
    DECLARE @Pk SYSNAME = (
        SELECT TOP 1 c.name
        FROM sys.indexes i
        JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
        JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
        WHERE i.object_id = @TableID AND i.is_primary_key = 1
    );

    DECLARE @Sql NVARCHAR(MAX), @Cnt INT;
    SET @Sql = N'SELECT @c = COUNT(*) FROM dbo.' + QUOTENAME(@Table) + N' WHERE ' + QUOTENAME(@Pk) + N' = @id';
    EXEC sp_executesql @Sql, N'@c INT OUTPUT, @id INT', @c = @Cnt OUTPUT, @id = @RecordID;
    IF @Cnt = 0
    BEGIN SELECT 0 AS Success, N'«' + @Label + N'» یافت نشد.' AS Message; RETURN; END

    -- انتخاب‌هایِ متعلق به خودِ رکورد که همراهش حذف می‌شوند
    -- OwnedWhere (اختیاری، با alias ِ t): فقط ردیف‌هایِ منطبق متعلق‌اند و بقیه وابستگی حساب می‌شوند
    DECLARE @Owned TABLE (TableName SYSNAME, ColName SYSNAME, OwnedWhere NVARCHAR(400) NULL);
    DECLARE @OrphanPartyBrand NVARCHAR(400) = N'NOT EXISTS (SELECT 1 FROM dbo.CrmPartyBrandCategories x WHERE x.PartyBrandID = t.PartyBrandID)';
    IF @EntityCode = N'PARTY'
        INSERT INTO @Owned VALUES (N'CrmPartyRoles', N'PartyID', NULL), (N'CrmPartyPositions', N'PartyID', NULL),
                                  (N'CrmPartySupplementaryInfo', N'PartyID', NULL), (N'CrmPartyBrands', N'PartyID', @OrphanPartyBrand);
    IF @EntityCode = N'BRAND'
        INSERT INTO @Owned VALUES (N'CrmPartyBrands', N'BrandID', @OrphanPartyBrand);
    IF @EntityCode = N'RELATION'
        INSERT INTO @Owned VALUES (N'CrmPartyPersonRelationRoles', N'RelationID', NULL), (N'CrmPartyPersonRelationPositions', N'RelationID', NULL);

    -- ردیفِ «برندِ طرف‌حساب»: ارتباطِ داخلیِ Party↔Brand اگر بعد از حذف بی‌ردیف شود، همراهش حذف می‌شود
    DECLARE @ParentPartyBrandID INT = NULL;
    IF @EntityCode = N'PARTY_BRAND_CATEGORY'
        SELECT @ParentPartyBrandID = PartyBrandID FROM dbo.CrmPartyBrandCategories WHERE PartyBrandCategoryID = @RecordID;

    -- وابستگی‌ها: همهٔ FKهایِ ارجاع‌دهنده به این جدول، به‌جز انتخاب‌هایِ متعلق
    DECLARE @Deps TABLE (TableName SYSNAME, Cnt INT);
    DECLARE @Child SYSNAME, @Col SYSNAME, @OwnedWhere NVARCHAR(400), @IsOwned BIT;
    DECLARE fk CURSOR LOCAL FAST_FORWARD FOR
        SELECT x.Child, x.Col, o.OwnedWhere, CASE WHEN o.TableName IS NULL THEN 0 ELSE 1 END
        FROM (
            SELECT DISTINCT OBJECT_NAME(fkc.parent_object_id) AS Child, COL_NAME(fkc.parent_object_id, fkc.parent_column_id) AS Col
            FROM sys.foreign_key_columns fkc
            WHERE fkc.referenced_object_id = @TableID
        ) x
        LEFT JOIN @Owned o ON o.TableName = x.Child AND o.ColName = x.Col
        WHERE o.TableName IS NULL OR o.OwnedWhere IS NOT NULL;
    OPEN fk;
    FETCH NEXT FROM fk INTO @Child, @Col, @OwnedWhere, @IsOwned;
    WHILE @@FETCH_STATUS = 0
    BEGIN
        SET @Sql = N'SELECT @c = COUNT(*) FROM dbo.' + QUOTENAME(@Child) + N' t WHERE t.' + QUOTENAME(@Col) + N' = @id'
                 + CASE WHEN @IsOwned = 1 THEN N' AND NOT (' + @OwnedWhere + N')' ELSE N'' END;
        EXEC sp_executesql @Sql, N'@c INT OUTPUT, @id INT', @c = @Cnt OUTPUT, @id = @RecordID;
        IF @Cnt > 0
        BEGIN
            IF EXISTS (SELECT 1 FROM @Deps WHERE TableName = @Child)
                UPDATE @Deps SET Cnt = Cnt + @Cnt WHERE TableName = @Child;
            ELSE
                INSERT INTO @Deps (TableName, Cnt) VALUES (@Child, @Cnt);
        END
        FETCH NEXT FROM fk INTO @Child, @Col, @OwnedWhere, @IsOwned;
    END
    CLOSE fk;
    DEALLOCATE fk;

    IF EXISTS (SELECT 1 FROM @Deps)
    BEGIN
        DECLARE @List NVARCHAR(MAX) = (
            SELECT STRING_AGG(ISNULL(m.Label, d.TableName) + N' (' + CAST(d.Cnt AS NVARCHAR(10)) + N')', N'، ')
            FROM @Deps d LEFT JOIN @Map m ON m.TableName = d.TableName
        );
        SELECT 0 AS Success,
               N'این «' + @Label + N'» قابلِ حذف نیست چون در این بخش‌ها استفاده شده است: ' + @List
               + N'. ابتدا آن‌ها را حذف کنید یا به‌جایِ حذف، رکورد را غیرفعال کنید.' AS Message;
        RETURN;
    END

    BEGIN TRY
        BEGIN TRAN;

        DECLARE @OT SYSNAME, @OC SYSNAME, @OW NVARCHAR(400);
        DECLARE own CURSOR LOCAL FAST_FORWARD FOR SELECT TableName, ColName, OwnedWhere FROM @Owned;
        OPEN own;
        FETCH NEXT FROM own INTO @OT, @OC, @OW;
        WHILE @@FETCH_STATUS = 0
        BEGIN
            SET @Sql = N'DELETE t FROM dbo.' + QUOTENAME(@OT) + N' t WHERE t.' + QUOTENAME(@OC) + N' = @id'
                     + CASE WHEN @OW IS NOT NULL THEN N' AND (' + @OW + N')' ELSE N'' END;
            EXEC sp_executesql @Sql, N'@id INT', @id = @RecordID;
            FETCH NEXT FROM own INTO @OT, @OC, @OW;
        END
        CLOSE own;
        DEALLOCATE own;

        SET @Sql = N'DELETE FROM dbo.' + QUOTENAME(@Table) + N' WHERE ' + QUOTENAME(@Pk) + N' = @id';
        EXEC sp_executesql @Sql, N'@id INT', @id = @RecordID;

        IF @ParentPartyBrandID IS NOT NULL
            DELETE FROM dbo.CrmPartyBrands
            WHERE PartyBrandID = @ParentPartyBrandID
              AND NOT EXISTS (SELECT 1 FROM dbo.CrmPartyBrandCategories x WHERE x.PartyBrandID = @ParentPartyBrandID);

        COMMIT TRAN;
        SELECT 1 AS Success, N'«' + @Label + N'» حذف شد.' AS Message;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRAN;
        SELECT 0 AS Success, N'خطا در حذفِ «' + @Label + N'»: ' + ERROR_MESSAGE() AS Message;
    END CATCH
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
    @AddressText     NVARCHAR(500) = NULL,
    @PlateNumber     NVARCHAR(20)  = NULL,
    @Unit            NVARCHAR(20)  = NULL,
    @UserID          INT,
    @Latitude        DECIMAL(9,6)  = NULL,
    @Longitude       DECIMAL(9,6)  = NULL
AS
BEGIN
    SET NOCOUNT ON;

    IF (@PartyID IS NULL AND @PersonID IS NULL) OR (@PartyID IS NOT NULL AND @PersonID IS NOT NULL)
    BEGIN SELECT 0 AS Success, N'دقیقاً یکی از طرف‌حساب یا فرد مرتبط باید مشخص باشد.' AS Message; RETURN; END
    IF @PartyID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حساب انتخاب‌شده معتبر نیست.' AS Message; RETURN; END
    IF @PersonID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmPersons WHERE PersonID = @PersonID)
    BEGIN SELECT 0 AS Success, N'فردِ مرتبطِ انتخاب‌شده معتبر نیست.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.CrmAddressTitles WHERE AddressTitleID = @AddressTitleID)
    BEGIN SELECT 0 AS Success, N'عنوانِ آدرس معتبر نیست.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.CrmCounties WHERE CountyID = @CountyID AND ProvinceID = @ProvinceID)
    BEGIN SELECT 0 AS Success, N'شهرستانِ انتخاب‌شده متعلق به استانِ انتخاب‌شده نیست.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.CrmCities WHERE CityID = @CityID AND CountyID = @CountyID)
    BEGIN SELECT 0 AS Success, N'شهرِ انتخاب‌شده متعلق به شهرستانِ انتخاب‌شده نیست.' AS Message; RETURN; END
    IF @NeighborhoodID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmNeighborhoods WHERE NeighborhoodID = @NeighborhoodID AND CityID = @CityID)
    BEGIN SELECT 0 AS Success, N'محلهٔ انتخاب‌شده متعلق به شهرِ انتخاب‌شده نیست.' AS Message; RETURN; END
    -- متنِ آدرس اختیاری است؛ خالی به‌صورتِ رشتهٔ تهی ذخیره می‌شود.
    SET @AddressText = ISNULL(LTRIM(RTRIM(@AddressText)), N'');
    IF (@Latitude IS NULL AND @Longitude IS NOT NULL) OR (@Latitude IS NOT NULL AND @Longitude IS NULL)
    BEGIN SELECT 0 AS Success, N'عرض و طولِ جغرافیایی باید با هم ثبت یا با هم حذف شوند.' AS Message; RETURN; END
    IF @Latitude IS NOT NULL AND (@Latitude < -90 OR @Latitude > 90 OR @Longitude < -180 OR @Longitude > 180)
    BEGIN SELECT 0 AS Success, N'مختصاتِ جغرافیایی خارج از محدودهٔ معتبر است.' AS Message; RETURN; END

    IF @AddressID IS NULL
    BEGIN
        INSERT INTO dbo.CrmAddresses (
            PartyID, PersonID, AddressTitleID, ProvinceID, CountyID, CityID, NeighborhoodID, MunicipalZoneID,
            PostalCode, AddressText, PlateNumber, Unit, Latitude, Longitude, IsActive, Date_InsertFirst, UserID_InsertFirst
        )
        VALUES (
            @PartyID, @PersonID, @AddressTitleID, @ProvinceID, @CountyID, @CityID, @NeighborhoodID, @MunicipalZoneID,
            @PostalCode, @AddressText, @PlateNumber, @Unit, @Latitude, @Longitude, 1, SYSDATETIME(), @UserID
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
            Latitude = @Latitude, Longitude = @Longitude,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE AddressID = @AddressID;

        SELECT 1 AS Success, N'آدرس ویرایش شد.' AS Message, @AddressID AS AddressID;
    END
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveContact
ALTER PROCEDURE dbo.sp_Crm_SaveContact
    @ContactID       INT           = NULL,
    @PartyID         INT           = NULL,
    @PersonID        INT           = NULL,
    @ContactTypeID   INT,
    @ContactValue    NVARCHAR(200),
    @Extension       NVARCHAR(20)  = NULL,
    @Description     NVARCHAR(200) = NULL,
    @IsPrimary       BIT           = 0,
    @RelatedPersonID INT           = NULL,
    @UserID          INT
AS
BEGIN
    SET NOCOUNT ON;

    IF (@PartyID IS NULL AND @PersonID IS NULL) OR (@PartyID IS NOT NULL AND @PersonID IS NOT NULL)
    BEGIN SELECT 0 AS Success, N'دقیقاً یکی از طرف‌حساب یا فرد مرتبط باید مشخص باشد.' AS Message; RETURN; END
    IF @PartyID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حساب انتخاب‌شده معتبر نیست.' AS Message; RETURN; END
    IF @PersonID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmPersons WHERE PersonID = @PersonID)
    BEGIN SELECT 0 AS Success, N'فردِ مرتبطِ انتخاب‌شده معتبر نیست.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.CrmContactTypes WHERE ContactTypeID = @ContactTypeID)
    BEGIN SELECT 0 AS Success, N'نوعِ تماس معتبر نیست.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@ContactValue)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'مقدارِ تماس الزامی است.' AS Message; RETURN; END
    IF @RelatedPersonID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmPersons WHERE PersonID = @RelatedPersonID)
    BEGIN SELECT 0 AS Success, N'فردِ مرتبطِ این تماس معتبر نیست.' AS Message; RETURN; END

    -- شماره‌ها فقط رقمِ لاتینِ 0 تا 9، بدونِ هیچ نویسهٔ دیگری (فاصله، خط‌تیره، +98، پرانتز، ارقامِ فارسی):
    -- موبایل = ۱۱ رقم و شروع با ۰۹؛ تلفن/فکس = ۱۱ رقم با پیش‌شماره (شروع با ۰) یا ۸ رقم بدونِ پیش‌شماره.
    DECLARE @TypeName NVARCHAR(100) = (SELECT LTRIM(RTRIM(DisplayName)) FROM dbo.CrmContactTypes WHERE ContactTypeID = @ContactTypeID);
    IF @TypeName IN (N'موبایل', N'تلفن', N'فکس')
    BEGIN
        DECLARE @Digits NVARCHAR(200) = @ContactValue;
        IF @Digits = N'' OR @Digits COLLATE Latin1_General_BIN LIKE N'%[^0-9]%'
        BEGIN SELECT 0 AS Success, N'شمارهٔ ' + @TypeName + N' فقط باید شاملِ رقم باشد، بدونِ فاصله، خط‌تیره، +98 یا هر نویسهٔ دیگر (مثلاً 09123544125 یا 02155955160).' AS Message; RETURN; END
        IF @TypeName = N'موبایل' AND NOT (LEN(@Digits) = 11 AND LEFT(@Digits, 2) = N'09')
        BEGIN SELECT 0 AS Success, N'شمارهٔ موبایل باید ۱۱ رقم باشد و با ۰۹ شروع شود (مثلاً 09121234567).' AS Message; RETURN; END
        IF @TypeName IN (N'تلفن', N'فکس') AND NOT ((LEN(@Digits) = 11 AND LEFT(@Digits, 1) = N'0') OR (LEN(@Digits) = 8 AND LEFT(@Digits, 1) <> N'0'))
        BEGIN SELECT 0 AS Success, N'شمارهٔ ' + @TypeName + N' باید ۱۱ رقم با پیش‌شماره (مثلاً 02112345678) یا ۸ رقم بدونِ پیش‌شماره باشد.' AS Message; RETURN; END
    END
    BEGIN TRY
        BEGIN TRAN;

        -- شمارهٔ موبایل یکتا: هیچ اطلاعاتِ تماسِ «موبایل»ِ فعالِ دیگری (برایِ هر طرف‌حساب یا مخاطبی) همین شماره را
        -- نداشته باشد. applock دو ثبتِ هم‌زمانِ یک شماره را پشتِ‌سرِهم می‌کند.
        IF EXISTS (SELECT 1 FROM dbo.CrmContactTypes WHERE ContactTypeID = @ContactTypeID AND LTRIM(RTRIM(DisplayName)) = N'موبایل')
        BEGIN
            EXEC sp_getapplock @Resource = N'CrmMobileUnique', @LockMode = N'Exclusive', @LockOwner = N'Transaction', @LockTimeout = 10000;

            DECLARE @Mobile NVARCHAR(200) = dbo.fn_Crm_NormalizeMobile(@ContactValue);
            DECLARE @DupOwner NVARCHAR(400) = (
                SELECT TOP 1 COALESCE(p.OfficialName, LTRIM(RTRIM(ISNULL(pe.FirstName, N'') + N' ' + ISNULL(pe.LastName, N''))))
                FROM dbo.CrmContacts c
                JOIN dbo.CrmContactTypes t ON t.ContactTypeID = c.ContactTypeID AND LTRIM(RTRIM(t.DisplayName)) = N'موبایل'
                LEFT JOIN dbo.CrmParties p ON p.PartyID = c.PartyID
                LEFT JOIN dbo.CrmPersons pe ON pe.PersonID = c.PersonID
                WHERE c.IsActive = 1
                  AND (@ContactID IS NULL OR c.ContactID <> @ContactID)
                  AND dbo.fn_Crm_NormalizeMobile(c.ContactValue) = @Mobile
                ORDER BY c.ContactID
            );
            IF @DupOwner IS NOT NULL
            BEGIN
                COMMIT TRAN; -- هنوز چیزی نوشته نشده؛ ROLLBACK تراکنشِ بیرونیِ فراخوان را هم از بین می‌برد
                SELECT 0 AS Success, N'این شمارهٔ موبایل قبلاً برایِ «' + @DupOwner + N'» ثبت شده است؛ شمارهٔ موبایلِ تکراری مجاز نیست.' AS Message;
                RETURN;
            END
        END

        IF @ContactID IS NULL
        BEGIN
            INSERT INTO dbo.CrmContacts (PartyID, PersonID, ContactTypeID, ContactValue, Extension, Description, IsPrimary, RelatedPersonID, IsActive, Date_InsertFirst, UserID_InsertFirst)
            VALUES (@PartyID, @PersonID, @ContactTypeID, @ContactValue, @Extension, @Description, ISNULL(@IsPrimary, 0), @RelatedPersonID, 1, SYSDATETIME(), @UserID);

            SET @ContactID = CAST(SCOPE_IDENTITY() AS INT);
        END
        ELSE
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM dbo.CrmContacts WHERE ContactID = @ContactID)
            BEGIN
                ROLLBACK TRAN;
                SELECT 0 AS Success, N'اطلاعاتِ تماس یافت نشد.' AS Message;
                RETURN;
            END

            UPDATE dbo.CrmContacts
            SET ContactTypeID = @ContactTypeID, ContactValue = @ContactValue, Extension = @Extension,
                Description = @Description, IsPrimary = ISNULL(@IsPrimary, 0), RelatedPersonID = @RelatedPersonID,
                Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
            WHERE ContactID = @ContactID;
        END

        -- تکِ اصلی بودن، به‌ازایِ همان مالک (طرف‌حساب یا مخاطب)
        IF ISNULL(@IsPrimary, 0) = 1
            UPDATE dbo.CrmContacts
            SET IsPrimary = 0
            WHERE ContactID <> @ContactID
              AND ((@PartyID IS NOT NULL AND PartyID = @PartyID) OR (@PersonID IS NOT NULL AND PersonID = @PersonID));

        COMMIT TRAN;
        SELECT 1 AS Success, N'اطلاعاتِ تماس ذخیره شد.' AS Message, @ContactID AS ContactID;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRAN;
        SELECT 0 AS Success, N'خطا در ذخیره: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveInteraction
-- 3) sp_Crm_SaveInteraction: accept @ProjectID (optional) + Server-side validation against ProjectContractors
ALTER PROCEDURE dbo.sp_Crm_SaveInteraction
    @InteractionID      INT            = NULL,
    @InteractionTypeID  INT,
    @PartyID             INT            = NULL,
    @PersonID            INT            = NULL,
    @ProjectID            BIGINT         = NULL,
    @Subject              NVARCHAR(200),
    @Description          NVARCHAR(MAX)  = NULL,
    @Outcome              NVARCHAR(MAX)  = NULL,
    @InteractionDate      DATETIME2(0),
    @Status               NVARCHAR(20)   = NULL,
    @FollowUpOfID         INT            = NULL,
    @OwnerUserID          INT            = NULL,
    @UserID               INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @TypeCode NVARCHAR(64);
    SELECT @TypeCode = Code FROM dbo.CrmInteractionTypes WHERE InteractionTypeID = @InteractionTypeID AND IsActive = 1;
    IF @TypeCode IS NULL
    BEGIN SELECT 0 AS Success, N'نوعِ تعامل نامعتبر است.' AS Message; RETURN; END
    -- مالکِ تعامل: طرف‌حساب (با مخاطبِ اختیاری از مخاطبینِ مرتبطش) یا مستقیماً یک مخاطب (بدونِ طرف‌حساب)
    IF @PartyID IS NULL AND @PersonID IS NULL
    BEGIN SELECT 0 AS Success, N'طرف‌حساب یا فردِ مرتبطِ تعامل باید مشخص باشد.' AS Message; RETURN; END
    IF @PartyID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حسابِ مربوطه یافت نشد.' AS Message; RETURN; END
    IF @PartyID IS NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmPersons WHERE PersonID = @PersonID)
    BEGIN SELECT 0 AS Success, N'فردِ مرتبطِ مربوطه یافت نشد.' AS Message; RETURN; END
    IF @PartyID IS NULL AND @ProjectID IS NOT NULL
    BEGIN SELECT 0 AS Success, N'ارتباط با پروژه فقط برایِ تعاملِ یک طرف‌حساب ممکن است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@Subject)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'موضوع الزامی است.' AS Message; RETURN; END
    IF @InteractionDate IS NULL
    BEGIN SELECT 0 AS Success, N'تاریخ و ساعت الزامی است.' AS Message; RETURN; END

    -- ارتباطِ اختیاریِ Project: اگر ارسال شد، Backend مستقل از Frontend بررسی می‌کند که این
    -- Party واقعاً پیمانکارِ فعالِ همین Project باشد (دفاع در برابرِ دستکاریِ Request).
    IF @ProjectID IS NOT NULL
    BEGIN
        IF NOT EXISTS (
            SELECT 1 FROM dbo.ProjectContractors
            WHERE ProjectID = @ProjectID AND PartyID = @PartyID AND IsActive = 1)
        BEGIN SELECT 0 AS Success, N'این طرف‌حساب، پیمانکارِ فعالِ پروژهٔ انتخاب‌شده نیست.' AS Message; RETURN; END
    END

    IF @PartyID IS NOT NULL AND @PersonID IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM dbo.CrmPartyPersonRelations WHERE PartyID = @PartyID AND PersonID = @PersonID AND IsActive = 1)
    BEGIN SELECT 0 AS Success, N'فردِ مرتبطِ انتخاب‌شده با این طرف‌حساب رابطهٔ فعال ندارد.' AS Message; RETURN; END

    IF @FollowUpOfID IS NOT NULL
    BEGIN
        IF @TypeCode <> N'FOLLOWUP'
        BEGIN SELECT 0 AS Success, N'فقط «پیگیری» می‌تواند به تعاملِ دیگری ارجاع داشته باشد.' AS Message; RETURN; END
        IF @FollowUpOfID = ISNULL(@InteractionID, 0)
        BEGIN SELECT 0 AS Success, N'یک تعامل نمی‌تواند پیگیریِ خودش باشد.' AS Message; RETURN; END
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmInteractions WHERE InteractionID = @FollowUpOfID
                         AND ((@PartyID IS NOT NULL AND PartyID = @PartyID)
                              OR (@PartyID IS NULL AND PartyID IS NULL AND PersonID = @PersonID)))
        BEGIN SELECT 0 AS Success, N'تعاملِ مبدأِ پیگیری در همین طرف‌حساب/فرد مرتبط یافت نشد.' AS Message; RETURN; END
    END

    SET @OwnerUserID = ISNULL(@OwnerUserID, @UserID);
    IF @OwnerUserID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @OwnerUserID)
    BEGIN SELECT 0 AS Success, N'مسئولِ انتخاب‌شده معتبر نیست.' AS Message; RETURN; END

    IF @InteractionID IS NULL
    BEGIN
        IF @TypeCode = N'NOTE'
        BEGIN
            IF @Status IS NOT NULL AND @Status <> N'DONE'
            BEGIN SELECT 0 AS Success, N'یادداشت همیشه «انجام‌شده» ثبت می‌شود.' AS Message; RETURN; END
            SET @Status = N'DONE';
        END
        ELSE IF @TypeCode = N'FOLLOWUP'
        BEGIN
            IF @Status IS NOT NULL AND @Status <> N'PLANNED'
            BEGIN SELECT 0 AS Success, N'پیگیری هنگامِ ثبت باید «برنامه‌ریزی‌شده» باشد.' AS Message; RETURN; END
            SET @Status = N'PLANNED';
        END
        ELSE
        BEGIN
            SET @Status = ISNULL(@Status, N'DONE');
            IF @Status NOT IN (N'PLANNED', N'DONE')
            BEGIN SELECT 0 AS Success, N'تماس/جلسه هنگامِ ثبت فقط می‌تواند «برنامه‌ریزی‌شده» یا «انجام‌شده» باشد.' AS Message; RETURN; END
        END

        INSERT INTO dbo.CrmInteractions (InteractionType, InteractionTypeID, PartyID, PersonID, ProjectID, Subject, Description, Outcome, InteractionDate,
                                         Status, FollowUpOfID, OwnerUserID, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@TypeCode, @InteractionTypeID, @PartyID, @PersonID, @ProjectID, @Subject, @Description, @Outcome, @InteractionDate,
                @Status, @FollowUpOfID, @OwnerUserID, 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'تعامل ثبت شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS InteractionID;
    END
    ELSE
    BEGIN
        DECLARE @CurTypeID INT, @CurParty INT, @CurPerson INT;
        SELECT @CurTypeID = InteractionTypeID, @CurParty = PartyID, @CurPerson = PersonID FROM dbo.CrmInteractions WHERE InteractionID = @InteractionID;
        IF @CurTypeID IS NULL
        BEGIN SELECT 0 AS Success, N'تعامل یافت نشد.' AS Message; RETURN; END
        IF @CurTypeID <> @InteractionTypeID OR ISNULL(@CurParty, 0) <> ISNULL(@PartyID, 0)
        BEGIN SELECT 0 AS Success, N'نوع و طرف‌حسابِ تعامل پس از ثبت قابلِ‌تغییر نیست.' AS Message; RETURN; END
        IF @CurParty IS NULL AND ISNULL(@CurPerson, 0) <> ISNULL(@PersonID, 0)
        BEGIN SELECT 0 AS Success, N'فردِ مرتبطِ تعاملی که بدونِ طرف‌حساب ثبت شده، پس از ثبت قابلِ‌تغییر نیست.' AS Message; RETURN; END

        UPDATE dbo.CrmInteractions
        SET PersonID = @PersonID, ProjectID = @ProjectID, Subject = @Subject, Description = @Description, Outcome = @Outcome,
            InteractionDate = @InteractionDate, FollowUpOfID = @FollowUpOfID, OwnerUserID = @OwnerUserID,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE InteractionID = @InteractionID;

        SELECT 1 AS Success, N'تعامل ویرایش شد.' AS Message, @InteractionID AS InteractionID;
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
               N'فرد مرتبطی با نامِ «' + @DupPerson + N'» از قبل ثبت شده است' + CASE WHEN @DupPersonActive = 0 THEN N' (غیرفعال)' ELSE N'' END + N'. آیا با همین نام ثبت شود؟' AS Message,
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

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveRelation
ALTER PROCEDURE dbo.sp_Crm_SaveRelation
    @RelationID       INT = NULL,
    @PartyID          INT,
    @PersonID         INT,
    @PositionID       INT = NULL,
    @IsPrimaryContact BIT = 0,
    @UserID           INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حسابِ مربوطه یافت نشد.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.CrmPersons WHERE PersonID = @PersonID)
    BEGIN SELECT 0 AS Success, N'شخصِ انتخاب‌شده یافت نشد.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.CrmPartyPersonRelations
               WHERE PartyID = @PartyID AND PersonID = @PersonID AND (@RelationID IS NULL OR RelationID <> @RelationID))
    BEGIN SELECT 0 AS Success, N'این شخص از قبل به‌عنوانِ فردِ مرتبطِ همین طرف‌حساب ثبت شده است.' AS Message; RETURN; END

    BEGIN TRY
        BEGIN TRAN;

        IF @RelationID IS NULL
        BEGIN
            INSERT INTO dbo.CrmPartyPersonRelations (PartyID, PersonID, PositionID, IsPrimaryContact, IsActive, Date_InsertFirst, UserID_InsertFirst)
            VALUES (@PartyID, @PersonID, @PositionID, ISNULL(@IsPrimaryContact, 0), 1, SYSDATETIME(), @UserID);

            SET @RelationID = CAST(SCOPE_IDENTITY() AS INT);
        END
        ELSE
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM dbo.CrmPartyPersonRelations WHERE RelationID = @RelationID)
            BEGIN
                ROLLBACK TRAN;
                SELECT 0 AS Success, N'رابطه یافت نشد.' AS Message;
                RETURN;
            END

            UPDATE dbo.CrmPartyPersonRelations
            SET PersonID = @PersonID, PositionID = @PositionID, IsPrimaryContact = ISNULL(@IsPrimaryContact, 0),
                Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
            WHERE RelationID = @RelationID;
        END

        -- فقط یک مخاطبِ اصلی در هر طرف‌حساب
        IF ISNULL(@IsPrimaryContact, 0) = 1
            UPDATE dbo.CrmPartyPersonRelations SET IsPrimaryContact = 0 WHERE PartyID = @PartyID AND RelationID <> @RelationID;

        COMMIT TRAN;
        SELECT 1 AS Success, N'رابطه ذخیره شد.' AS Message, @RelationID AS RelationID;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRAN;
        SELECT 0 AS Success, N'خطا در ذخیره: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveRelationPositions
-- سمت‌هایِ چندگانهٔ یک مخاطب (رابطهٔ طرف‌حساب↔شخص) — Sync کامل از CSV، هم‌شکلِ sp_Crm_SaveRelationRoles.
-- سمتِ غیرفعالِ جدید پذیرفته نمی‌شود؛ سمتِ غیرفعالی که از قبل رویِ رابطه بوده حفظ می‌شود.
ALTER PROCEDURE dbo.sp_Crm_SaveRelationPositions
    @RelationID     INT,
    @PositionIDsCsv NVARCHAR(MAX) = NULL, -- e.g. '1,2,3' یا NULL/خالی برایِ پاک‌کردنِ همه
    @UserID         INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmPartyPersonRelations WHERE RelationID = @RelationID)
    BEGIN SELECT 0 AS Success, N'رابطه یافت نشد.' AS Message; RETURN; END

    DECLARE @Raw TABLE (Val NVARCHAR(50));
    INSERT INTO @Raw (Val)
    SELECT LTRIM(RTRIM(value)) FROM STRING_SPLIT(ISNULL(@PositionIDsCsv, N''), ',') WHERE NULLIF(LTRIM(RTRIM(value)), N'') IS NOT NULL;

    IF EXISTS (SELECT 1 FROM @Raw WHERE TRY_CAST(Val AS INT) IS NULL)
    BEGIN SELECT 0 AS Success, N'یک یا چند سمتِ انتخاب‌شده نامعتبر است.' AS Message; RETURN; END

    DECLARE @Positions TABLE (PositionID INT PRIMARY KEY);
    INSERT INTO @Positions (PositionID) SELECT DISTINCT CAST(Val AS INT) FROM @Raw;

    IF EXISTS (SELECT 1 FROM @Positions x WHERE NOT EXISTS (SELECT 1 FROM dbo.CrmPositions p WHERE p.PositionID = x.PositionID))
    BEGIN SELECT 0 AS Success, N'یک یا چند سمتِ انتخاب‌شده نامعتبر است.' AS Message; RETURN; END

    IF EXISTS (
        SELECT 1 FROM @Positions x
        JOIN dbo.CrmPositions p ON p.PositionID = x.PositionID AND p.IsActive = 0
        WHERE NOT EXISTS (SELECT 1 FROM dbo.CrmPartyPersonRelationPositions rp WHERE rp.RelationID = @RelationID AND rp.PositionID = x.PositionID)
    )
    BEGIN SELECT 0 AS Success, N'سمتِ غیرفعال را نمی‌توان به فرد مرتبط اضافه کرد.' AS Message; RETURN; END

    BEGIN TRY
        BEGIN TRAN;

        DELETE FROM dbo.CrmPartyPersonRelationPositions
        WHERE RelationID = @RelationID AND PositionID NOT IN (SELECT PositionID FROM @Positions);

        INSERT INTO dbo.CrmPartyPersonRelationPositions (RelationID, PositionID, Date_InsertFirst, UserID_InsertFirst)
        SELECT @RelationID, x.PositionID, SYSDATETIME(), @UserID
        FROM @Positions x
        WHERE NOT EXISTS (
            SELECT 1 FROM dbo.CrmPartyPersonRelationPositions rp WHERE rp.RelationID = @RelationID AND rp.PositionID = x.PositionID
        );

        COMMIT TRAN;
        SELECT 1 AS Success, N'سمت‌ها به‌روزرسانی شد.' AS Message;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRAN;
        SELECT 0 AS Success, N'خطا در ذخیرهٔ سمت‌ها: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SetCooperationEnded
-- پچِ ۱۲۰: ثبت/برداشتنِ «قطعِ همکاری» از صفحهٔ اصلیِ طرف‌حساب/مخاطب — فقط CooperationEndedAt (بقیهٔ فیلدها دست نمی‌خورند)
ALTER PROCEDURE dbo.sp_Crm_SetCooperationEnded
    @EntityType         NVARCHAR(10),          -- PARTY | PERSON
    @EntityID           INT,
    @CooperationEnded   BIT,                   -- ۱ = قطعِ همکاری در @CooperationEndedAt، ۰ = برقراریِ دوبارهٔ همکاری
    @CooperationEndedAt DATETIME2(0) = NULL,
    @UserID             INT
AS
BEGIN
    SET NOCOUNT ON;

    IF @EntityType NOT IN (N'PARTY', N'PERSON')
    BEGIN SELECT 0 AS Success, N'نوعِ رکورد نامعتبر است.' AS Message; RETURN; END
    IF @CooperationEnded IS NULL
    BEGIN SELECT 0 AS Success, N'وضعیتِ قطعِ همکاری نامعتبر است.' AS Message; RETURN; END
    IF @CooperationEnded = 1 AND @CooperationEndedAt IS NULL
    BEGIN SELECT 0 AS Success, N'تاریخ و ساعتِ قطعِ همکاری الزامی است.' AS Message; RETURN; END

    DECLARE @At DATETIME2(0) = CASE WHEN @CooperationEnded = 1 THEN @CooperationEndedAt END;

    IF @EntityType = N'PARTY'
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @EntityID)
        BEGIN SELECT 0 AS Success, N'طرف‌حساب یافت نشد.' AS Message; RETURN; END
        UPDATE dbo.CrmParties SET CooperationEndedAt = @At, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID WHERE PartyID = @EntityID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmPersons WHERE PersonID = @EntityID)
        BEGIN SELECT 0 AS Success, N'فرد مرتبط یافت نشد.' AS Message; RETURN; END
        UPDATE dbo.CrmPersons SET CooperationEndedAt = @At, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID WHERE PersonID = @EntityID;
    END

    SELECT 1 AS Success,
           CASE WHEN @CooperationEnded = 1 THEN N'قطعِ همکاری ثبت شد.' ELSE N'قطعِ همکاری برداشته شد.' END AS Message,
           @At AS CooperationEndedAt;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SetEntityUser
-- پچِ ۱۲۱: تعیین/برداشتنِ کاربرِ یک مخاطب یا طرف‌حساب — از صفحهٔ اصلیِ CRM.
-- @UserID = NULL: کاربرِ فعلی جدا و «داخلی» می‌شود؛ کاربرِ جدید نوعِ مخاطب/طرف‌حساب می‌گیرد و کاربرِ قبلیِ همین رکورد جدا می‌شود.
ALTER PROCEDURE dbo.sp_Crm_SetEntityUser
    @EntityType NVARCHAR(10),   -- PARTY | PERSON
    @EntityID   INT,
    @UserID     INT = NULL,
    @ModifyUser INT = NULL,
    @UserType   NVARCHAR(10) = NULL -- پچِ ۱۲۲: فقط برایِ مخاطب — INTERNAL یا PERSON (پیش‌فرض PERSON)؛ طرف‌حساب همیشه PARTY
AS
BEGIN
    SET NOCOUNT ON;

    IF @EntityType NOT IN (N'PARTY', N'PERSON')
    BEGIN SELECT 0 AS Success, N'نوعِ رکورد نامعتبر است.' AS Message; RETURN; END
    DECLARE @NewType NVARCHAR(10) = CASE WHEN @EntityType = N'PARTY' THEN N'PARTY' ELSE ISNULL(@UserType, N'PERSON') END;
    IF @EntityType = N'PERSON' AND @NewType NOT IN (N'INTERNAL', N'PERSON')
    BEGIN SELECT 0 AS Success, N'برایِ فرد مرتبط، نوعِ کاربر باید «داخلی» یا «فرد مرتبط» باشد.' AS Message; RETURN; END
    IF @EntityType = N'PARTY' AND NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @EntityID)
    BEGIN SELECT 0 AS Success, N'طرف‌حساب یافت نشد.' AS Message; RETURN; END
    IF @EntityType = N'PERSON' AND NOT EXISTS (SELECT 1 FROM dbo.CrmPersons WHERE PersonID = @EntityID)
    BEGIN SELECT 0 AS Success, N'فرد مرتبط یافت نشد.' AS Message; RETURN; END

    IF @UserID IS NOT NULL
    BEGIN
        DECLARE @Type NVARCHAR(10), @PersonID INT, @PartyID INT;
        SELECT @Type = UserType, @PersonID = CrmPersonID, @PartyID = CrmPartyID FROM dbo.Users WITH (UPDLOCK, HOLDLOCK) WHERE UserID = @UserID;
        IF @Type IS NULL
        BEGIN SELECT 0 AS Success, N'کاربر یافت نشد.' AS Message; RETURN; END
        -- کاربری که به مخاطب/طرف‌حسابِ دیگری متصل است، اول باید از آنجا جدا شود
        IF (@PersonID IS NOT NULL AND NOT (@EntityType = N'PERSON' AND @PersonID = @EntityID))
           OR (@PartyID IS NOT NULL AND NOT (@EntityType = N'PARTY' AND @PartyID = @EntityID))
        BEGIN
            SELECT 0 AS Success,
                   N'این کاربر قبلاً به ' + CASE WHEN @PersonID IS NOT NULL
                        THEN N'فردِ مرتبطِ «' + (SELECT LTRIM(FirstName + N' ' + LastName) FROM dbo.CrmPersons WHERE PersonID = @PersonID)
                        ELSE N'طرف‌حسابِ «' + (SELECT OfficialName FROM dbo.CrmParties WHERE PartyID = @PartyID) END
                   + N'» متصل است؛ ابتدا آن اتصال را بردارید.' AS Message;
            RETURN;
        END
    END

    BEGIN TRANSACTION;
    -- کاربرِ قبلیِ همین رکورد (اگر کسِ دیگری است) جدا و «داخلی» می‌شود
    UPDATE dbo.Users
    SET UserType = N'INTERNAL', CrmPersonID = NULL, CrmPartyID = NULL, ModifyDate = GETDATE(), ModifyUser = @ModifyUser -- کاربرِ قبلی: داخلی و بدونِ اتصال
    WHERE (@UserID IS NULL OR UserID <> @UserID)
      AND ((@EntityType = N'PERSON' AND CrmPersonID = @EntityID) OR (@EntityType = N'PARTY' AND CrmPartyID = @EntityID));

    IF @UserID IS NOT NULL
        UPDATE dbo.Users
        SET UserType = @NewType,
            CrmPersonID = CASE WHEN @EntityType = N'PERSON' THEN @EntityID END,
            CrmPartyID = CASE WHEN @EntityType = N'PARTY' THEN @EntityID END,
            ModifyDate = GETDATE(), ModifyUser = @ModifyUser
        WHERE UserID = @UserID;
    COMMIT TRANSACTION;

    SELECT 1 AS Success, CASE WHEN @UserID IS NULL THEN N'اتصالِ کاربر برداشته شد.' ELSE N'کاربر متصل شد.' END AS Message;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_SetUserCrmLink
-- پچِ ۱۲۱: تعیینِ نوعِ کاربر (داخلی/مخاطب/طرف‌حساب) و مخاطب/طرف‌حسابِ متصل — از فرمِ تعریفِ کاربر.
-- پچِ ۱۲۲: نوع جدا از رکوردِ متصل — داخلی: مخاطبِ اختیاری؛ مخاطب: مخاطبِ الزامی؛ طرف‌حساب: طرف‌حسابِ الزامی.
-- هر مخاطب/طرف‌حساب حداکثر به یک کاربر متصل است.
ALTER PROCEDURE dbo.sp_SetUserCrmLink
    @UserID      INT,
    @UserType    NVARCHAR(10),
    @CrmPersonID INT = NULL,
    @CrmPartyID  INT = NULL,
    @ModifyUser  INT = NULL
AS
BEGIN
    SET NOCOUNT ON;

    IF @UserType NOT IN (N'INTERNAL', N'PERSON', N'PARTY')
    BEGIN SELECT 0 AS Success, N'نوعِ کاربر نامعتبر است.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @UserID)
    BEGIN SELECT 0 AS Success, N'کاربر یافت نشد.' AS Message; RETURN; END

    IF @UserType = N'PARTY' SET @CrmPersonID = NULL;
    IF @UserType <> N'PARTY' SET @CrmPartyID = NULL;

    IF @UserType = N'PERSON' AND @CrmPersonID IS NULL
    BEGIN SELECT 0 AS Success, N'فردِ مرتبطِ این کاربر را انتخاب کنید.' AS Message; RETURN; END
    IF @UserType = N'PARTY' AND @CrmPartyID IS NULL
    BEGIN SELECT 0 AS Success, N'طرف‌حسابِ این کاربر را انتخاب کنید.' AS Message; RETURN; END
    IF @CrmPersonID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmPersons WHERE PersonID = @CrmPersonID)
    BEGIN SELECT 0 AS Success, N'فردِ مرتبطِ انتخاب‌شده یافت نشد.' AS Message; RETURN; END
    IF @CrmPartyID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @CrmPartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حسابِ انتخاب‌شده یافت نشد.' AS Message; RETURN; END

    DECLARE @Other NVARCHAR(200);
    SELECT TOP 1 @Other = ISNULL(NULLIF(LTRIM(RTRIM(FullName)), N''), UserName)
    FROM dbo.Users WITH (UPDLOCK, HOLDLOCK)
    WHERE UserID <> @UserID
      AND ((@CrmPersonID IS NOT NULL AND CrmPersonID = @CrmPersonID) OR (@CrmPartyID IS NOT NULL AND CrmPartyID = @CrmPartyID));
    IF @Other IS NOT NULL
    BEGIN
        SELECT 0 AS Success,
               CASE WHEN @CrmPersonID IS NOT NULL THEN N'این فرد مرتبط قبلاً به کاربرِ «' ELSE N'این طرف‌حساب قبلاً به کاربرِ «' END + @Other + N'» متصل شده است.' AS Message;
        RETURN;
    END

    UPDATE dbo.Users
    SET UserType = @UserType, CrmPersonID = @CrmPersonID, CrmPartyID = @CrmPartyID, ModifyDate = GETDATE(), ModifyUser = @ModifyUser
    WHERE UserID = @UserID;

    SELECT 1 AS Success, N'نوعِ کاربر ذخیره شد.' AS Message;
END
GO

