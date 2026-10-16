/* ==========================================================================
   پچ خودکار شماره: 080 | نام: crm_party_roles_positions
   تاریخ: 2026-10-12 14:10:02 | شامل 8 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: CrmPartyRoles
CREATE TABLE dbo.CrmPartyRoles
(
    PartyRoleID        INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmPartyRoles PRIMARY KEY,
    PartyID            INT NOT NULL,
    RoleID             INT NOT NULL,
    RowGuid            UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmPartyRoles_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmPartyRoles_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate    DATETIME2 NULL,
    UserID_LastUpdate  INT NULL,
    CONSTRAINT UQ_CrmPartyRoles_Party_Role UNIQUE (PartyID, RoleID),
    CONSTRAINT FK_CrmPartyRoles_Party FOREIGN KEY (PartyID) REFERENCES dbo.CrmParties(PartyID),
    CONSTRAINT FK_CrmPartyRoles_Role FOREIGN KEY (RoleID) REFERENCES dbo.CrmContactRoles(ContactRoleID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmPartyRoles_RoleID
CREATE INDEX IX_CrmPartyRoles_RoleID ON dbo.CrmPartyRoles(RoleID)
GO

-- [CREATE_TABLE] روی TABLE: CrmPartyPositions
CREATE TABLE dbo.CrmPartyPositions
(
    PartyPositionID    INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmPartyPositions PRIMARY KEY,
    PartyID            INT NOT NULL,
    PositionID         INT NOT NULL,
    RowGuid            UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmPartyPositions_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmPartyPositions_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate    DATETIME2 NULL,
    UserID_LastUpdate  INT NULL,
    CONSTRAINT UQ_CrmPartyPositions_Party_Position UNIQUE (PartyID, PositionID),
    CONSTRAINT FK_CrmPartyPositions_Party FOREIGN KEY (PartyID) REFERENCES dbo.CrmParties(PartyID),
    CONSTRAINT FK_CrmPartyPositions_Position FOREIGN KEY (PositionID) REFERENCES dbo.CrmPositions(PositionID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmPartyPositions_PositionID
CREATE INDEX IX_CrmPartyPositions_PositionID ON dbo.CrmPartyPositions(PositionID)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetPartyRoles
CREATE PROCEDURE dbo.sp_Crm_GetPartyRoles
    @PartyID INT
AS
BEGIN
    SET NOCOUNT ON;
    -- ترتیب: SortOrder > 0 ⇒ همان SortOrder؛ SortOrder = 0 ⇒ کد (Code)؛ هم‌ارزها ⇒ کد، سپس شناسه
    SELECT pr.PartyRoleID, pr.PartyID, cr.ContactRoleID AS RoleID, cr.Code, cr.DisplayName, cr.SortOrder, cr.IsActive
    FROM dbo.CrmPartyRoles pr
    JOIN dbo.CrmContactRoles cr ON cr.ContactRoleID = pr.RoleID
    WHERE pr.PartyID = @PartyID
    ORDER BY CASE WHEN cr.SortOrder > 0 THEN cr.SortOrder ELSE CAST(cr.Code AS INT) END, CAST(cr.Code AS INT), cr.ContactRoleID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetPartyPositions
CREATE PROCEDURE dbo.sp_Crm_GetPartyPositions
    @PartyID INT
AS
BEGIN
    SET NOCOUNT ON;
    -- ترتیب: SortOrder > 0 ⇒ همان SortOrder؛ SortOrder = 0 ⇒ کد (Code)؛ هم‌ارزها ⇒ کد، سپس شناسه
    SELECT pp.PartyPositionID, pp.PartyID, p.PositionID, p.Code, p.DisplayName, p.SortOrder, p.IsActive
    FROM dbo.CrmPartyPositions pp
    JOIN dbo.CrmPositions p ON p.PositionID = pp.PositionID
    WHERE pp.PartyID = @PartyID
    ORDER BY CASE WHEN p.SortOrder > 0 THEN p.SortOrder ELSE CAST(p.Code AS INT) END, CAST(p.Code AS INT), p.PositionID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SavePartyRoles
CREATE PROCEDURE dbo.sp_Crm_SavePartyRoles
    @PartyID    INT,
    @RoleIDsCsv NVARCHAR(MAX) = NULL, -- e.g. '1,2,3' یا NULL/خالی برایِ برداشتنِ همه
    @UserID     INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حساب یافت نشد.' AS Message; RETURN; END

    DECLARE @Ids TABLE (RoleID INT NULL);
    INSERT INTO @Ids (RoleID)
    SELECT DISTINCT TRY_CAST(LTRIM(RTRIM(value)) AS INT)
    FROM STRING_SPLIT(ISNULL(@RoleIDsCsv, N''), ',')
    WHERE NULLIF(LTRIM(RTRIM(value)), N'') IS NOT NULL;

    IF EXISTS (SELECT 1 FROM @Ids i WHERE i.RoleID IS NULL
                  OR NOT EXISTS (SELECT 1 FROM dbo.CrmContactRoles cr WHERE cr.ContactRoleID = i.RoleID))
    BEGIN SELECT 0 AS Success, N'یک یا چند نقشِ انتخاب‌شده نامعتبر است.' AS Message; RETURN; END

    -- نقشِ غیرفعال فقط اگر از قبل رویِ طرف‌حساب ثبت بوده می‌ماند؛ افزودنِ تازه‌اش مجاز نیست
    IF EXISTS (SELECT 1 FROM @Ids i
               JOIN dbo.CrmContactRoles cr ON cr.ContactRoleID = i.RoleID AND cr.IsActive = 0
               WHERE NOT EXISTS (SELECT 1 FROM dbo.CrmPartyRoles pr WHERE pr.PartyID = @PartyID AND pr.RoleID = i.RoleID))
    BEGIN SELECT 0 AS Success, N'نقشِ غیرفعال را نمی‌توان به طرف‌حساب افزود.' AS Message; RETURN; END

    BEGIN TRY
        BEGIN TRAN;

        DELETE FROM dbo.CrmPartyRoles
        WHERE PartyID = @PartyID AND RoleID NOT IN (SELECT RoleID FROM @Ids);

        INSERT INTO dbo.CrmPartyRoles (PartyID, RoleID, Date_InsertFirst, UserID_InsertFirst)
        SELECT @PartyID, i.RoleID, SYSDATETIME(), @UserID
        FROM @Ids i
        WHERE NOT EXISTS (SELECT 1 FROM dbo.CrmPartyRoles pr WHERE pr.PartyID = @PartyID AND pr.RoleID = i.RoleID);

        COMMIT TRAN;
        SELECT 1 AS Success, N'نقش‌هایِ طرف‌حساب به‌روزرسانی شد.' AS Message;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRAN;
        SELECT 0 AS Success, N'خطا در ذخیرهٔ نقش‌هایِ طرف‌حساب: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SavePartyPositions
CREATE PROCEDURE dbo.sp_Crm_SavePartyPositions
    @PartyID        INT,
    @PositionIDsCsv NVARCHAR(MAX) = NULL, -- e.g. '1,2,3' یا NULL/خالی برایِ برداشتنِ همه
    @UserID         INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حساب یافت نشد.' AS Message; RETURN; END

    DECLARE @Ids TABLE (PositionID INT NULL);
    INSERT INTO @Ids (PositionID)
    SELECT DISTINCT TRY_CAST(LTRIM(RTRIM(value)) AS INT)
    FROM STRING_SPLIT(ISNULL(@PositionIDsCsv, N''), ',')
    WHERE NULLIF(LTRIM(RTRIM(value)), N'') IS NOT NULL;

    IF EXISTS (SELECT 1 FROM @Ids i WHERE i.PositionID IS NULL
                  OR NOT EXISTS (SELECT 1 FROM dbo.CrmPositions p WHERE p.PositionID = i.PositionID))
    BEGIN SELECT 0 AS Success, N'یک یا چند سمتِ انتخاب‌شده نامعتبر است.' AS Message; RETURN; END

    -- سمتِ غیرفعال فقط اگر از قبل رویِ طرف‌حساب ثبت بوده می‌ماند؛ افزودنِ تازه‌اش مجاز نیست
    IF EXISTS (SELECT 1 FROM @Ids i
               JOIN dbo.CrmPositions p ON p.PositionID = i.PositionID AND p.IsActive = 0
               WHERE NOT EXISTS (SELECT 1 FROM dbo.CrmPartyPositions pp WHERE pp.PartyID = @PartyID AND pp.PositionID = i.PositionID))
    BEGIN SELECT 0 AS Success, N'سمتِ غیرفعال را نمی‌توان به طرف‌حساب افزود.' AS Message; RETURN; END

    BEGIN TRY
        BEGIN TRAN;

        DELETE FROM dbo.CrmPartyPositions
        WHERE PartyID = @PartyID AND PositionID NOT IN (SELECT PositionID FROM @Ids);

        INSERT INTO dbo.CrmPartyPositions (PartyID, PositionID, Date_InsertFirst, UserID_InsertFirst)
        SELECT @PartyID, i.PositionID, SYSDATETIME(), @UserID
        FROM @Ids i
        WHERE NOT EXISTS (SELECT 1 FROM dbo.CrmPartyPositions pp WHERE pp.PartyID = @PartyID AND pp.PositionID = i.PositionID);

        COMMIT TRAN;
        SELECT 1 AS Success, N'سمت‌هایِ طرف‌حساب به‌روزرسانی شد.' AS Message;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRAN;
        SELECT 0 AS Success, N'خطا در ذخیرهٔ سمت‌هایِ طرف‌حساب: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

