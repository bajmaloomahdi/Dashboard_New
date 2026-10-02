/* ==========================================================================
   پچ خودکار شماره: 078 | نام: project_owner
   تاریخ: 2026-10-02 10:01:05 | شامل 5 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: Projects
ALTER TABLE dbo.Projects ADD OwnerPartyID INT NULL, OwnerBrandID INT NULL
GO

-- [ALTER_TABLE] روی TABLE: Projects
ALTER TABLE dbo.Projects ADD CONSTRAINT FK_Projects_OwnerParty FOREIGN KEY (OwnerPartyID) REFERENCES dbo.CrmParties(PartyID)
GO

-- [ALTER_TABLE] روی TABLE: Projects
ALTER TABLE dbo.Projects ADD CONSTRAINT FK_Projects_OwnerBrand FOREIGN KEY (OwnerBrandID) REFERENCES dbo.CrmBrands(BrandID)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetProjectOwner
CREATE PROCEDURE dbo.sp_GetProjectOwner
    @ProjectID BIGINT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT p.ProjectID,
           p.OwnerPartyID, NULLIF(ISNULL(cp.OfficialName, N''), N'') AS OwnerPartyName,
           p.OwnerBrandID, b.Name AS OwnerBrandName
    FROM dbo.Projects p
    LEFT JOIN dbo.CrmParties cp ON cp.PartyID = p.OwnerPartyID
    LEFT JOIN dbo.CrmBrands b ON b.BrandID = p.OwnerBrandID
    WHERE p.ProjectID = @ProjectID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_SaveProjectOwner
CREATE PROCEDURE dbo.sp_SaveProjectOwner
    @ProjectID    BIGINT,
    @OwnerPartyID INT = NULL,
    @OwnerBrandID INT = NULL,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.Projects WHERE ProjectID = @ProjectID)
    BEGIN SELECT 0 AS Success, N'پروژه یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.Projects
    SET OwnerPartyID = @OwnerPartyID, OwnerBrandID = @OwnerBrandID,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE ProjectID = @ProjectID;

    SELECT 1 AS Success, N'مالکِ پروژه ذخیره شد.' AS Message;
END
GO
