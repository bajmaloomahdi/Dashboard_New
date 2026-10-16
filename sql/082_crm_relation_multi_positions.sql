/* ==========================================================================
   پچ خودکار شماره: 082 | نام: crm_relation_multi_positions
   تاریخ: 2026-10-12 19:33:58 | شامل 6 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: CrmPartyPersonRelationPositions
CREATE TABLE dbo.CrmPartyPersonRelationPositions
(
    RelationPositionID INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmPartyPersonRelationPositions PRIMARY KEY,
    RelationID         INT NOT NULL,
    PositionID         INT NOT NULL,
    RowGuid            UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmPartyPersonRelationPositions_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmPartyPersonRelationPositions_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate    DATETIME2 NULL,
    UserID_LastUpdate  INT NULL,
    CONSTRAINT UQ_CrmPartyPersonRelationPositions_Relation_Position UNIQUE (RelationID, PositionID),
    CONSTRAINT FK_CrmPartyPersonRelationPositions_Relation FOREIGN KEY (RelationID) REFERENCES dbo.CrmPartyPersonRelations(RelationID),
    CONSTRAINT FK_CrmPartyPersonRelationPositions_Position FOREIGN KEY (PositionID) REFERENCES dbo.CrmPositions(PositionID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmPartyPersonRelationPositions_PositionID
CREATE INDEX IX_CrmPartyPersonRelationPositions_PositionID ON dbo.CrmPartyPersonRelationPositions(PositionID)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetRelationPositionIds
CREATE PROCEDURE dbo.sp_Crm_GetRelationPositionIds
    @RelationID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT PositionID FROM dbo.CrmPartyPersonRelationPositions WHERE RelationID = @RelationID ORDER BY PositionID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SaveRelationPositions
-- سمت‌هایِ چندگانهٔ یک مخاطب (رابطهٔ طرف‌حساب↔شخص) — Sync کامل از CSV، هم‌شکلِ sp_Crm_SaveRelationRoles.
-- سمتِ غیرفعالِ جدید پذیرفته نمی‌شود؛ سمتِ غیرفعالی که از قبل رویِ رابطه بوده حفظ می‌شود.
CREATE PROCEDURE dbo.sp_Crm_SaveRelationPositions
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
    BEGIN SELECT 0 AS Success, N'سمتِ غیرفعال را نمی‌توان به مخاطب اضافه کرد.' AS Message; RETURN; END

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

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetPartyRelations
ALTER PROCEDURE dbo.sp_Crm_GetPartyRelations
    @PartyID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT r.RelationID, r.PartyID, r.PersonID, r.PositionID, r.IsPrimaryContact, r.IsActive,
           r.Date_InsertFirst, r.UserID_InsertFirst, r.Date_LastUpdate, r.UserID_LastUpdate,
           per.FirstName AS PersonFirstName, per.LastName AS PersonLastName,
           (per.FirstName + N' ' + per.LastName) AS PersonName,
           (
               SELECT STRING_AGG(pos.DisplayName, N'، ') WITHIN GROUP (ORDER BY CASE WHEN pos.SortOrder > 0 THEN pos.SortOrder ELSE CAST(pos.Code AS INT) END, CAST(pos.Code AS INT), pos.PositionID)
               FROM dbo.CrmPartyPersonRelationPositions rp
               JOIN dbo.CrmPositions pos ON pos.PositionID = rp.PositionID
               WHERE rp.RelationID = r.RelationID
           ) AS PositionName,
           (
               SELECT STRING_AGG(cr.DisplayName, N'، ') WITHIN GROUP (ORDER BY CASE WHEN cr.SortOrder > 0 THEN cr.SortOrder ELSE CAST(cr.Code AS INT) END, CAST(cr.Code AS INT), cr.ContactRoleID)
               FROM dbo.CrmPartyPersonRelationRoles rr
               JOIN dbo.CrmContactRoles cr ON cr.ContactRoleID = rr.RoleID
               WHERE rr.RelationID = r.RelationID
           ) AS RoleNames
    FROM dbo.CrmPartyPersonRelations r
    JOIN dbo.CrmPersons per ON per.PersonID = r.PersonID
    WHERE r.PartyID = @PartyID
    ORDER BY r.IsPrimaryContact DESC, r.RelationID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetPersonRelations
ALTER PROCEDURE dbo.sp_Crm_GetPersonRelations
    @PersonID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT r.RelationID, r.PartyID, r.PersonID, r.PositionID, r.IsPrimaryContact, r.IsActive,
           r.Date_InsertFirst, r.UserID_InsertFirst, r.Date_LastUpdate, r.UserID_LastUpdate,
           p.PartyNature, ISNULL(p.OfficialName, N'') AS PartyDisplayName,
           (
               SELECT STRING_AGG(pos.DisplayName, N'، ') WITHIN GROUP (ORDER BY CASE WHEN pos.SortOrder > 0 THEN pos.SortOrder ELSE CAST(pos.Code AS INT) END, CAST(pos.Code AS INT), pos.PositionID)
               FROM dbo.CrmPartyPersonRelationPositions rp
               JOIN dbo.CrmPositions pos ON pos.PositionID = rp.PositionID
               WHERE rp.RelationID = r.RelationID
           ) AS PositionName,
           (
               SELECT STRING_AGG(cr.DisplayName, N'، ') WITHIN GROUP (ORDER BY CASE WHEN cr.SortOrder > 0 THEN cr.SortOrder ELSE CAST(cr.Code AS INT) END, CAST(cr.Code AS INT), cr.ContactRoleID)
               FROM dbo.CrmPartyPersonRelationRoles rr
               JOIN dbo.CrmContactRoles cr ON cr.ContactRoleID = rr.RoleID
               WHERE rr.RelationID = r.RelationID
           ) AS RoleNames
    FROM dbo.CrmPartyPersonRelations r
    JOIN dbo.CrmParties p ON p.PartyID = r.PartyID
    WHERE r.PersonID = @PersonID
    ORDER BY r.IsPrimaryContact DESC, r.RelationID;
END
GO

-- [DML — دستی، خارج از DDL Trigger] مهاجرتِ سمتِ تک‌مقداریِ فعلیِ هر مخاطب (CrmPartyPersonRelations.PositionID)
-- به جدولِ چندمقداریِ CrmPartyPersonRelationPositions، تا داده‌ای از بین نرود. ستونِ قدیمیِ PositionID حذف نشده
-- و همچنان اولین سمتِ انتخاب‌شده در آن نوشته می‌شود؛ منبعِ نمایش و ویرایش از این پس جدولِ جدید است.
INSERT INTO dbo.CrmPartyPersonRelationPositions (RelationID, PositionID, Date_InsertFirst, UserID_InsertFirst)
SELECT r.RelationID, r.PositionID, SYSDATETIME(), r.UserID_InsertFirst
FROM dbo.CrmPartyPersonRelations r
WHERE r.PositionID IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM dbo.CrmPartyPersonRelationPositions rp WHERE rp.RelationID = r.RelationID AND rp.PositionID = r.PositionID)
GO

