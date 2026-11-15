IF OBJECT_ID('dbo.CrmRecordHistory','U') IS NULL
BEGIN
 CREATE TABLE dbo.CrmRecordHistory (
 HistoryID bigint IDENTITY PRIMARY KEY, EntityType varchar(6) NOT NULL, EntityID int NOT NULL,
 SourceTable sysname NOT NULL, RecordID int NOT NULL, ActionType varchar(6) NOT NULL,
 ChangedAt datetime2 NOT NULL DEFAULT SYSDATETIME(), ChangedBy int NULL,
 FieldName sysname NOT NULL, OldValue nvarchar(max) NULL, NewValue nvarchar(max) NULL
 );
 CREATE INDEX IX_CrmRecordHistory_Entity ON dbo.CrmRecordHistory(EntityType,EntityID,HistoryID DESC);
END
GO
CREATE OR ALTER TRIGGER dbo.tr_CrmParties_RecordHistory ON dbo.[CrmParties]
AFTER INSERT, UPDATE, DELETE AS
BEGIN
 SET NOCOUNT ON;
 INSERT dbo.CrmRecordHistory(EntityType,EntityID,SourceTable,RecordID,ActionType,ChangedBy,FieldName,OldValue,NewValue)
 SELECT scope.EntityType,scope.EntityID,N'CrmParties',COALESCE(i.[PartyID],d.[PartyID]),
 CASE WHEN i.[PartyID] IS NULL THEN 'DELETE' WHEN d.[PartyID] IS NULL THEN 'INSERT' ELSE 'UPDATE' END,
 CASE WHEN d.[PartyID] IS NULL THEN i.UserID_InsertFirst WHEN i.[PartyID] IS NOT NULL THEN i.UserID_LastUpdate END,
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
 CASE WHEN d.[PersonID] IS NULL THEN i.UserID_InsertFirst WHEN i.[PersonID] IS NOT NULL THEN i.UserID_LastUpdate END,
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
 CASE WHEN d.[ContactID] IS NULL THEN i.UserID_InsertFirst WHEN i.[ContactID] IS NOT NULL THEN i.UserID_LastUpdate END,
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
 CASE WHEN d.[AddressID] IS NULL THEN i.UserID_InsertFirst WHEN i.[AddressID] IS NOT NULL THEN i.UserID_LastUpdate END,
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
 CASE WHEN d.[RelationID] IS NULL THEN i.UserID_InsertFirst WHEN i.[RelationID] IS NOT NULL THEN i.UserID_LastUpdate END,
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
CREATE OR ALTER PROCEDURE dbo.sp_Crm_GetRecordHistory @EntityType varchar(6), @EntityID int, @Before bigint=NULL
AS
BEGIN
 SET NOCOUNT ON;
 SELECT TOP(51) h.*, u.FullName AS ActorName
 FROM dbo.CrmRecordHistory h LEFT JOIN dbo.Users u ON u.UserID=h.ChangedBy
 WHERE h.EntityType=@EntityType AND h.EntityID=@EntityID AND (@Before IS NULL OR h.HistoryID<@Before)
 ORDER BY h.HistoryID DESC;
END
GO
