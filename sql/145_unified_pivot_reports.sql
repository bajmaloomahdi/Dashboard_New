IF COL_LENGTH('dbo.Reports','ReportType') IS NULL
    ALTER TABLE dbo.Reports ADD ReportType varchar(10) NOT NULL CONSTRAINT DF_Reports_ReportType DEFAULT ('NORMAL') WITH VALUES,
        ViewSchema sysname NULL, ViewName sysname NULL, PivotConfig nvarchar(max) NULL,
        CONSTRAINT CK_Reports_ReportType CHECK (ReportType IN ('NORMAL','PIVOT','CHART')),
        CONSTRAINT CK_Reports_PivotConfig CHECK (PivotConfig IS NULL OR ISJSON(PivotConfig)=1);
GO
CREATE OR ALTER PROCEDURE dbo.sp_GetReports
    @SearchText NVARCHAR(200) = NULL,
    @IsActive BIT = NULL,
    @MenuID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;

    SELECT 
        r.ReportID,
        r.ReportCode,
        r.ReportTitle,
        r.ProcedureName, r.ReportType, r.ViewSchema, r.ViewName, r.PivotConfig,
        r.MenuID,
        r.CommandTimeout,
        r.AllowExcel,
        r.AllowPdf,
        r.AllowPrint,
        r.CacheDuration,
        r.Description,
        r.IsActive,
        r.CreateDate,
        -- عنوان منوی مربوطه
        (SELECT m.MenuTitle FROM Menu m WHERE m.MenuID = r.MenuID) AS MenuTitle,
        -- تعداد پارامترهای فعال
        (SELECT COUNT(*) 
         FROM ReportParameters rp 
         WHERE rp.ReportID = r.ReportID AND rp.IsActive = 1) AS ParametersCount,
        -- تعداد نقش‌هایی که دسترسی دارن
        (SELECT COUNT(*) 
         FROM RoleReports rr 
         WHERE rr.ReportID = r.ReportID AND rr.IsActive = 1 AND rr.CanView = 1) AS RolesCount
    FROM Reports r
    WHERE 
        (@SearchText IS NULL 
            OR r.ReportTitle LIKE '%' + @SearchText + '%'
            OR r.ReportCode LIKE '%' + @SearchText + '%'
            OR r.ProcedureName LIKE '%' + @SearchText + '%'
            OR r.Description LIKE '%' + @SearchText + '%')
        AND (@IsActive IS NULL OR r.IsActive = @IsActive)
        AND (@MenuID IS NULL OR r.MenuID = @MenuID)
    ORDER BY r.ReportID DESC;
END
GO
CREATE OR ALTER PROCEDURE dbo.sp_GetReportInfo
    @ReportCode NVARCHAR(50),
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;

    -- ابتدا بررسی می‌کنیم گزارش وجود داره
    IF NOT EXISTS (SELECT 1 FROM Reports WHERE ReportCode = @ReportCode AND IsActive = 1)
    BEGIN
        -- برگرداندن نتیجه خالی
        SELECT TOP 0
            ReportID = 0,
            ReportCode = '',
            ReportTitle = '',
            ProcedureName = '',
            CommandTimeout = 0,
            AllowExcel = CAST(0 AS BIT),
            AllowPdf = CAST(0 AS BIT),
            AllowPrint = CAST(0 AS BIT),
            CacheDuration = 0,
            Description = '';

        SELECT TOP 0
            ReportParameterID = 0,
            ReportID = 0,
            MasterParameterID = 0,
            ParameterName = '',
            ParameterCaption = '',
            DataType = '',
            ControlType = '',
            LookupProcedure = '',
            DefaultValue = '',
            IsRequired = CAST(0 AS BIT),
            IsVisible = CAST(0 AS BIT),
            SortOrder = 0,
            Description = '';

        RETURN;
    END

    -- Result Set 1: اطلاعات گزارش
    SELECT DISTINCT
        r.ReportID,
        r.ReportCode,
        r.ReportTitle,
        r.ProcedureName, r.ReportType, r.ViewSchema, r.ViewName, r.PivotConfig,
        r.CommandTimeout,
        r.AllowExcel,
        r.AllowPdf,
        r.AllowPrint,
        r.CacheDuration,
        r.Description
    FROM Reports r
    INNER JOIN RoleReports rr ON rr.ReportID = r.ReportID
    INNER JOIN UserRoles ur ON ur.RoleID = rr.RoleID
    INNER JOIN Roles ro ON ro.RoleID = ur.RoleID
    WHERE r.ReportCode = @ReportCode
      AND r.IsActive = 1
      AND rr.IsActive = 1
      AND rr.CanView = 1
      AND ur.IsActive = 1
      AND ur.UserID = @UserID
      AND ro.IsActive = 1;

    -- Result Set 2: پارامترها
    SELECT 
        rp.ReportParameterID,
        rp.ReportID,
        rp.MasterParameterID,
        mp.ParameterName,
        mp.ParameterCaption,
        mp.DataType,
        mp.ControlType,
        mp.LookupProcedure,
        rp.DefaultValue,
        rp.IsRequired,
        rp.IsVisible,
        rp.SortOrder,
        ISNULL(rp.Description, mp.Description) AS Description
    FROM ReportParameters rp
    INNER JOIN Reports r ON r.ReportID = rp.ReportID
    INNER JOIN MasterParameters mp ON mp.MasterParameterID = rp.MasterParameterID
    WHERE r.ReportCode = @ReportCode
      AND rp.IsActive = 1
      AND rp.IsVisible = 1
      AND mp.IsActive = 1
    ORDER BY rp.SortOrder;
END
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
    IF @ReportType IS NULL OR @ReportType NOT IN ('NORMAL','PIVOT')
       OR (@ReportType='PIVOT' AND (@ViewSchema IS NULL OR @ViewName IS NULL OR @PivotConfig IS NULL OR ISJSON(@PivotConfig)<>1
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
    IF @ReportType IS NULL OR @ReportType NOT IN ('NORMAL','PIVOT')
       OR (@ReportType='PIVOT' AND (@ViewSchema IS NULL OR @ViewName IS NULL OR @PivotConfig IS NULL OR ISJSON(@PivotConfig)<>1
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
