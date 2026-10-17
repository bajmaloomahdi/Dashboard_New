/* ==========================================================================
   پچ خودکار شماره: 089 | نام: crm_party_attachments
   تاریخ: 2026-10-17 08:58:30 | شامل 7 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: CrmPartyAttachments
CREATE TABLE dbo.CrmPartyAttachments
(
    PartyAttachmentID  INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmPartyAttachments PRIMARY KEY,
    PartyID            INT              NOT NULL,
    FileName           NVARCHAR(255)    NOT NULL,
    FileExtension      NVARCHAR(20)     NULL,
    FileSize           BIGINT           NOT NULL,
    FilePath           NVARCHAR(1000)   NOT NULL,
    Description        NVARCHAR(500)    NULL,
    IsActive           BIT              NOT NULL CONSTRAINT DF_CrmPartyAttachments_IsActive DEFAULT (1),
    RowGuid            UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmPartyAttachments_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2        NOT NULL CONSTRAINT DF_CrmPartyAttachments_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT              NULL,
    Date_LastUpdate    DATETIME2        NULL,
    UserID_LastUpdate  INT              NULL,
    CONSTRAINT FK_CrmPartyAttachments_Party FOREIGN KEY (PartyID) REFERENCES dbo.CrmParties(PartyID),
    CONSTRAINT CK_CrmPartyAttachments_FileSize CHECK (FileSize >= 0)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmPartyAttachments_PartyID
CREATE INDEX IX_CrmPartyAttachments_PartyID ON dbo.CrmPartyAttachments(PartyID, IsActive)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetPartyAttachments
-- فهرستِ فایل‌هایِ پیوستِ فعالِ طرف‌حساب (با FilePath فقط برایِ لایهٔ PHP؛ PHP آن را حذف می‌کند)
CREATE PROCEDURE dbo.sp_Crm_GetPartyAttachments
    @PartyID           INT = NULL,
    @PartyAttachmentID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT a.PartyAttachmentID, a.PartyID, a.FileName, a.FileExtension, a.FileSize, a.FilePath, a.Description,
           a.Date_InsertFirst, a.UserID_InsertFirst,
           (cu.FirstName + N' ' + cu.LastName) AS CreatedByName
    FROM dbo.CrmPartyAttachments a
    LEFT JOIN dbo.Users cu ON cu.UserID = a.UserID_InsertFirst
    WHERE a.IsActive = 1
      AND (@PartyID           IS NULL OR a.PartyID           = @PartyID)
      AND (@PartyAttachmentID IS NULL OR a.PartyAttachmentID = @PartyAttachmentID)
    ORDER BY a.PartyAttachmentID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_AddPartyAttachment
CREATE PROCEDURE dbo.sp_Crm_AddPartyAttachment
    @PartyID       INT,
    @FileName      NVARCHAR(255),
    @FileExtension NVARCHAR(20)  = NULL,
    @FileSize      BIGINT,
    @FilePath      NVARCHAR(1000),
    @Description   NVARCHAR(500) = NULL,
    @UserID        INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حساب یافت نشد.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@FileName)), N'') IS NULL OR NULLIF(LTRIM(RTRIM(@FilePath)), N'') IS NULL OR @FileSize IS NULL OR @FileSize < 0
    BEGIN SELECT 0 AS Success, N'اطلاعاتِ فایلِ پیوست ناقص است.' AS Message; RETURN; END

    INSERT INTO dbo.CrmPartyAttachments (PartyID, FileName, FileExtension, FileSize, FilePath, Description, IsActive, Date_InsertFirst, UserID_InsertFirst)
    VALUES (@PartyID, @FileName, NULLIF(@FileExtension, N''), @FileSize, @FilePath, NULLIF(LTRIM(RTRIM(@Description)), N''), 1, SYSDATETIME(), @UserID);

    SELECT 1 AS Success, N'فایل ثبت شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS PartyAttachmentID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_DeletePartyAttachment
-- حذفِ منطقی (IsActive=0)؛ فایلِ فیزیکی رویِ دیسک باقی می‌ماند (هم‌الگو با sp_Crm_DeletePartyImage)
CREATE PROCEDURE dbo.sp_Crm_DeletePartyAttachment
    @PartyAttachmentID INT,
    @UserID            INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmPartyAttachments WHERE PartyAttachmentID = @PartyAttachmentID AND IsActive = 1)
    BEGIN SELECT 0 AS Success, N'فایل یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmPartyAttachments
    SET IsActive = 0, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE PartyAttachmentID = @PartyAttachmentID;

    SELECT 1 AS Success, N'فایل حذف شد.' AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_UpdatePartyAttachmentDescription
CREATE PROCEDURE dbo.sp_Crm_UpdatePartyAttachmentDescription
    @PartyAttachmentID INT,
    @Description       NVARCHAR(500) = NULL,
    @UserID            INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmPartyAttachments WHERE PartyAttachmentID = @PartyAttachmentID AND IsActive = 1)
    BEGIN SELECT 0 AS Success, N'فایل یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmPartyAttachments
    SET Description = NULLIF(LTRIM(RTRIM(@Description)), N''),
        Date_LastUpdate = SYSDATETIME(),
        UserID_LastUpdate = @UserID
    WHERE PartyAttachmentID = @PartyAttachmentID;

    SELECT 1 AS Success, N'توضیحاتِ فایل به‌روزرسانی شد.' AS Message;
END
GO

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
        (NULL, N'CrmPartyAttachments',             N'فایل‌هایِ پیوست'),
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

