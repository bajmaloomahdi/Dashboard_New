/* ==========================================================================
   پچ خودکار شماره: 038 | نام: crm_phase1_master_data
   تاریخ: 2026-09-19 19:43:57 | شامل 51 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: CrmDepartments
CREATE TABLE dbo.CrmDepartments
(
    DepartmentID  INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmDepartments PRIMARY KEY,
    Code          NVARCHAR(64)  NOT NULL,
    DisplayName   NVARCHAR(200) NOT NULL,
    SortOrder     INT NOT NULL CONSTRAINT DF_CrmDepartments_SortOrder DEFAULT (0),
    IsActive      BIT NOT NULL CONSTRAINT DF_CrmDepartments_IsActive DEFAULT (1),
    RowGuid       UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmDepartments_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmDepartments_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate     DATETIME2 NULL,
    UserID_LastUpdate   INT NULL,
    CONSTRAINT UQ_CrmDepartments_Code UNIQUE (Code)
)
GO

-- [CREATE_TABLE] روی TABLE: CrmPartyTypes
CREATE TABLE dbo.CrmPartyTypes
(
    PartyTypeID   INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmPartyTypes PRIMARY KEY,
    Code          NVARCHAR(64)  NOT NULL,
    DisplayName   NVARCHAR(200) NOT NULL,
    SortOrder     INT NOT NULL CONSTRAINT DF_CrmPartyTypes_SortOrder DEFAULT (0),
    IsActive      BIT NOT NULL CONSTRAINT DF_CrmPartyTypes_IsActive DEFAULT (1),
    RowGuid       UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmPartyTypes_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmPartyTypes_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate     DATETIME2 NULL,
    UserID_LastUpdate   INT NULL,
    CONSTRAINT UQ_CrmPartyTypes_Code UNIQUE (Code)
)
GO

-- [CREATE_TABLE] روی TABLE: CrmActivities
CREATE TABLE dbo.CrmActivities
(
    ActivityID    INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmActivities PRIMARY KEY,
    PartyTypeID   INT NOT NULL,
    Code          NVARCHAR(64)  NOT NULL,
    DisplayName   NVARCHAR(200) NOT NULL,
    SortOrder     INT NOT NULL CONSTRAINT DF_CrmActivities_SortOrder DEFAULT (0),
    IsActive      BIT NOT NULL CONSTRAINT DF_CrmActivities_IsActive DEFAULT (1),
    RowGuid       UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmActivities_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmActivities_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate     DATETIME2 NULL,
    UserID_LastUpdate   INT NULL,
    CONSTRAINT UQ_CrmActivities_PartyTypeID_Code UNIQUE (PartyTypeID, Code),
    CONSTRAINT FK_CrmActivities_PartyType FOREIGN KEY (PartyTypeID) REFERENCES dbo.CrmPartyTypes(PartyTypeID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmActivities_PartyTypeID
CREATE INDEX IX_CrmActivities_PartyTypeID ON dbo.CrmActivities(PartyTypeID)
GO

-- [CREATE_TABLE] روی TABLE: CrmTitles
CREATE TABLE dbo.CrmTitles
(
    TitleID       INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmTitles PRIMARY KEY,
    Code          NVARCHAR(64)  NOT NULL,
    DisplayName   NVARCHAR(200) NOT NULL,
    SortOrder     INT NOT NULL CONSTRAINT DF_CrmTitles_SortOrder DEFAULT (0),
    IsActive      BIT NOT NULL CONSTRAINT DF_CrmTitles_IsActive DEFAULT (1),
    RowGuid       UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmTitles_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmTitles_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate     DATETIME2 NULL,
    UserID_LastUpdate   INT NULL,
    CONSTRAINT UQ_CrmTitles_Code UNIQUE (Code)
)
GO

-- [CREATE_TABLE] روی TABLE: CrmPositions
CREATE TABLE dbo.CrmPositions
(
    PositionID    INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmPositions PRIMARY KEY,
    Code          NVARCHAR(64)  NOT NULL,
    DisplayName   NVARCHAR(200) NOT NULL,
    SortOrder     INT NOT NULL CONSTRAINT DF_CrmPositions_SortOrder DEFAULT (0),
    IsActive      BIT NOT NULL CONSTRAINT DF_CrmPositions_IsActive DEFAULT (1),
    RowGuid       UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmPositions_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmPositions_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate     DATETIME2 NULL,
    UserID_LastUpdate   INT NULL,
    CONSTRAINT UQ_CrmPositions_Code UNIQUE (Code)
)
GO

-- [CREATE_TABLE] روی TABLE: CrmContactRoles
CREATE TABLE dbo.CrmContactRoles
(
    ContactRoleID INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmContactRoles PRIMARY KEY,
    Code          NVARCHAR(64)  NOT NULL,
    DisplayName   NVARCHAR(200) NOT NULL,
    SortOrder     INT NOT NULL CONSTRAINT DF_CrmContactRoles_SortOrder DEFAULT (0),
    IsActive      BIT NOT NULL CONSTRAINT DF_CrmContactRoles_IsActive DEFAULT (1),
    RowGuid       UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmContactRoles_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmContactRoles_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate     DATETIME2 NULL,
    UserID_LastUpdate   INT NULL,
    CONSTRAINT UQ_CrmContactRoles_Code UNIQUE (Code)
)
GO

-- [CREATE_TABLE] روی TABLE: CrmContactTypes
CREATE TABLE dbo.CrmContactTypes
(
    ContactTypeID INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmContactTypes PRIMARY KEY,
    Code          NVARCHAR(64)  NOT NULL,
    DisplayName   NVARCHAR(200) NOT NULL,
    SortOrder     INT NOT NULL CONSTRAINT DF_CrmContactTypes_SortOrder DEFAULT (0),
    IsActive      BIT NOT NULL CONSTRAINT DF_CrmContactTypes_IsActive DEFAULT (1),
    RowGuid       UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmContactTypes_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmContactTypes_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate     DATETIME2 NULL,
    UserID_LastUpdate   INT NULL,
    CONSTRAINT UQ_CrmContactTypes_Code UNIQUE (Code)
)
GO

-- [CREATE_TABLE] روی TABLE: CrmAddressTitles
CREATE TABLE dbo.CrmAddressTitles
(
    AddressTitleID INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmAddressTitles PRIMARY KEY,
    Code          NVARCHAR(64)  NOT NULL,
    DisplayName   NVARCHAR(200) NOT NULL,
    SortOrder     INT NOT NULL CONSTRAINT DF_CrmAddressTitles_SortOrder DEFAULT (0),
    IsActive      BIT NOT NULL CONSTRAINT DF_CrmAddressTitles_IsActive DEFAULT (1),
    RowGuid       UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmAddressTitles_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmAddressTitles_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate     DATETIME2 NULL,
    UserID_LastUpdate   INT NULL,
    CONSTRAINT UQ_CrmAddressTitles_Code UNIQUE (Code)
)
GO

-- [CREATE_TABLE] روی TABLE: CrmProvinces
CREATE TABLE dbo.CrmProvinces
(
    ProvinceID    INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmProvinces PRIMARY KEY,
    Code          NVARCHAR(64)  NOT NULL,
    DisplayName   NVARCHAR(200) NOT NULL,
    SortOrder     INT NOT NULL CONSTRAINT DF_CrmProvinces_SortOrder DEFAULT (0),
    IsActive      BIT NOT NULL CONSTRAINT DF_CrmProvinces_IsActive DEFAULT (1),
    RowGuid       UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmProvinces_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmProvinces_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate     DATETIME2 NULL,
    UserID_LastUpdate   INT NULL,
    CONSTRAINT UQ_CrmProvinces_Code UNIQUE (Code)
)
GO

-- [CREATE_TABLE] روی TABLE: CrmCities
CREATE TABLE dbo.CrmCities
(
    CityID        INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmCities PRIMARY KEY,
    ProvinceID    INT NOT NULL,
    Code          NVARCHAR(64)  NOT NULL,
    DisplayName   NVARCHAR(200) NOT NULL,
    SortOrder     INT NOT NULL CONSTRAINT DF_CrmCities_SortOrder DEFAULT (0),
    IsActive      BIT NOT NULL CONSTRAINT DF_CrmCities_IsActive DEFAULT (1),
    RowGuid       UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmCities_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmCities_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate     DATETIME2 NULL,
    UserID_LastUpdate   INT NULL,
    CONSTRAINT UQ_CrmCities_ProvinceID_Code UNIQUE (ProvinceID, Code),
    CONSTRAINT FK_CrmCities_Province FOREIGN KEY (ProvinceID) REFERENCES dbo.CrmProvinces(ProvinceID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmCities_ProvinceID
CREATE INDEX IX_CrmCities_ProvinceID ON dbo.CrmCities(ProvinceID)
GO

-- [CREATE_TABLE] روی TABLE: CrmCounties
CREATE TABLE dbo.CrmCounties
(
    CountyID      INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmCounties PRIMARY KEY,
    CityID        INT NOT NULL,
    Code          NVARCHAR(64)  NOT NULL,
    DisplayName   NVARCHAR(200) NOT NULL,
    SortOrder     INT NOT NULL CONSTRAINT DF_CrmCounties_SortOrder DEFAULT (0),
    IsActive      BIT NOT NULL CONSTRAINT DF_CrmCounties_IsActive DEFAULT (1),
    RowGuid       UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmCounties_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmCounties_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate     DATETIME2 NULL,
    UserID_LastUpdate   INT NULL,
    CONSTRAINT UQ_CrmCounties_CityID_Code UNIQUE (CityID, Code),
    CONSTRAINT FK_CrmCounties_City FOREIGN KEY (CityID) REFERENCES dbo.CrmCities(CityID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmCounties_CityID
CREATE INDEX IX_CrmCounties_CityID ON dbo.CrmCounties(CityID)
GO

-- [CREATE_TABLE] روی TABLE: CrmMunicipalZones
CREATE TABLE dbo.CrmMunicipalZones
(
    MunicipalZoneID INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmMunicipalZones PRIMARY KEY,
    Code          NVARCHAR(64)  NOT NULL,
    DisplayName   NVARCHAR(200) NOT NULL,
    SortOrder     INT NOT NULL CONSTRAINT DF_CrmMunicipalZones_SortOrder DEFAULT (0),
    IsActive      BIT NOT NULL CONSTRAINT DF_CrmMunicipalZones_IsActive DEFAULT (1),
    RowGuid       UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmMunicipalZones_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmMunicipalZones_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate     DATETIME2 NULL,
    UserID_LastUpdate   INT NULL,
    CONSTRAINT UQ_CrmMunicipalZones_Code UNIQUE (Code)
)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetDepartments
-- =============================================================
-- CRM Phase 1: Master Data SPs (3 per registry x 12 = 36)
-- Independent registries: Departments, PartyTypes, Titles, Positions,
--   ContactRoles, ContactTypes, AddressTitles, Provinces, MunicipalZones
-- Child registries: Activities(->PartyTypes), Cities(->Provinces), Counties(->Cities)
-- =============================================================

/* ===================== CrmDepartments ===================== */
CREATE PROCEDURE dbo.sp_Crm_GetDepartments
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT DepartmentID, Code, DisplayName, SortOrder, IsActive,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate
    FROM dbo.CrmDepartments d
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR d.DisplayName LIKE N'%' + @SearchText + N'%'
           OR d.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR d.IsActive = @IsActive)
    ORDER BY d.SortOrder, d.DepartmentID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SaveDepartment
CREATE PROCEDURE dbo.sp_Crm_SaveDepartment
    @DepartmentID INT           = NULL,
    @Code         NVARCHAR(64),
    @DisplayName  NVARCHAR(200),
    @SortOrder    INT           = 0,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@Code)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'کد الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.CrmDepartments
               WHERE Code = @Code AND (@DepartmentID IS NULL OR DepartmentID <> @DepartmentID))
    BEGIN SELECT 0 AS Success, N'این کد قبلاً استفاده شده است.' AS Message; RETURN; END

    IF @DepartmentID IS NULL
    BEGIN
        INSERT INTO dbo.CrmDepartments (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@Code, @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'دپارتمان ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS DepartmentID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmDepartments WHERE DepartmentID = @DepartmentID)
        BEGIN SELECT 0 AS Success, N'دپارتمان یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmDepartments
        SET Code = @Code, DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE DepartmentID = @DepartmentID;

        SELECT 1 AS Success, N'دپارتمان ویرایش شد.' AS Message, @DepartmentID AS DepartmentID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_ToggleDepartmentActive
CREATE PROCEDURE dbo.sp_Crm_ToggleDepartmentActive
    @DepartmentID INT,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmDepartments WHERE DepartmentID = @DepartmentID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'دپارتمان یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmDepartments
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE DepartmentID = @DepartmentID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'دپارتمان غیرفعال شد.' ELSE N'دپارتمان فعال شد.' END AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetPartyTypes
/* ===================== CrmPartyTypes ===================== */
CREATE PROCEDURE dbo.sp_Crm_GetPartyTypes
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT PartyTypeID, Code, DisplayName, SortOrder, IsActive,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate
    FROM dbo.CrmPartyTypes t
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR t.DisplayName LIKE N'%' + @SearchText + N'%'
           OR t.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR t.IsActive = @IsActive)
    ORDER BY t.SortOrder, t.PartyTypeID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SavePartyType
CREATE PROCEDURE dbo.sp_Crm_SavePartyType
    @PartyTypeID  INT           = NULL,
    @Code         NVARCHAR(64),
    @DisplayName  NVARCHAR(200),
    @SortOrder    INT           = 0,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@Code)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'کد الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.CrmPartyTypes
               WHERE Code = @Code AND (@PartyTypeID IS NULL OR PartyTypeID <> @PartyTypeID))
    BEGIN SELECT 0 AS Success, N'این کد قبلاً استفاده شده است.' AS Message; RETURN; END

    IF @PartyTypeID IS NULL
    BEGIN
        INSERT INTO dbo.CrmPartyTypes (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@Code, @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'نوع ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS PartyTypeID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmPartyTypes WHERE PartyTypeID = @PartyTypeID)
        BEGIN SELECT 0 AS Success, N'نوع یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmPartyTypes
        SET Code = @Code, DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE PartyTypeID = @PartyTypeID;

        SELECT 1 AS Success, N'نوع ویرایش شد.' AS Message, @PartyTypeID AS PartyTypeID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_TogglePartyTypeActive
CREATE PROCEDURE dbo.sp_Crm_TogglePartyTypeActive
    @PartyTypeID INT,
    @UserID      INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmPartyTypes WHERE PartyTypeID = @PartyTypeID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'نوع یافت نشد.' AS Message; RETURN; END

    IF @Current = 1 AND EXISTS (SELECT 1 FROM dbo.CrmActivities a WHERE a.PartyTypeID = @PartyTypeID AND a.IsActive = 1)
    BEGIN SELECT 0 AS Success, N'این نوع دارایِ یک یا چند فعالیتِ فعال است؛ ابتدا آن‌ها را غیرفعال کنید.' AS Message; RETURN; END

    UPDATE dbo.CrmPartyTypes
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE PartyTypeID = @PartyTypeID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'نوع غیرفعال شد.' ELSE N'نوع فعال شد.' END AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetActivities
/* ===================== CrmActivities (child of CrmPartyTypes) ===================== */
CREATE PROCEDURE dbo.sp_Crm_GetActivities
    @PartyTypeID INT           = NULL,
    @SearchText  NVARCHAR(200) = NULL,
    @IsActive    BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT a.ActivityID, a.PartyTypeID, a.Code, a.DisplayName, a.SortOrder, a.IsActive,
           a.Date_InsertFirst, a.UserID_InsertFirst, a.Date_LastUpdate, a.UserID_LastUpdate,
           t.DisplayName AS PartyTypeName
    FROM dbo.CrmActivities a
    JOIN dbo.CrmPartyTypes t ON t.PartyTypeID = a.PartyTypeID
    WHERE (@PartyTypeID IS NULL OR a.PartyTypeID = @PartyTypeID)
      AND (@SearchText IS NULL OR @SearchText = N''
           OR a.DisplayName LIKE N'%' + @SearchText + N'%'
           OR a.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR a.IsActive = @IsActive)
    ORDER BY a.SortOrder, a.ActivityID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SaveActivity
CREATE PROCEDURE dbo.sp_Crm_SaveActivity
    @ActivityID   INT           = NULL,
    @PartyTypeID  INT,
    @Code         NVARCHAR(64),
    @DisplayName  NVARCHAR(200),
    @SortOrder    INT           = 0,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmPartyTypes WHERE PartyTypeID = @PartyTypeID)
    BEGIN SELECT 0 AS Success, N'نوعِ انتخاب‌شده معتبر نیست.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@Code)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'کد الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.CrmActivities
               WHERE PartyTypeID = @PartyTypeID AND Code = @Code AND (@ActivityID IS NULL OR ActivityID <> @ActivityID))
    BEGIN SELECT 0 AS Success, N'این کد قبلاً در همین نوع استفاده شده است.' AS Message; RETURN; END

    IF @ActivityID IS NULL
    BEGIN
        INSERT INTO dbo.CrmActivities (PartyTypeID, Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@PartyTypeID, @Code, @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'فعالیت ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS ActivityID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmActivities WHERE ActivityID = @ActivityID)
        BEGIN SELECT 0 AS Success, N'فعالیت یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmActivities
        SET PartyTypeID = @PartyTypeID, Code = @Code, DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE ActivityID = @ActivityID;

        SELECT 1 AS Success, N'فعالیت ویرایش شد.' AS Message, @ActivityID AS ActivityID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_ToggleActivityActive
CREATE PROCEDURE dbo.sp_Crm_ToggleActivityActive
    @ActivityID INT,
    @UserID     INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmActivities WHERE ActivityID = @ActivityID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'فعالیت یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmActivities
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE ActivityID = @ActivityID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'فعالیت غیرفعال شد.' ELSE N'فعالیت فعال شد.' END AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetTitles
/* ===================== CrmTitles ===================== */
CREATE PROCEDURE dbo.sp_Crm_GetTitles
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TitleID, Code, DisplayName, SortOrder, IsActive,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate
    FROM dbo.CrmTitles x
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR x.DisplayName LIKE N'%' + @SearchText + N'%'
           OR x.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR x.IsActive = @IsActive)
    ORDER BY x.SortOrder, x.TitleID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SaveTitle
CREATE PROCEDURE dbo.sp_Crm_SaveTitle
    @TitleID      INT           = NULL,
    @Code         NVARCHAR(64),
    @DisplayName  NVARCHAR(200),
    @SortOrder    INT           = 0,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@Code)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'کد الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.CrmTitles
               WHERE Code = @Code AND (@TitleID IS NULL OR TitleID <> @TitleID))
    BEGIN SELECT 0 AS Success, N'این کد قبلاً استفاده شده است.' AS Message; RETURN; END

    IF @TitleID IS NULL
    BEGIN
        INSERT INTO dbo.CrmTitles (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@Code, @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'عنوان ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS TitleID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmTitles WHERE TitleID = @TitleID)
        BEGIN SELECT 0 AS Success, N'عنوان یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmTitles
        SET Code = @Code, DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE TitleID = @TitleID;

        SELECT 1 AS Success, N'عنوان ویرایش شد.' AS Message, @TitleID AS TitleID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_ToggleTitleActive
CREATE PROCEDURE dbo.sp_Crm_ToggleTitleActive
    @TitleID INT,
    @UserID  INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmTitles WHERE TitleID = @TitleID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'عنوان یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmTitles
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE TitleID = @TitleID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'عنوان غیرفعال شد.' ELSE N'عنوان فعال شد.' END AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetPositions
/* ===================== CrmPositions ===================== */
CREATE PROCEDURE dbo.sp_Crm_GetPositions
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT PositionID, Code, DisplayName, SortOrder, IsActive,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate
    FROM dbo.CrmPositions p
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR p.DisplayName LIKE N'%' + @SearchText + N'%'
           OR p.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR p.IsActive = @IsActive)
    ORDER BY p.SortOrder, p.PositionID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SavePosition
CREATE PROCEDURE dbo.sp_Crm_SavePosition
    @PositionID   INT           = NULL,
    @Code         NVARCHAR(64),
    @DisplayName  NVARCHAR(200),
    @SortOrder    INT           = 0,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@Code)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'کد الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.CrmPositions
               WHERE Code = @Code AND (@PositionID IS NULL OR PositionID <> @PositionID))
    BEGIN SELECT 0 AS Success, N'این کد قبلاً استفاده شده است.' AS Message; RETURN; END

    IF @PositionID IS NULL
    BEGIN
        INSERT INTO dbo.CrmPositions (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@Code, @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'سمت ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS PositionID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmPositions WHERE PositionID = @PositionID)
        BEGIN SELECT 0 AS Success, N'سمت یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmPositions
        SET Code = @Code, DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE PositionID = @PositionID;

        SELECT 1 AS Success, N'سمت ویرایش شد.' AS Message, @PositionID AS PositionID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_TogglePositionActive
CREATE PROCEDURE dbo.sp_Crm_TogglePositionActive
    @PositionID INT,
    @UserID     INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmPositions WHERE PositionID = @PositionID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'سمت یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmPositions
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE PositionID = @PositionID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'سمت غیرفعال شد.' ELSE N'سمت فعال شد.' END AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetContactRoles
/* ===================== CrmContactRoles ===================== */
CREATE PROCEDURE dbo.sp_Crm_GetContactRoles
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT ContactRoleID, Code, DisplayName, SortOrder, IsActive,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate
    FROM dbo.CrmContactRoles r
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR r.DisplayName LIKE N'%' + @SearchText + N'%'
           OR r.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR r.IsActive = @IsActive)
    ORDER BY r.SortOrder, r.ContactRoleID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SaveContactRole
CREATE PROCEDURE dbo.sp_Crm_SaveContactRole
    @ContactRoleID INT           = NULL,
    @Code          NVARCHAR(64),
    @DisplayName   NVARCHAR(200),
    @SortOrder     INT           = 0,
    @UserID        INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@Code)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'کد الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.CrmContactRoles
               WHERE Code = @Code AND (@ContactRoleID IS NULL OR ContactRoleID <> @ContactRoleID))
    BEGIN SELECT 0 AS Success, N'این کد قبلاً استفاده شده است.' AS Message; RETURN; END

    IF @ContactRoleID IS NULL
    BEGIN
        INSERT INTO dbo.CrmContactRoles (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@Code, @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'نقش ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS ContactRoleID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmContactRoles WHERE ContactRoleID = @ContactRoleID)
        BEGIN SELECT 0 AS Success, N'نقش یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmContactRoles
        SET Code = @Code, DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE ContactRoleID = @ContactRoleID;

        SELECT 1 AS Success, N'نقش ویرایش شد.' AS Message, @ContactRoleID AS ContactRoleID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_ToggleContactRoleActive
CREATE PROCEDURE dbo.sp_Crm_ToggleContactRoleActive
    @ContactRoleID INT,
    @UserID        INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmContactRoles WHERE ContactRoleID = @ContactRoleID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'نقش یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmContactRoles
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE ContactRoleID = @ContactRoleID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'نقش غیرفعال شد.' ELSE N'نقش فعال شد.' END AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetContactTypes
/* ===================== CrmContactTypes ===================== */
CREATE PROCEDURE dbo.sp_Crm_GetContactTypes
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT ContactTypeID, Code, DisplayName, SortOrder, IsActive,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate
    FROM dbo.CrmContactTypes c
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR c.DisplayName LIKE N'%' + @SearchText + N'%'
           OR c.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR c.IsActive = @IsActive)
    ORDER BY c.SortOrder, c.ContactTypeID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SaveContactType
CREATE PROCEDURE dbo.sp_Crm_SaveContactType
    @ContactTypeID INT           = NULL,
    @Code          NVARCHAR(64),
    @DisplayName   NVARCHAR(200),
    @SortOrder     INT           = 0,
    @UserID        INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@Code)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'کد الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.CrmContactTypes
               WHERE Code = @Code AND (@ContactTypeID IS NULL OR ContactTypeID <> @ContactTypeID))
    BEGIN SELECT 0 AS Success, N'این کد قبلاً استفاده شده است.' AS Message; RETURN; END

    IF @ContactTypeID IS NULL
    BEGIN
        INSERT INTO dbo.CrmContactTypes (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@Code, @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'نوعِ تماس ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS ContactTypeID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmContactTypes WHERE ContactTypeID = @ContactTypeID)
        BEGIN SELECT 0 AS Success, N'نوعِ تماس یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmContactTypes
        SET Code = @Code, DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE ContactTypeID = @ContactTypeID;

        SELECT 1 AS Success, N'نوعِ تماس ویرایش شد.' AS Message, @ContactTypeID AS ContactTypeID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_ToggleContactTypeActive
CREATE PROCEDURE dbo.sp_Crm_ToggleContactTypeActive
    @ContactTypeID INT,
    @UserID        INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmContactTypes WHERE ContactTypeID = @ContactTypeID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'نوعِ تماس یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmContactTypes
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE ContactTypeID = @ContactTypeID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'نوعِ تماس غیرفعال شد.' ELSE N'نوعِ تماس فعال شد.' END AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetAddressTitles
/* ===================== CrmAddressTitles ===================== */
CREATE PROCEDURE dbo.sp_Crm_GetAddressTitles
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT AddressTitleID, Code, DisplayName, SortOrder, IsActive,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate
    FROM dbo.CrmAddressTitles t
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR t.DisplayName LIKE N'%' + @SearchText + N'%'
           OR t.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR t.IsActive = @IsActive)
    ORDER BY t.SortOrder, t.AddressTitleID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SaveAddressTitle
CREATE PROCEDURE dbo.sp_Crm_SaveAddressTitle
    @AddressTitleID INT           = NULL,
    @Code           NVARCHAR(64),
    @DisplayName    NVARCHAR(200),
    @SortOrder      INT           = 0,
    @UserID         INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@Code)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'کد الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.CrmAddressTitles
               WHERE Code = @Code AND (@AddressTitleID IS NULL OR AddressTitleID <> @AddressTitleID))
    BEGIN SELECT 0 AS Success, N'این کد قبلاً استفاده شده است.' AS Message; RETURN; END

    IF @AddressTitleID IS NULL
    BEGIN
        INSERT INTO dbo.CrmAddressTitles (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@Code, @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'عنوانِ آدرس ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS AddressTitleID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmAddressTitles WHERE AddressTitleID = @AddressTitleID)
        BEGIN SELECT 0 AS Success, N'عنوانِ آدرس یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmAddressTitles
        SET Code = @Code, DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE AddressTitleID = @AddressTitleID;

        SELECT 1 AS Success, N'عنوانِ آدرس ویرایش شد.' AS Message, @AddressTitleID AS AddressTitleID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_ToggleAddressTitleActive
CREATE PROCEDURE dbo.sp_Crm_ToggleAddressTitleActive
    @AddressTitleID INT,
    @UserID         INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmAddressTitles WHERE AddressTitleID = @AddressTitleID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'عنوانِ آدرس یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmAddressTitles
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE AddressTitleID = @AddressTitleID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'عنوانِ آدرس غیرفعال شد.' ELSE N'عنوانِ آدرس فعال شد.' END AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetProvinces
/* ===================== CrmProvinces ===================== */
CREATE PROCEDURE dbo.sp_Crm_GetProvinces
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT ProvinceID, Code, DisplayName, SortOrder, IsActive,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate
    FROM dbo.CrmProvinces p
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR p.DisplayName LIKE N'%' + @SearchText + N'%'
           OR p.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR p.IsActive = @IsActive)
    ORDER BY p.SortOrder, p.ProvinceID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SaveProvince
CREATE PROCEDURE dbo.sp_Crm_SaveProvince
    @ProvinceID   INT           = NULL,
    @Code         NVARCHAR(64),
    @DisplayName  NVARCHAR(200),
    @SortOrder    INT           = 0,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@Code)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'کد الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.CrmProvinces
               WHERE Code = @Code AND (@ProvinceID IS NULL OR ProvinceID <> @ProvinceID))
    BEGIN SELECT 0 AS Success, N'این کد قبلاً استفاده شده است.' AS Message; RETURN; END

    IF @ProvinceID IS NULL
    BEGIN
        INSERT INTO dbo.CrmProvinces (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@Code, @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'استان ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS ProvinceID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmProvinces WHERE ProvinceID = @ProvinceID)
        BEGIN SELECT 0 AS Success, N'استان یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmProvinces
        SET Code = @Code, DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE ProvinceID = @ProvinceID;

        SELECT 1 AS Success, N'استان ویرایش شد.' AS Message, @ProvinceID AS ProvinceID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_ToggleProvinceActive
CREATE PROCEDURE dbo.sp_Crm_ToggleProvinceActive
    @ProvinceID INT,
    @UserID     INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmProvinces WHERE ProvinceID = @ProvinceID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'استان یافت نشد.' AS Message; RETURN; END

    IF @Current = 1 AND EXISTS (SELECT 1 FROM dbo.CrmCities c WHERE c.ProvinceID = @ProvinceID AND c.IsActive = 1)
    BEGIN SELECT 0 AS Success, N'این استان دارایِ یک یا چند شهرِ فعال است؛ ابتدا آن‌ها را غیرفعال کنید.' AS Message; RETURN; END

    UPDATE dbo.CrmProvinces
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE ProvinceID = @ProvinceID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'استان غیرفعال شد.' ELSE N'استان فعال شد.' END AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetCities
/* ===================== CrmCities (child of CrmProvinces) ===================== */
CREATE PROCEDURE dbo.sp_Crm_GetCities
    @ProvinceID INT           = NULL,
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT c.CityID, c.ProvinceID, c.Code, c.DisplayName, c.SortOrder, c.IsActive,
           c.Date_InsertFirst, c.UserID_InsertFirst, c.Date_LastUpdate, c.UserID_LastUpdate,
           p.DisplayName AS ProvinceName
    FROM dbo.CrmCities c
    JOIN dbo.CrmProvinces p ON p.ProvinceID = c.ProvinceID
    WHERE (@ProvinceID IS NULL OR c.ProvinceID = @ProvinceID)
      AND (@SearchText IS NULL OR @SearchText = N''
           OR c.DisplayName LIKE N'%' + @SearchText + N'%'
           OR c.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR c.IsActive = @IsActive)
    ORDER BY c.SortOrder, c.CityID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SaveCity
CREATE PROCEDURE dbo.sp_Crm_SaveCity
    @CityID       INT           = NULL,
    @ProvinceID   INT,
    @Code         NVARCHAR(64),
    @DisplayName  NVARCHAR(200),
    @SortOrder    INT           = 0,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmProvinces WHERE ProvinceID = @ProvinceID)
    BEGIN SELECT 0 AS Success, N'استانِ انتخاب‌شده معتبر نیست.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@Code)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'کد الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.CrmCities
               WHERE ProvinceID = @ProvinceID AND Code = @Code AND (@CityID IS NULL OR CityID <> @CityID))
    BEGIN SELECT 0 AS Success, N'این کد قبلاً در همین استان استفاده شده است.' AS Message; RETURN; END

    IF @CityID IS NULL
    BEGIN
        INSERT INTO dbo.CrmCities (ProvinceID, Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@ProvinceID, @Code, @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'شهر ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS CityID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmCities WHERE CityID = @CityID)
        BEGIN SELECT 0 AS Success, N'شهر یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmCities
        SET ProvinceID = @ProvinceID, Code = @Code, DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE CityID = @CityID;

        SELECT 1 AS Success, N'شهر ویرایش شد.' AS Message, @CityID AS CityID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_ToggleCityActive
CREATE PROCEDURE dbo.sp_Crm_ToggleCityActive
    @CityID INT,
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmCities WHERE CityID = @CityID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'شهر یافت نشد.' AS Message; RETURN; END

    IF @Current = 1 AND EXISTS (SELECT 1 FROM dbo.CrmCounties co WHERE co.CityID = @CityID AND co.IsActive = 1)
    BEGIN SELECT 0 AS Success, N'این شهر دارایِ یک یا چند شهرستانِ فعال است؛ ابتدا آن‌ها را غیرفعال کنید.' AS Message; RETURN; END

    UPDATE dbo.CrmCities
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE CityID = @CityID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'شهر غیرفعال شد.' ELSE N'شهر فعال شد.' END AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetCounties
/* ===================== CrmCounties (child of CrmCities) ===================== */
CREATE PROCEDURE dbo.sp_Crm_GetCounties
    @CityID     INT           = NULL,
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT co.CountyID, co.CityID, co.Code, co.DisplayName, co.SortOrder, co.IsActive,
           co.Date_InsertFirst, co.UserID_InsertFirst, co.Date_LastUpdate, co.UserID_LastUpdate,
           c.DisplayName AS CityName
    FROM dbo.CrmCounties co
    JOIN dbo.CrmCities c ON c.CityID = co.CityID
    WHERE (@CityID IS NULL OR co.CityID = @CityID)
      AND (@SearchText IS NULL OR @SearchText = N''
           OR co.DisplayName LIKE N'%' + @SearchText + N'%'
           OR co.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR co.IsActive = @IsActive)
    ORDER BY co.SortOrder, co.CountyID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SaveCounty
CREATE PROCEDURE dbo.sp_Crm_SaveCounty
    @CountyID     INT           = NULL,
    @CityID       INT,
    @Code         NVARCHAR(64),
    @DisplayName  NVARCHAR(200),
    @SortOrder    INT           = 0,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmCities WHERE CityID = @CityID)
    BEGIN SELECT 0 AS Success, N'شهرِ انتخاب‌شده معتبر نیست.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@Code)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'کد الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.CrmCounties
               WHERE CityID = @CityID AND Code = @Code AND (@CountyID IS NULL OR CountyID <> @CountyID))
    BEGIN SELECT 0 AS Success, N'این کد قبلاً در همین شهر استفاده شده است.' AS Message; RETURN; END

    IF @CountyID IS NULL
    BEGIN
        INSERT INTO dbo.CrmCounties (CityID, Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@CityID, @Code, @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'شهرستان ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS CountyID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmCounties WHERE CountyID = @CountyID)
        BEGIN SELECT 0 AS Success, N'شهرستان یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmCounties
        SET CityID = @CityID, Code = @Code, DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE CountyID = @CountyID;

        SELECT 1 AS Success, N'شهرستان ویرایش شد.' AS Message, @CountyID AS CountyID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_ToggleCountyActive
CREATE PROCEDURE dbo.sp_Crm_ToggleCountyActive
    @CountyID INT,
    @UserID   INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmCounties WHERE CountyID = @CountyID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'شهرستان یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmCounties
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE CountyID = @CountyID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'شهرستان غیرفعال شد.' ELSE N'شهرستان فعال شد.' END AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetMunicipalZones
/* ===================== CrmMunicipalZones ===================== */
CREATE PROCEDURE dbo.sp_Crm_GetMunicipalZones
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT MunicipalZoneID, Code, DisplayName, SortOrder, IsActive,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate
    FROM dbo.CrmMunicipalZones z
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR z.DisplayName LIKE N'%' + @SearchText + N'%'
           OR z.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR z.IsActive = @IsActive)
    ORDER BY z.SortOrder, z.MunicipalZoneID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SaveMunicipalZone
CREATE PROCEDURE dbo.sp_Crm_SaveMunicipalZone
    @MunicipalZoneID INT           = NULL,
    @Code            NVARCHAR(64),
    @DisplayName     NVARCHAR(200),
    @SortOrder       INT           = 0,
    @UserID          INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@Code)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'کد الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.CrmMunicipalZones
               WHERE Code = @Code AND (@MunicipalZoneID IS NULL OR MunicipalZoneID <> @MunicipalZoneID))
    BEGIN SELECT 0 AS Success, N'این کد قبلاً استفاده شده است.' AS Message; RETURN; END

    IF @MunicipalZoneID IS NULL
    BEGIN
        INSERT INTO dbo.CrmMunicipalZones (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@Code, @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'منطقهٔ شهرداری ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS MunicipalZoneID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmMunicipalZones WHERE MunicipalZoneID = @MunicipalZoneID)
        BEGIN SELECT 0 AS Success, N'منطقهٔ شهرداری یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmMunicipalZones
        SET Code = @Code, DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE MunicipalZoneID = @MunicipalZoneID;

        SELECT 1 AS Success, N'منطقهٔ شهرداری ویرایش شد.' AS Message, @MunicipalZoneID AS MunicipalZoneID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_ToggleMunicipalZoneActive
CREATE PROCEDURE dbo.sp_Crm_ToggleMunicipalZoneActive
    @MunicipalZoneID INT,
    @UserID          INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmMunicipalZones WHERE MunicipalZoneID = @MunicipalZoneID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'منطقهٔ شهرداری یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmMunicipalZones
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE MunicipalZoneID = @MunicipalZoneID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'منطقهٔ شهرداری غیرفعال شد.' ELSE N'منطقهٔ شهرداری فعال شد.' END AS Message;
END
GO

/* [DATA SEED] — Idempotent؛ این بخش توسطِ Triggerِ DDL ردیابی نمی‌شود (DML است،
   نه تغییرِ Schema)، پس دستی به پچ اضافه شد — هم‌الگو با patch 028. فقط فهرست‌هایِ
   پیش‌فرضِ کوچکِ UI (نوعِ تماس/عنوانِ آدرس/عنوانِ فرد) Seed می‌شوند؛ هیچ دادهٔ
   جغرافیایی (استان/شهر/شهرستان) Seed نمی‌شود — طبقِ تصمیمِ صریحِ کاربر. */

-- CrmContactTypes defaults
IF NOT EXISTS (SELECT 1 FROM dbo.CrmContactTypes WHERE Code = N'PHONE')
    INSERT INTO dbo.CrmContactTypes (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst) VALUES (N'PHONE', N'تلفن', 1, 1, SYSDATETIME());
IF NOT EXISTS (SELECT 1 FROM dbo.CrmContactTypes WHERE Code = N'MOBILE')
    INSERT INTO dbo.CrmContactTypes (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst) VALUES (N'MOBILE', N'موبایل', 2, 1, SYSDATETIME());
IF NOT EXISTS (SELECT 1 FROM dbo.CrmContactTypes WHERE Code = N'EMAIL')
    INSERT INTO dbo.CrmContactTypes (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst) VALUES (N'EMAIL', N'ایمیل', 3, 1, SYSDATETIME());
IF NOT EXISTS (SELECT 1 FROM dbo.CrmContactTypes WHERE Code = N'FAX')
    INSERT INTO dbo.CrmContactTypes (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst) VALUES (N'FAX', N'فکس', 4, 1, SYSDATETIME());
IF NOT EXISTS (SELECT 1 FROM dbo.CrmContactTypes WHERE Code = N'WEBSITE')
    INSERT INTO dbo.CrmContactTypes (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst) VALUES (N'WEBSITE', N'وب‌سایت', 5, 1, SYSDATETIME());
IF NOT EXISTS (SELECT 1 FROM dbo.CrmContactTypes WHERE Code = N'OTHER')
    INSERT INTO dbo.CrmContactTypes (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst) VALUES (N'OTHER', N'سایر', 6, 1, SYSDATETIME());
GO

-- CrmAddressTitles defaults
IF NOT EXISTS (SELECT 1 FROM dbo.CrmAddressTitles WHERE Code = N'HEAD_OFFICE')
    INSERT INTO dbo.CrmAddressTitles (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst) VALUES (N'HEAD_OFFICE', N'دفتر مرکزی', 1, 1, SYSDATETIME());
IF NOT EXISTS (SELECT 1 FROM dbo.CrmAddressTitles WHERE Code = N'BRANCH')
    INSERT INTO dbo.CrmAddressTitles (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst) VALUES (N'BRANCH', N'شعبه', 2, 1, SYSDATETIME());
IF NOT EXISTS (SELECT 1 FROM dbo.CrmAddressTitles WHERE Code = N'WAREHOUSE')
    INSERT INTO dbo.CrmAddressTitles (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst) VALUES (N'WAREHOUSE', N'انبار', 3, 1, SYSDATETIME());
IF NOT EXISTS (SELECT 1 FROM dbo.CrmAddressTitles WHERE Code = N'STORE')
    INSERT INTO dbo.CrmAddressTitles (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst) VALUES (N'STORE', N'فروشگاه', 4, 1, SYSDATETIME());
IF NOT EXISTS (SELECT 1 FROM dbo.CrmAddressTitles WHERE Code = N'FACTORY')
    INSERT INTO dbo.CrmAddressTitles (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst) VALUES (N'FACTORY', N'کارخانه', 5, 1, SYSDATETIME());
IF NOT EXISTS (SELECT 1 FROM dbo.CrmAddressTitles WHERE Code = N'DELIVERY')
    INSERT INTO dbo.CrmAddressTitles (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst) VALUES (N'DELIVERY', N'محل تحویل', 6, 1, SYSDATETIME());
IF NOT EXISTS (SELECT 1 FROM dbo.CrmAddressTitles WHERE Code = N'OTHER')
    INSERT INTO dbo.CrmAddressTitles (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst) VALUES (N'OTHER', N'سایر', 7, 1, SYSDATETIME());
GO

-- CrmTitles defaults (individual salutation)
IF NOT EXISTS (SELECT 1 FROM dbo.CrmTitles WHERE Code = N'MR')
    INSERT INTO dbo.CrmTitles (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst) VALUES (N'MR', N'آقای', 1, 1, SYSDATETIME());
IF NOT EXISTS (SELECT 1 FROM dbo.CrmTitles WHERE Code = N'MRS')
    INSERT INTO dbo.CrmTitles (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst) VALUES (N'MRS', N'خانم', 2, 1, SYSDATETIME());
IF NOT EXISTS (SELECT 1 FROM dbo.CrmTitles WHERE Code = N'DR')
    INSERT INTO dbo.CrmTitles (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst) VALUES (N'DR', N'دکتر', 3, 1, SYSDATETIME());
IF NOT EXISTS (SELECT 1 FROM dbo.CrmTitles WHERE Code = N'ENG')
    INSERT INTO dbo.CrmTitles (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst) VALUES (N'ENG', N'مهندس', 4, 1, SYSDATETIME());
GO

-- Permission جدید — هم‌الگو با patch 028
IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE PermissionCode = N'CRM_MANAGE_MASTER_DATA')
    INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, Description, SortOrder, IsActive)
    VALUES (N'CRM_MANAGE_MASTER_DATA', N'مدیریتِ Master Dataهایِ CRM', N'CRM', N'ایجاد/ویرایش/فعال‌سازیِ فهرست‌هایِ پایهٔ CRM (دپارتمان، نوع، فعالیت، عنوان، سمت، نقش، جغرافیا و...)', 1, 1);
GO

-- اعطایِ خودکارِ Permissionِ جدید به نقشِ «مدیرِ سیستم» (RoleID=1)
IF EXISTS (SELECT 1 FROM dbo.Roles WHERE RoleID = 1)
BEGIN
    INSERT INTO dbo.RolePermissions (RoleID, PermissionID, CanAccess, IsActive)
    SELECT 1, p.PermissionID, 1, 1
    FROM dbo.Permissions p
    WHERE p.PermissionCode = N'CRM_MANAGE_MASTER_DATA'
      AND NOT EXISTS (SELECT 1 FROM dbo.RolePermissions rp WHERE rp.RoleID = 1 AND rp.PermissionID = p.PermissionID);
END
GO

/* ثبتِ منویِ CRM (پوشهٔ CRM + سه صفحهٔ Master Data) در سیستمِ منویِ پویایِ
   موجود (dbo.Menu/RoleMenus، از‌پیش‌موجود — نه جدولی از این پچ) — DML است،
   پس دستی اضافه شد؛ Idempotent با چکِ Url. */
IF NOT EXISTS (SELECT 1 FROM dbo.Menu WHERE MenuTitle = N'CRM' AND ParentID IS NULL)
BEGIN
    DECLARE @CrmFolderID INT;
    EXEC dbo.sp_InsertMenu @ParentID = NULL, @MenuTitle = N'CRM', @MenuKind = N'FOLDER', @Icon = N'TeamOutlined', @SortOrder = 79, @CreateUser = NULL;
    SELECT @CrmFolderID = MenuID FROM dbo.Menu WHERE MenuTitle = N'CRM' AND ParentID IS NULL;

    IF NOT EXISTS (SELECT 1 FROM dbo.Menu WHERE Url = N'/crm/classification')
        EXEC dbo.sp_InsertMenu @ParentID = @CrmFolderID, @MenuTitle = N'طبقه‌بندی', @MenuKind = N'PAGE', @Url = N'/crm/classification', @SortOrder = 1;
    IF NOT EXISTS (SELECT 1 FROM dbo.Menu WHERE Url = N'/crm/geography')
        EXEC dbo.sp_InsertMenu @ParentID = @CrmFolderID, @MenuTitle = N'جغرافیا', @MenuKind = N'PAGE', @Url = N'/crm/geography', @SortOrder = 2;
    IF NOT EXISTS (SELECT 1 FROM dbo.Menu WHERE Url = N'/crm/directory')
        EXEC dbo.sp_InsertMenu @ParentID = @CrmFolderID, @MenuTitle = N'فهرست‌های CRM', @MenuKind = N'PAGE', @Url = N'/crm/directory', @SortOrder = 3;

    EXEC dbo.sp_GrantAllMenusToRole @RoleID = 1;
END
GO

