-- Apply after 145 and before 147. Preserve the newer procedures if invoked out of order.
IF COL_LENGTH('dbo.ReportUserLayouts','LayoutID') IS NOT NULL
    THROW 51000, 'Patch 147 is already present; do not reapply Patch 146.', 1;
IF COL_LENGTH('dbo.Reports','ReportType') IS NULL
    THROW 51000, 'Report layouts require Patch 145.', 1;
IF OBJECT_ID('dbo.ReportUserLayouts','U') IS NULL
CREATE TABLE dbo.ReportUserLayouts (
    ReportID int NOT NULL REFERENCES dbo.Reports(ReportID),
    UserID int NOT NULL REFERENCES dbo.Users(UserID),
    LayoutJson nvarchar(max) NOT NULL CHECK (ISJSON(LayoutJson)=1 AND DATALENGTH(LayoutJson)<=131072),
    SchemaVersion smallint NOT NULL CHECK (SchemaVersion=1),
    UpdatedAtUtc datetime2(3) NOT NULL DEFAULT SYSUTCDATETIME(),
    CONSTRAINT PK_ReportUserLayouts PRIMARY KEY (ReportID, UserID)
);
GO
CREATE OR ALTER PROCEDURE dbo.sp_GetReportUserLayout @ReportID int, @UserID int
AS
BEGIN
    SET NOCOUNT ON;
    SELECT LayoutJson, SchemaVersion FROM dbo.ReportUserLayouts WHERE ReportID=@ReportID AND UserID=@UserID;
END;
GO
CREATE OR ALTER PROCEDURE dbo.sp_SaveReportUserLayout @ReportID int, @UserID int, @LayoutJson nvarchar(max)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    BEGIN TRANSACTION;
    UPDATE dbo.ReportUserLayouts WITH (UPDLOCK, SERIALIZABLE)
    SET LayoutJson=@LayoutJson, SchemaVersion=1, UpdatedAtUtc=SYSUTCDATETIME()
    WHERE ReportID=@ReportID AND UserID=@UserID;
    IF @@ROWCOUNT=0
        INSERT dbo.ReportUserLayouts(ReportID,UserID,LayoutJson,SchemaVersion) VALUES(@ReportID,@UserID,@LayoutJson,1);
    COMMIT TRANSACTION;
END;
GO
