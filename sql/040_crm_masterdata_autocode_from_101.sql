/* ==========================================================================
   پچ خودکار شماره: 040 | نام: crm_masterdata_autocode_from_101
   تاریخ: 2026-09-24 06:56:24 | شامل 12 دستور SQL
   ========================================================================== */

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveDepartment
-- =============================================================
-- CRM Master Data: auto-generate Code starting at 101 (mirrors
-- sp_InsertMenu / sp_InsertUser / sp_InsertRole's existing pattern:
-- MAX(CAST(Code AS INT)) among numeric codes, +1, floored at 101).
-- Code becomes immutable after creation (not passed/updated).
-- =============================================================

/* ===================== CrmDepartments ===================== */
ALTER PROCEDURE dbo.sp_Crm_SaveDepartment
    @DepartmentID INT           = NULL,
    @DisplayName  NVARCHAR(200),
    @SortOrder    INT           = 0,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF @DepartmentID IS NULL
    BEGIN
        DECLARE @NextCode1 INT;
        SELECT @NextCode1 = ISNULL(MAX(CAST(Code AS INT)), 100) + 1 FROM dbo.CrmDepartments WHERE ISNUMERIC(Code) = 1;
        IF @NextCode1 < 101 SET @NextCode1 = 101;

        INSERT INTO dbo.CrmDepartments (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (CAST(@NextCode1 AS NVARCHAR(64)), @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'دپارتمان ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS DepartmentID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmDepartments WHERE DepartmentID = @DepartmentID)
        BEGIN SELECT 0 AS Success, N'دپارتمان یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmDepartments
        SET DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE DepartmentID = @DepartmentID;

        SELECT 1 AS Success, N'دپارتمان ویرایش شد.' AS Message, @DepartmentID AS DepartmentID;
    END
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SavePartyType
/* ===================== CrmPartyTypes ===================== */
ALTER PROCEDURE dbo.sp_Crm_SavePartyType
    @PartyTypeID  INT           = NULL,
    @DisplayName  NVARCHAR(200),
    @SortOrder    INT           = 0,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF @PartyTypeID IS NULL
    BEGIN
        DECLARE @NextCode2 INT;
        SELECT @NextCode2 = ISNULL(MAX(CAST(Code AS INT)), 100) + 1 FROM dbo.CrmPartyTypes WHERE ISNUMERIC(Code) = 1;
        IF @NextCode2 < 101 SET @NextCode2 = 101;

        INSERT INTO dbo.CrmPartyTypes (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (CAST(@NextCode2 AS NVARCHAR(64)), @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'نوع ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS PartyTypeID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmPartyTypes WHERE PartyTypeID = @PartyTypeID)
        BEGIN SELECT 0 AS Success, N'نوع یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmPartyTypes
        SET DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE PartyTypeID = @PartyTypeID;

        SELECT 1 AS Success, N'نوع ویرایش شد.' AS Message, @PartyTypeID AS PartyTypeID;
    END
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveActivity
/* ===================== CrmActivities ===================== */
ALTER PROCEDURE dbo.sp_Crm_SaveActivity
    @ActivityID   INT           = NULL,
    @PartyTypeID  INT,
    @DisplayName  NVARCHAR(200),
    @SortOrder    INT           = 0,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmPartyTypes WHERE PartyTypeID = @PartyTypeID)
    BEGIN SELECT 0 AS Success, N'نوعِ انتخاب‌شده معتبر نیست.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF @ActivityID IS NULL
    BEGIN
        DECLARE @NextCode3 INT;
        SELECT @NextCode3 = ISNULL(MAX(CAST(Code AS INT)), 100) + 1 FROM dbo.CrmActivities WHERE ISNUMERIC(Code) = 1;
        IF @NextCode3 < 101 SET @NextCode3 = 101;

        INSERT INTO dbo.CrmActivities (PartyTypeID, Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@PartyTypeID, CAST(@NextCode3 AS NVARCHAR(64)), @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'فعالیت ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS ActivityID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmActivities WHERE ActivityID = @ActivityID)
        BEGIN SELECT 0 AS Success, N'فعالیت یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmActivities
        SET PartyTypeID = @PartyTypeID, DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE ActivityID = @ActivityID;

        SELECT 1 AS Success, N'فعالیت ویرایش شد.' AS Message, @ActivityID AS ActivityID;
    END
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveTitle
/* ===================== CrmTitles ===================== */
ALTER PROCEDURE dbo.sp_Crm_SaveTitle
    @TitleID      INT           = NULL,
    @DisplayName  NVARCHAR(200),
    @SortOrder    INT           = 0,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF @TitleID IS NULL
    BEGIN
        DECLARE @NextCode4 INT;
        SELECT @NextCode4 = ISNULL(MAX(CAST(Code AS INT)), 100) + 1 FROM dbo.CrmTitles WHERE ISNUMERIC(Code) = 1;
        IF @NextCode4 < 101 SET @NextCode4 = 101;

        INSERT INTO dbo.CrmTitles (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (CAST(@NextCode4 AS NVARCHAR(64)), @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'عنوان ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS TitleID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmTitles WHERE TitleID = @TitleID)
        BEGIN SELECT 0 AS Success, N'عنوان یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmTitles
        SET DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE TitleID = @TitleID;

        SELECT 1 AS Success, N'عنوان ویرایش شد.' AS Message, @TitleID AS TitleID;
    END
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SavePosition
/* ===================== CrmPositions ===================== */
ALTER PROCEDURE dbo.sp_Crm_SavePosition
    @PositionID   INT           = NULL,
    @DisplayName  NVARCHAR(200),
    @SortOrder    INT           = 0,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF @PositionID IS NULL
    BEGIN
        DECLARE @NextCode5 INT;
        SELECT @NextCode5 = ISNULL(MAX(CAST(Code AS INT)), 100) + 1 FROM dbo.CrmPositions WHERE ISNUMERIC(Code) = 1;
        IF @NextCode5 < 101 SET @NextCode5 = 101;

        INSERT INTO dbo.CrmPositions (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (CAST(@NextCode5 AS NVARCHAR(64)), @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'سمت ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS PositionID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmPositions WHERE PositionID = @PositionID)
        BEGIN SELECT 0 AS Success, N'سمت یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmPositions
        SET DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE PositionID = @PositionID;

        SELECT 1 AS Success, N'سمت ویرایش شد.' AS Message, @PositionID AS PositionID;
    END
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveContactRole
/* ===================== CrmContactRoles ===================== */
ALTER PROCEDURE dbo.sp_Crm_SaveContactRole
    @ContactRoleID INT           = NULL,
    @DisplayName   NVARCHAR(200),
    @SortOrder     INT           = 0,
    @UserID        INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF @ContactRoleID IS NULL
    BEGIN
        DECLARE @NextCode6 INT;
        SELECT @NextCode6 = ISNULL(MAX(CAST(Code AS INT)), 100) + 1 FROM dbo.CrmContactRoles WHERE ISNUMERIC(Code) = 1;
        IF @NextCode6 < 101 SET @NextCode6 = 101;

        INSERT INTO dbo.CrmContactRoles (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (CAST(@NextCode6 AS NVARCHAR(64)), @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'نقش ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS ContactRoleID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmContactRoles WHERE ContactRoleID = @ContactRoleID)
        BEGIN SELECT 0 AS Success, N'نقش یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmContactRoles
        SET DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE ContactRoleID = @ContactRoleID;

        SELECT 1 AS Success, N'نقش ویرایش شد.' AS Message, @ContactRoleID AS ContactRoleID;
    END
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveContactType
/* ===================== CrmContactTypes ===================== */
ALTER PROCEDURE dbo.sp_Crm_SaveContactType
    @ContactTypeID INT           = NULL,
    @DisplayName   NVARCHAR(200),
    @SortOrder     INT           = 0,
    @UserID        INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF @ContactTypeID IS NULL
    BEGIN
        DECLARE @NextCode7 INT;
        SELECT @NextCode7 = ISNULL(MAX(CAST(Code AS INT)), 100) + 1 FROM dbo.CrmContactTypes WHERE ISNUMERIC(Code) = 1;
        IF @NextCode7 < 101 SET @NextCode7 = 101;

        INSERT INTO dbo.CrmContactTypes (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (CAST(@NextCode7 AS NVARCHAR(64)), @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'نوعِ تماس ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS ContactTypeID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmContactTypes WHERE ContactTypeID = @ContactTypeID)
        BEGIN SELECT 0 AS Success, N'نوعِ تماس یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmContactTypes
        SET DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE ContactTypeID = @ContactTypeID;

        SELECT 1 AS Success, N'نوعِ تماس ویرایش شد.' AS Message, @ContactTypeID AS ContactTypeID;
    END
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveAddressTitle
/* ===================== CrmAddressTitles ===================== */
ALTER PROCEDURE dbo.sp_Crm_SaveAddressTitle
    @AddressTitleID INT           = NULL,
    @DisplayName    NVARCHAR(200),
    @SortOrder      INT           = 0,
    @UserID         INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF @AddressTitleID IS NULL
    BEGIN
        DECLARE @NextCode8 INT;
        SELECT @NextCode8 = ISNULL(MAX(CAST(Code AS INT)), 100) + 1 FROM dbo.CrmAddressTitles WHERE ISNUMERIC(Code) = 1;
        IF @NextCode8 < 101 SET @NextCode8 = 101;

        INSERT INTO dbo.CrmAddressTitles (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (CAST(@NextCode8 AS NVARCHAR(64)), @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'عنوانِ آدرس ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS AddressTitleID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmAddressTitles WHERE AddressTitleID = @AddressTitleID)
        BEGIN SELECT 0 AS Success, N'عنوانِ آدرس یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmAddressTitles
        SET DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE AddressTitleID = @AddressTitleID;

        SELECT 1 AS Success, N'عنوانِ آدرس ویرایش شد.' AS Message, @AddressTitleID AS AddressTitleID;
    END
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveProvince
/* ===================== CrmProvinces ===================== */
ALTER PROCEDURE dbo.sp_Crm_SaveProvince
    @ProvinceID   INT           = NULL,
    @DisplayName  NVARCHAR(200),
    @SortOrder    INT           = 0,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF @ProvinceID IS NULL
    BEGIN
        DECLARE @NextCode9 INT;
        SELECT @NextCode9 = ISNULL(MAX(CAST(Code AS INT)), 100) + 1 FROM dbo.CrmProvinces WHERE ISNUMERIC(Code) = 1;
        IF @NextCode9 < 101 SET @NextCode9 = 101;

        INSERT INTO dbo.CrmProvinces (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (CAST(@NextCode9 AS NVARCHAR(64)), @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'استان ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS ProvinceID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmProvinces WHERE ProvinceID = @ProvinceID)
        BEGIN SELECT 0 AS Success, N'استان یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmProvinces
        SET DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE ProvinceID = @ProvinceID;

        SELECT 1 AS Success, N'استان ویرایش شد.' AS Message, @ProvinceID AS ProvinceID;
    END
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveCity
/* ===================== CrmCities ===================== */
ALTER PROCEDURE dbo.sp_Crm_SaveCity
    @CityID       INT           = NULL,
    @ProvinceID   INT,
    @DisplayName  NVARCHAR(200),
    @SortOrder    INT           = 0,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmProvinces WHERE ProvinceID = @ProvinceID)
    BEGIN SELECT 0 AS Success, N'استانِ انتخاب‌شده معتبر نیست.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF @CityID IS NULL
    BEGIN
        DECLARE @NextCode10 INT;
        SELECT @NextCode10 = ISNULL(MAX(CAST(Code AS INT)), 100) + 1 FROM dbo.CrmCities WHERE ISNUMERIC(Code) = 1;
        IF @NextCode10 < 101 SET @NextCode10 = 101;

        INSERT INTO dbo.CrmCities (ProvinceID, Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@ProvinceID, CAST(@NextCode10 AS NVARCHAR(64)), @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'شهر ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS CityID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmCities WHERE CityID = @CityID)
        BEGIN SELECT 0 AS Success, N'شهر یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmCities
        SET ProvinceID = @ProvinceID, DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE CityID = @CityID;

        SELECT 1 AS Success, N'شهر ویرایش شد.' AS Message, @CityID AS CityID;
    END
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveCounty
/* ===================== CrmCounties ===================== */
ALTER PROCEDURE dbo.sp_Crm_SaveCounty
    @CountyID     INT           = NULL,
    @CityID       INT,
    @DisplayName  NVARCHAR(200),
    @SortOrder    INT           = 0,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmCities WHERE CityID = @CityID)
    BEGIN SELECT 0 AS Success, N'شهرِ انتخاب‌شده معتبر نیست.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF @CountyID IS NULL
    BEGIN
        DECLARE @NextCode11 INT;
        SELECT @NextCode11 = ISNULL(MAX(CAST(Code AS INT)), 100) + 1 FROM dbo.CrmCounties WHERE ISNUMERIC(Code) = 1;
        IF @NextCode11 < 101 SET @NextCode11 = 101;

        INSERT INTO dbo.CrmCounties (CityID, Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@CityID, CAST(@NextCode11 AS NVARCHAR(64)), @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'شهرستان ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS CountyID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmCounties WHERE CountyID = @CountyID)
        BEGIN SELECT 0 AS Success, N'شهرستان یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmCounties
        SET CityID = @CityID, DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE CountyID = @CountyID;

        SELECT 1 AS Success, N'شهرستان ویرایش شد.' AS Message, @CountyID AS CountyID;
    END
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveMunicipalZone
/* ===================== CrmMunicipalZones ===================== */
ALTER PROCEDURE dbo.sp_Crm_SaveMunicipalZone
    @MunicipalZoneID INT           = NULL,
    @DisplayName     NVARCHAR(200),
    @SortOrder       INT           = 0,
    @UserID          INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF @MunicipalZoneID IS NULL
    BEGIN
        DECLARE @NextCode12 INT;
        SELECT @NextCode12 = ISNULL(MAX(CAST(Code AS INT)), 100) + 1 FROM dbo.CrmMunicipalZones WHERE ISNUMERIC(Code) = 1;
        IF @NextCode12 < 101 SET @NextCode12 = 101;

        INSERT INTO dbo.CrmMunicipalZones (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (CAST(@NextCode12 AS NVARCHAR(64)), @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'منطقهٔ شهرداری ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS MunicipalZoneID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmMunicipalZones WHERE MunicipalZoneID = @MunicipalZoneID)
        BEGIN SELECT 0 AS Success, N'منطقهٔ شهرداری یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmMunicipalZones
        SET DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE MunicipalZoneID = @MunicipalZoneID;

        SELECT 1 AS Success, N'منطقهٔ شهرداری ویرایش شد.' AS Message, @MunicipalZoneID AS MunicipalZoneID;
    END
END
GO

