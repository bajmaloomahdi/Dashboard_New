SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO
IF COL_LENGTH('dbo.Reports','ReportType') IS NULL OR COL_LENGTH('dbo.Reports','PivotConfig') IS NULL OR COL_LENGTH('dbo.ReportUserLayouts','LayoutID') IS NULL OR NOT EXISTS (SELECT 1 FROM sys.parameters WHERE object_id=OBJECT_ID('dbo.sp_SaveReportUserLayout','P') AND name='@Action') THROW 51000,'Requires patches 145, 146, and 147',1;
GO
CREATE OR ALTER PROCEDURE dbo.sp_InsertReport
    @MenuID INT,
    @ReportCode NVARCHAR(50),
    @ReportTitle NVARCHAR(200),
    @ProcedureName NVARCHAR(200),
    @CommandTimeout INT = 120,
    @AllowExcel BIT = 1,
    @AllowPdf BIT = 0,
    @AllowPrint BIT = 0,
    @CacheDuration INT = 0,
    @Description NVARCHAR(500) = NULL,
    @CreateUser INT = NULL, @ReportType varchar(10) = 'NORMAL', @ViewSchema sysname = NULL, @ViewName sysname = NULL, @PivotConfig nvarchar(max) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    IF @ReportType IS NULL OR @ReportType NOT IN ('NORMAL','PIVOT','CHART')
       OR (@ReportType IN ('PIVOT','CHART') AND (@ViewSchema IS NULL OR @ViewName IS NULL OR @PivotConfig IS NULL OR ISJSON(@PivotConfig)<>1
           OR NOT EXISTS (SELECT 1 FROM sys.views v JOIN sys.schemas s ON s.schema_id=v.schema_id WHERE s.name=@ViewSchema AND v.name=@ViewName)))
       THROW 51000, 'Invalid report type or pivot source.', 1;

    -- چک کردن وجود منو
    IF NOT EXISTS (SELECT 1 FROM Menu WHERE MenuID = @MenuID AND IsActive = 1)
    BEGIN
        SELECT
            0 AS Success,
            'منوی انتخاب شده یافت نشد یا غیرفعال است' AS Message,
            NULL AS ReportID;
        RETURN;
    END

    -- چک کردن تکراری نبودن ReportCode
    IF EXISTS (SELECT 1 FROM Reports WHERE ReportCode = @ReportCode)
    BEGIN
        SELECT
            0 AS Success,
            'کد گزارش قبلاً ثبت شده است' AS Message,
            NULL AS ReportID;
        RETURN;
    END

    -- چک کردن وجود SP در دیتابیس (اختیاری - هشدار)
    IF NOT EXISTS (
        SELECT 1 FROM sys.procedures
        WHERE name = @ProcedureName
    )
    BEGIN
        -- فقط هشدار میدیم، متوقف نمی‌کنیم
        -- چون شاید SP بعداً ساخته بشه
        PRINT 'هشدار: Stored Procedure با نام ' + @ProcedureName + ' یافت نشد';
    END

    -- درج گزارش جدید
    INSERT INTO Reports (
        RowGuid,
        MenuID,
        ReportCode,
        ReportTitle,
        ProcedureName, ReportType, ViewSchema, ViewName, PivotConfig,
        CommandTimeout,
        AllowExcel,
        AllowPdf,
        AllowPrint,
        CacheDuration,
        Description,
        CreateDate,
        CreateUser,
        IsActive
    )
    VALUES (
        NEWID(),
        @MenuID,
        @ReportCode,
        @ReportTitle,
        @ProcedureName, @ReportType, @ViewSchema, @ViewName, @PivotConfig,
        @CommandTimeout,
        @AllowExcel,
        @AllowPdf,
        @AllowPrint,
        @CacheDuration,
        @Description,
        GETDATE(),
        @CreateUser,
        1
    );

    SELECT
        1 AS Success,
        'گزارش با موفقیت ایجاد شد' AS Message,
        SCOPE_IDENTITY() AS ReportID;
END
GO
CREATE OR ALTER PROCEDURE dbo.sp_UpdateReport
    @ReportID INT,
    @MenuID INT,
    @ReportCode NVARCHAR(50),
    @ReportTitle NVARCHAR(200),
    @ProcedureName NVARCHAR(200),
    @CommandTimeout INT = 120,
    @AllowExcel BIT = 1,
    @AllowPdf BIT = 0,
    @AllowPrint BIT = 0,
    @CacheDuration INT = 0,
    @Description NVARCHAR(500) = NULL,
    @ModifyUser INT = NULL, @ReportType varchar(10) = 'NORMAL', @ViewSchema sysname = NULL, @ViewName sysname = NULL, @PivotConfig nvarchar(max) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    IF @ReportType IS NULL OR @ReportType NOT IN ('NORMAL','PIVOT','CHART')
       OR (@ReportType IN ('PIVOT','CHART') AND (@ViewSchema IS NULL OR @ViewName IS NULL OR @PivotConfig IS NULL OR ISJSON(@PivotConfig)<>1
           OR NOT EXISTS (SELECT 1 FROM sys.views v JOIN sys.schemas s ON s.schema_id=v.schema_id WHERE s.name=@ViewSchema AND v.name=@ViewName)))
       THROW 51000, 'Invalid report type or pivot source.', 1;

    -- چک کردن وجود گزارش
    IF NOT EXISTS (SELECT 1 FROM Reports WHERE ReportID = @ReportID)
    BEGIN
        SELECT
            0 AS Success,
            'گزارش مورد نظر یافت نشد' AS Message;
        RETURN;
    END

    -- چک کردن وجود منو
    IF NOT EXISTS (SELECT 1 FROM Menu WHERE MenuID = @MenuID AND IsActive = 1)
    BEGIN
        SELECT
            0 AS Success,
            'منوی انتخاب شده یافت نشد یا غیرفعال است' AS Message;
        RETURN;
    END

    -- چک کردن تکراری نبودن ReportCode (بجز خودش)
    IF EXISTS (
        SELECT 1 FROM Reports
        WHERE ReportCode = @ReportCode
          AND ReportID <> @ReportID
    )
    BEGIN
        SELECT
            0 AS Success,
            'این کد گزارش قبلاً برای گزارش دیگری ثبت شده است' AS Message;
        RETURN;
    END

    -- به‌روزرسانی گزارش
    UPDATE Reports
    SET
        MenuID = @MenuID,
        ReportCode = @ReportCode,
        ReportTitle = @ReportTitle,
        ProcedureName = @ProcedureName, ReportType = @ReportType, ViewSchema = @ViewSchema, ViewName = @ViewName, PivotConfig = @PivotConfig,
        CommandTimeout = @CommandTimeout,
        AllowExcel = @AllowExcel,
        AllowPdf = @AllowPdf,
        AllowPrint = @AllowPrint,
        CacheDuration = @CacheDuration,
        Description = @Description,
        ModifyDate = GETDATE(),
        ModifyUser = @ModifyUser
    WHERE ReportID = @ReportID;

    SELECT
        1 AS Success,
        'گزارش با موفقیت به‌روزرسانی شد' AS Message,
        @ReportID AS ReportID;
END
GO
