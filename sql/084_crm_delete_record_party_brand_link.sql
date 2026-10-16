/* ==========================================================================
   پچ خودکار شماره: 084 | نام: crm_delete_record_party_brand_link
   تاریخ: 2026-10-12 19:58:23 | شامل 1 دستور SQL
   ========================================================================== */

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_DeleteRecord
-- حذفِ عمومیِ رکوردهایِ CRM (دسترسیِ CRM_DELETE در لایهٔ PHP کنترل می‌شود).
-- قاعده: اگر رکورد جایِ دیگری استفاده شده باشد (هر FKِ ارجاع‌دهنده، از جمله Projects) حذف انجام نمی‌شود
-- و پیام فهرستِ بخش‌هایِ وابسته را برمی‌گرداند. فقط انتخاب‌هایِ چندگانهٔ خودِ رکورد (نقش/سمتِ طرف‌حساب،
-- نقش/سمتِ مخاطب)، اطلاعاتِ تکمیلیِ ۱:۱ِ طرف‌حساب و ارتباطِ داخلیِ بدونِ ردیفِ Party↔Brand همراهِ آن حذف می‌شوند.
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
        (N'RELATION',             N'CrmPartyPersonRelations',         N'مخاطب'),
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
        (NULL, N'CrmInteractionAttachments',       N'پیوست‌هایِ تعامل'),
        (NULL, N'CrmPartyRoles',                   N'نقش‌هایِ طرف‌حساب'),
        (NULL, N'CrmPartyPositions',               N'سمت‌هایِ طرف‌حساب'),
        (NULL, N'CrmPartyPersonRelationRoles',     N'نقش‌هایِ مخاطب'),
        (NULL, N'CrmPartyPersonRelationPositions', N'سمت‌هایِ مخاطب'),
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

