SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO
IF OBJECT_ID('dbo.ReportUserLayouts','U') IS NULL THROW 51000,'Requires patch 146',1;
IF COL_LENGTH('dbo.ReportUserLayouts','LayoutID') IS NULL
BEGIN
    ALTER TABLE dbo.ReportUserLayouts ADD LayoutID int IDENTITY NOT NULL,
        LayoutName nvarchar(120) NOT NULL DEFAULT (N'چیدمان من') WITH VALUES,
        IsDefault bit NOT NULL DEFAULT (1) WITH VALUES,
        CONSTRAINT CK_ReportUserLayouts_Name CHECK (LEN(LTRIM(RTRIM(LayoutName)))>0);
    ALTER TABLE dbo.ReportUserLayouts DROP CONSTRAINT PK_ReportUserLayouts;
    ALTER TABLE dbo.ReportUserLayouts ADD CONSTRAINT PK_ReportUserLayouts PRIMARY KEY(LayoutID);
    EXEC(N'CREATE UNIQUE INDEX UX_ReportUserLayouts_Name ON dbo.ReportUserLayouts(ReportID,UserID,LayoutName);
           CREATE UNIQUE INDEX UX_ReportUserLayouts_Default ON dbo.ReportUserLayouts(ReportID,UserID) WHERE IsDefault=1;');
END
GO
CREATE OR ALTER PROCEDURE dbo.sp_GetReportUserLayout
    @ReportID int, @UserID int, @LayoutID int=NULL, @List bit=0
AS
BEGIN
    SET NOCOUNT ON;
    IF @List=1
        SELECT LayoutID,LayoutName,IsDefault,UpdatedAtUtc FROM dbo.ReportUserLayouts
        WHERE ReportID=@ReportID AND UserID=@UserID ORDER BY IsDefault DESC,UpdatedAtUtc DESC,LayoutID DESC;
    ELSE
        SELECT LayoutID,LayoutName,IsDefault,LayoutJson,SchemaVersion FROM dbo.ReportUserLayouts
        WHERE ReportID=@ReportID AND UserID=@UserID AND
        ((@LayoutID IS NULL AND IsDefault=1) OR LayoutID=@LayoutID);
END
GO
CREATE OR ALTER PROCEDURE dbo.sp_SaveReportUserLayout
    @ReportID int, @UserID int, @LayoutJson nvarchar(max)=NULL,
    @LayoutID int=NULL, @LayoutName nvarchar(120)=NULL, @Action varchar(10)='save'
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    BEGIN TRANSACTION;
    DECLARE @lock int, @resource nvarchar(255)=CONCAT('report-layout:',@ReportID,':',@UserID);
    EXEC @lock=sys.sp_getapplock @Resource=@resource,@LockMode='Exclusive',@LockOwner='Transaction',@LockTimeout=5000;
    IF @lock<0 THROW 51000,'Could not lock layouts',1;
    IF @LayoutID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.ReportUserLayouts WHERE LayoutID=@LayoutID AND ReportID=@ReportID AND UserID=@UserID)
    BEGIN
        COMMIT;
        SELECT CAST(NULL AS int) AS LayoutID;
        RETURN;
    END
    IF @Action='save' AND @LayoutID IS NULL
    BEGIN
        INSERT dbo.ReportUserLayouts(ReportID,UserID,LayoutJson,SchemaVersion,LayoutName,IsDefault)
        VALUES(@ReportID,@UserID,@LayoutJson,1,@LayoutName,0);
        SET @LayoutID=CONVERT(int,SCOPE_IDENTITY());
    END
    ELSE IF @Action='save'
        UPDATE dbo.ReportUserLayouts SET LayoutJson=@LayoutJson,UpdatedAtUtc=SYSUTCDATETIME() WHERE LayoutID=@LayoutID;
    ELSE IF @Action='rename' AND @LayoutID IS NOT NULL
        UPDATE dbo.ReportUserLayouts SET LayoutName=@LayoutName,UpdatedAtUtc=SYSUTCDATETIME() WHERE LayoutID=@LayoutID;
    ELSE IF @Action='delete' AND @LayoutID IS NOT NULL
        DELETE dbo.ReportUserLayouts WHERE LayoutID=@LayoutID;
    ELSE IF @Action='default' AND @LayoutID IS NOT NULL
    BEGIN
        UPDATE dbo.ReportUserLayouts SET IsDefault=0 WHERE ReportID=@ReportID AND UserID=@UserID AND IsDefault=1 AND LayoutID<>@LayoutID;
        UPDATE dbo.ReportUserLayouts SET IsDefault=1,UpdatedAtUtc=SYSUTCDATETIME() WHERE LayoutID=@LayoutID;
    END
    ELSE THROW 51000,'Invalid layout action',1;
    COMMIT;
    SELECT @LayoutID AS LayoutID;
END
GO
