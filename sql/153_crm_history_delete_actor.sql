SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO
IF OBJECT_ID('dbo.CrmRecordHistory','U') IS NULL THROW 51000,'Requires patch 152',1;
GO
CREATE OR ALTER PROCEDURE dbo.sp_Crm_DeleteRecord
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

    DECLARE @PreviousDeleteActor sql_variant = SESSION_CONTEXT(N'CrmDeleteActor');
    BEGIN TRY
        BEGIN TRAN;
        EXEC sys.sp_set_session_context @key=N'CrmDeleteActor', @value=@UserID;

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

        EXEC sys.sp_set_session_context @key=N'CrmDeleteActor', @value=@PreviousDeleteActor;
        COMMIT TRAN;
        SELECT 1 AS Success, N'«' + @Label + N'» حذف شد.' AS Message;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRAN;
        EXEC sys.sp_set_session_context @key=N'CrmDeleteActor', @value=@PreviousDeleteActor;
        SELECT 0 AS Success, N'خطا در حذفِ «' + @Label + N'»: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO
CREATE OR ALTER TRIGGER dbo.tr_CrmParties_RecordHistory ON dbo.[CrmParties]
AFTER INSERT, UPDATE, DELETE AS
BEGIN
 SET NOCOUNT ON;
 INSERT dbo.CrmRecordHistory(EntityType,EntityID,SourceTable,RecordID,ActionType,ChangedBy,FieldName,OldValue,NewValue)
 SELECT scope.EntityType,scope.EntityID,N'CrmParties',COALESCE(i.[PartyID],d.[PartyID]),
 CASE WHEN i.[PartyID] IS NULL THEN 'DELETE' WHEN d.[PartyID] IS NULL THEN 'INSERT' ELSE 'UPDATE' END,
 CASE WHEN d.[PartyID] IS NULL THEN i.UserID_InsertFirst WHEN i.[PartyID] IS NOT NULL THEN i.UserID_LastUpdate ELSE TRY_CONVERT(int,SESSION_CONTEXT(N'CrmDeleteActor')) END,
 f.FieldName,f.OldValue,f.NewValue
 FROM inserted i FULL JOIN deleted d ON d.[PartyID]=i.[PartyID]
 CROSS APPLY (SELECT DISTINCT EntityType,EntityID FROM (VALUES ('PARTY',i.[PartyID]),('PARTY',d.[PartyID])) v(EntityType,EntityID) WHERE EntityID IS NOT NULL) scope
 CROSS APPLY (VALUES (N'OfficialName',CONVERT(nvarchar(max),d.[OfficialName]),CONVERT(nvarchar(max),i.[OfficialName])),
(N'TradeName',CONVERT(nvarchar(max),d.[TradeName]),CONVERT(nvarchar(max),i.[TradeName])),
(N'RegistrationNumber',CONVERT(nvarchar(max),d.[RegistrationNumber]),CONVERT(nvarchar(max),i.[RegistrationNumber])),
(N'EconomicCode',CONVERT(nvarchar(max),d.[EconomicCode]),CONVERT(nvarchar(max),i.[EconomicCode])),
(N'IdentifierNumber',CONVERT(nvarchar(max),d.[IdentifierNumber]),CONVERT(nvarchar(max),i.[IdentifierNumber])),
(N'IdentifierDate',CONVERT(nvarchar(max),d.[IdentifierDate]),CONVERT(nvarchar(max),i.[IdentifierDate])),
(N'Description',CONVERT(nvarchar(max),d.[Description]),CONVERT(nvarchar(max),i.[Description])),
(N'IsActive',CONVERT(nvarchar(max),d.[IsActive]),CONVERT(nvarchar(max),i.[IsActive])),
(N'DepartmentID',CONVERT(nvarchar(max),d.[DepartmentID]),CONVERT(nvarchar(max),i.[DepartmentID])),
(N'PartyTypeID',CONVERT(nvarchar(max),d.[PartyTypeID]),CONVERT(nvarchar(max),i.[PartyTypeID])),
(N'ActivityID',CONVERT(nvarchar(max),d.[ActivityID]),CONVERT(nvarchar(max),i.[ActivityID])),
(N'PartyNature',CONVERT(nvarchar(max),d.[PartyNature]),CONVERT(nvarchar(max),i.[PartyNature])),
(N'CooperationEndedAt',CONVERT(nvarchar(max),d.[CooperationEndedAt]),CONVERT(nvarchar(max),i.[CooperationEndedAt])),
(N'Identifier1',CONVERT(nvarchar(max),d.[Identifier1]),CONVERT(nvarchar(max),i.[Identifier1])),
(N'Identifier2',CONVERT(nvarchar(max),d.[Identifier2]),CONVERT(nvarchar(max),i.[Identifier2]))) f(FieldName,OldValue,NewValue)
 WHERE EXISTS (SELECT f.OldValue COLLATE Latin1_General_100_BIN2,DATALENGTH(f.OldValue) EXCEPT SELECT f.NewValue COLLATE Latin1_General_100_BIN2,DATALENGTH(f.NewValue));
END
GO
CREATE OR ALTER TRIGGER dbo.tr_CrmPersons_RecordHistory ON dbo.[CrmPersons]
AFTER INSERT, UPDATE, DELETE AS
BEGIN
 SET NOCOUNT ON;
 INSERT dbo.CrmRecordHistory(EntityType,EntityID,SourceTable,RecordID,ActionType,ChangedBy,FieldName,OldValue,NewValue)
 SELECT scope.EntityType,scope.EntityID,N'CrmPersons',COALESCE(i.[PersonID],d.[PersonID]),
 CASE WHEN i.[PersonID] IS NULL THEN 'DELETE' WHEN d.[PersonID] IS NULL THEN 'INSERT' ELSE 'UPDATE' END,
 CASE WHEN d.[PersonID] IS NULL THEN i.UserID_InsertFirst WHEN i.[PersonID] IS NOT NULL THEN i.UserID_LastUpdate ELSE TRY_CONVERT(int,SESSION_CONTEXT(N'CrmDeleteActor')) END,
 f.FieldName,f.OldValue,f.NewValue
 FROM inserted i FULL JOIN deleted d ON d.[PersonID]=i.[PersonID]
 CROSS APPLY (SELECT DISTINCT EntityType,EntityID FROM (VALUES ('PERSON',i.[PersonID]),('PERSON',d.[PersonID])) v(EntityType,EntityID) WHERE EntityID IS NOT NULL) scope
 CROSS APPLY (VALUES (N'FirstName',CONVERT(nvarchar(max),d.[FirstName]),CONVERT(nvarchar(max),i.[FirstName])),
(N'LastName',CONVERT(nvarchar(max),d.[LastName]),CONVERT(nvarchar(max),i.[LastName])),
(N'IsActive',CONVERT(nvarchar(max),d.[IsActive]),CONVERT(nvarchar(max),i.[IsActive])),
(N'TitleID',CONVERT(nvarchar(max),d.[TitleID]),CONVERT(nvarchar(max),i.[TitleID])),
(N'IdentifierNumber',CONVERT(nvarchar(max),d.[IdentifierNumber]),CONVERT(nvarchar(max),i.[IdentifierNumber])),
(N'IdentifierDate',CONVERT(nvarchar(max),d.[IdentifierDate]),CONVERT(nvarchar(max),i.[IdentifierDate])),
(N'Description',CONVERT(nvarchar(max),d.[Description]),CONVERT(nvarchar(max),i.[Description])),
(N'CooperationEndedAt',CONVERT(nvarchar(max),d.[CooperationEndedAt]),CONVERT(nvarchar(max),i.[CooperationEndedAt]))) f(FieldName,OldValue,NewValue)
 WHERE EXISTS (SELECT f.OldValue COLLATE Latin1_General_100_BIN2,DATALENGTH(f.OldValue) EXCEPT SELECT f.NewValue COLLATE Latin1_General_100_BIN2,DATALENGTH(f.NewValue));
END
GO
CREATE OR ALTER TRIGGER dbo.tr_CrmContacts_RecordHistory ON dbo.[CrmContacts]
AFTER INSERT, UPDATE, DELETE AS
BEGIN
 SET NOCOUNT ON;
 INSERT dbo.CrmRecordHistory(EntityType,EntityID,SourceTable,RecordID,ActionType,ChangedBy,FieldName,OldValue,NewValue)
 SELECT scope.EntityType,scope.EntityID,N'CrmContacts',COALESCE(i.[ContactID],d.[ContactID]),
 CASE WHEN i.[ContactID] IS NULL THEN 'DELETE' WHEN d.[ContactID] IS NULL THEN 'INSERT' ELSE 'UPDATE' END,
 CASE WHEN d.[ContactID] IS NULL THEN i.UserID_InsertFirst WHEN i.[ContactID] IS NOT NULL THEN i.UserID_LastUpdate ELSE TRY_CONVERT(int,SESSION_CONTEXT(N'CrmDeleteActor')) END,
 f.FieldName,f.OldValue,f.NewValue
 FROM inserted i FULL JOIN deleted d ON d.[ContactID]=i.[ContactID]
 CROSS APPLY (SELECT DISTINCT EntityType,EntityID FROM (VALUES ('PARTY',i.[PartyID]),('PARTY',d.[PartyID]),('PERSON',i.[PersonID]),('PERSON',d.[PersonID])) v(EntityType,EntityID) WHERE EntityID IS NOT NULL) scope
 CROSS APPLY (VALUES (N'PartyID',CONVERT(nvarchar(max),d.[PartyID]),CONVERT(nvarchar(max),i.[PartyID])),
(N'ContactTypeID',CONVERT(nvarchar(max),d.[ContactTypeID]),CONVERT(nvarchar(max),i.[ContactTypeID])),
(N'ContactValue',CONVERT(nvarchar(max),d.[ContactValue]),CONVERT(nvarchar(max),i.[ContactValue])),
(N'Extension',CONVERT(nvarchar(max),d.[Extension]),CONVERT(nvarchar(max),i.[Extension])),
(N'Description',CONVERT(nvarchar(max),d.[Description]),CONVERT(nvarchar(max),i.[Description])),
(N'IsPrimary',CONVERT(nvarchar(max),d.[IsPrimary]),CONVERT(nvarchar(max),i.[IsPrimary])),
(N'IsActive',CONVERT(nvarchar(max),d.[IsActive]),CONVERT(nvarchar(max),i.[IsActive])),
(N'PersonID',CONVERT(nvarchar(max),d.[PersonID]),CONVERT(nvarchar(max),i.[PersonID])),
(N'RelatedPersonID',CONVERT(nvarchar(max),d.[RelatedPersonID]),CONVERT(nvarchar(max),i.[RelatedPersonID]))) f(FieldName,OldValue,NewValue)
 WHERE EXISTS (SELECT f.OldValue COLLATE Latin1_General_100_BIN2,DATALENGTH(f.OldValue) EXCEPT SELECT f.NewValue COLLATE Latin1_General_100_BIN2,DATALENGTH(f.NewValue));
END
GO
CREATE OR ALTER TRIGGER dbo.tr_CrmAddresses_RecordHistory ON dbo.[CrmAddresses]
AFTER INSERT, UPDATE, DELETE AS
BEGIN
 SET NOCOUNT ON;
 INSERT dbo.CrmRecordHistory(EntityType,EntityID,SourceTable,RecordID,ActionType,ChangedBy,FieldName,OldValue,NewValue)
 SELECT scope.EntityType,scope.EntityID,N'CrmAddresses',COALESCE(i.[AddressID],d.[AddressID]),
 CASE WHEN i.[AddressID] IS NULL THEN 'DELETE' WHEN d.[AddressID] IS NULL THEN 'INSERT' ELSE 'UPDATE' END,
 CASE WHEN d.[AddressID] IS NULL THEN i.UserID_InsertFirst WHEN i.[AddressID] IS NOT NULL THEN i.UserID_LastUpdate ELSE TRY_CONVERT(int,SESSION_CONTEXT(N'CrmDeleteActor')) END,
 f.FieldName,f.OldValue,f.NewValue
 FROM inserted i FULL JOIN deleted d ON d.[AddressID]=i.[AddressID]
 CROSS APPLY (SELECT DISTINCT EntityType,EntityID FROM (VALUES ('PARTY',i.[PartyID]),('PARTY',d.[PartyID]),('PERSON',i.[PersonID]),('PERSON',d.[PersonID])) v(EntityType,EntityID) WHERE EntityID IS NOT NULL) scope
 CROSS APPLY (VALUES (N'PartyID',CONVERT(nvarchar(max),d.[PartyID]),CONVERT(nvarchar(max),i.[PartyID])),
(N'AddressTitleID',CONVERT(nvarchar(max),d.[AddressTitleID]),CONVERT(nvarchar(max),i.[AddressTitleID])),
(N'ProvinceID',CONVERT(nvarchar(max),d.[ProvinceID]),CONVERT(nvarchar(max),i.[ProvinceID])),
(N'CountyID',CONVERT(nvarchar(max),d.[CountyID]),CONVERT(nvarchar(max),i.[CountyID])),
(N'CityID',CONVERT(nvarchar(max),d.[CityID]),CONVERT(nvarchar(max),i.[CityID])),
(N'MunicipalZoneID',CONVERT(nvarchar(max),d.[MunicipalZoneID]),CONVERT(nvarchar(max),i.[MunicipalZoneID])),
(N'PostalCode',CONVERT(nvarchar(max),d.[PostalCode]),CONVERT(nvarchar(max),i.[PostalCode])),
(N'AddressText',CONVERT(nvarchar(max),d.[AddressText]),CONVERT(nvarchar(max),i.[AddressText])),
(N'PlateNumber',CONVERT(nvarchar(max),d.[PlateNumber]),CONVERT(nvarchar(max),i.[PlateNumber])),
(N'Unit',CONVERT(nvarchar(max),d.[Unit]),CONVERT(nvarchar(max),i.[Unit])),
(N'IsActive',CONVERT(nvarchar(max),d.[IsActive]),CONVERT(nvarchar(max),i.[IsActive])),
(N'PersonID',CONVERT(nvarchar(max),d.[PersonID]),CONVERT(nvarchar(max),i.[PersonID])),
(N'NeighborhoodID',CONVERT(nvarchar(max),d.[NeighborhoodID]),CONVERT(nvarchar(max),i.[NeighborhoodID])),
(N'Latitude',CONVERT(nvarchar(max),d.[Latitude]),CONVERT(nvarchar(max),i.[Latitude])),
(N'Longitude',CONVERT(nvarchar(max),d.[Longitude]),CONVERT(nvarchar(max),i.[Longitude]))) f(FieldName,OldValue,NewValue)
 WHERE EXISTS (SELECT f.OldValue COLLATE Latin1_General_100_BIN2,DATALENGTH(f.OldValue) EXCEPT SELECT f.NewValue COLLATE Latin1_General_100_BIN2,DATALENGTH(f.NewValue));
END
GO
CREATE OR ALTER TRIGGER dbo.tr_CrmPartyPersonRelations_RecordHistory ON dbo.[CrmPartyPersonRelations]
AFTER INSERT, UPDATE, DELETE AS
BEGIN
 SET NOCOUNT ON;
 INSERT dbo.CrmRecordHistory(EntityType,EntityID,SourceTable,RecordID,ActionType,ChangedBy,FieldName,OldValue,NewValue)
 SELECT scope.EntityType,scope.EntityID,N'CrmPartyPersonRelations',COALESCE(i.[RelationID],d.[RelationID]),
 CASE WHEN i.[RelationID] IS NULL THEN 'DELETE' WHEN d.[RelationID] IS NULL THEN 'INSERT' ELSE 'UPDATE' END,
 CASE WHEN d.[RelationID] IS NULL THEN i.UserID_InsertFirst WHEN i.[RelationID] IS NOT NULL THEN i.UserID_LastUpdate ELSE TRY_CONVERT(int,SESSION_CONTEXT(N'CrmDeleteActor')) END,
 f.FieldName,f.OldValue,f.NewValue
 FROM inserted i FULL JOIN deleted d ON d.[RelationID]=i.[RelationID]
 CROSS APPLY (SELECT DISTINCT EntityType,EntityID FROM (VALUES ('PARTY',i.[PartyID]),('PARTY',d.[PartyID]),('PERSON',i.[PersonID]),('PERSON',d.[PersonID])) v(EntityType,EntityID) WHERE EntityID IS NOT NULL) scope
 CROSS APPLY (VALUES (N'PartyID',CONVERT(nvarchar(max),d.[PartyID]),CONVERT(nvarchar(max),i.[PartyID])),
(N'PersonID',CONVERT(nvarchar(max),d.[PersonID]),CONVERT(nvarchar(max),i.[PersonID])),
(N'PositionID',CONVERT(nvarchar(max),d.[PositionID]),CONVERT(nvarchar(max),i.[PositionID])),
(N'IsPrimaryContact',CONVERT(nvarchar(max),d.[IsPrimaryContact]),CONVERT(nvarchar(max),i.[IsPrimaryContact])),
(N'IsActive',CONVERT(nvarchar(max),d.[IsActive]),CONVERT(nvarchar(max),i.[IsActive]))) f(FieldName,OldValue,NewValue)
 WHERE EXISTS (SELECT f.OldValue COLLATE Latin1_General_100_BIN2,DATALENGTH(f.OldValue) EXCEPT SELECT f.NewValue COLLATE Latin1_General_100_BIN2,DATALENGTH(f.NewValue));
END
GO
