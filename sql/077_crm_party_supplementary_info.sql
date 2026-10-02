/* ==========================================================================
   پچ خودکار شماره: 077 | نام: crm_party_supplementary_info
   تاریخ: 2026-10-02 07:37:32 | شامل 8 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: CrmOwnershipTypes
CREATE TABLE dbo.CrmOwnershipTypes
(
    OwnershipTypeID    INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmOwnershipTypes PRIMARY KEY,
    Code               NVARCHAR(64)  NOT NULL,
    DisplayName        NVARCHAR(200) NOT NULL,
    SortOrder          INT NOT NULL CONSTRAINT DF_CrmOwnershipTypes_SortOrder DEFAULT (0),
    IsActive           BIT NOT NULL CONSTRAINT DF_CrmOwnershipTypes_IsActive DEFAULT (1),
    RowGuid            UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmOwnershipTypes_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmOwnershipTypes_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate    DATETIME2 NULL,
    UserID_LastUpdate  INT NULL,
    CONSTRAINT UQ_CrmOwnershipTypes_Code UNIQUE (Code)
)
GO

/* [DATA SEED] — Idempotent؛ DML است و توسطِ Triggerِ DDL ردیابی نمی‌شود، پس دستی اضافه شد
   (هم‌الگو با patch 073). فقط دادهٔ اولیه است — Admin می‌تواند ویرایش/غیرفعال کند یا نوعِ جدید
   بسازد (Codeِ خودکارِ عددی از ۱۰۳ به بعد). */
IF NOT EXISTS (SELECT 1 FROM dbo.CrmOwnershipTypes WHERE Code = N'101' OR DisplayName = N'مالک')
    INSERT INTO dbo.CrmOwnershipTypes (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst) VALUES (N'101', N'مالک', 1, 1, SYSDATETIME());
IF NOT EXISTS (SELECT 1 FROM dbo.CrmOwnershipTypes WHERE Code = N'102' OR DisplayName = N'مستأجر')
    INSERT INTO dbo.CrmOwnershipTypes (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst) VALUES (N'102', N'مستأجر', 2, 1, SYSDATETIME());
GO

-- [CREATE_TABLE] روی TABLE: CrmPartySupplementaryInfo
CREATE TABLE dbo.CrmPartySupplementaryInfo
(
    PartyID            INT NOT NULL CONSTRAINT PK_CrmPartySupplementaryInfo PRIMARY KEY,
    OwnershipTypeID    INT NULL,
    AreaSqm            DECIMAL(12,2) NULL,
    RowGuid            UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmPartySupplementaryInfo_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmPartySupplementaryInfo_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate    DATETIME2 NULL,
    UserID_LastUpdate  INT NULL,
    CONSTRAINT FK_CrmPartySupplementaryInfo_Party FOREIGN KEY (PartyID) REFERENCES dbo.CrmParties(PartyID),
    CONSTRAINT FK_CrmPartySupplementaryInfo_OwnershipType FOREIGN KEY (OwnershipTypeID) REFERENCES dbo.CrmOwnershipTypes(OwnershipTypeID),
    CONSTRAINT CK_CrmPartySupplementaryInfo_AreaSqm CHECK (AreaSqm IS NULL OR AreaSqm >= 0)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmPartySupplementaryInfo_OwnershipTypeID
CREATE INDEX IX_CrmPartySupplementaryInfo_OwnershipTypeID ON dbo.CrmPartySupplementaryInfo(OwnershipTypeID)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetOwnershipTypes
-- 3) SPهایِ Master Dataِ نوعِ مالکیت
CREATE PROCEDURE dbo.sp_Crm_GetOwnershipTypes
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT OwnershipTypeID, Code, DisplayName, SortOrder, IsActive,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate
    FROM dbo.CrmOwnershipTypes t
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR t.DisplayName LIKE N'%' + @SearchText + N'%'
           OR t.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR t.IsActive = @IsActive)
    ORDER BY t.SortOrder, t.OwnershipTypeID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SaveOwnershipType
CREATE PROCEDURE dbo.sp_Crm_SaveOwnershipType
    @OwnershipTypeID INT           = NULL,
    @DisplayName     NVARCHAR(200),
    @SortOrder       INT           = 0,
    @UserID          INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF @OwnershipTypeID IS NULL
    BEGIN
        DECLARE @NextCode INT;
        SELECT @NextCode = ISNULL(MAX(TRY_CAST(Code AS INT)), 100) + 1 FROM dbo.CrmOwnershipTypes;
        IF @NextCode < 101 SET @NextCode = 101;

        INSERT INTO dbo.CrmOwnershipTypes (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (CAST(@NextCode AS NVARCHAR(64)), LTRIM(RTRIM(@DisplayName)), ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'نوعِ مالکیت ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS OwnershipTypeID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmOwnershipTypes WHERE OwnershipTypeID = @OwnershipTypeID)
        BEGIN SELECT 0 AS Success, N'نوعِ مالکیت یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmOwnershipTypes
        SET DisplayName = LTRIM(RTRIM(@DisplayName)), SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE OwnershipTypeID = @OwnershipTypeID;

        SELECT 1 AS Success, N'نوعِ مالکیت ویرایش شد.' AS Message, @OwnershipTypeID AS OwnershipTypeID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_ToggleOwnershipTypeActive
CREATE PROCEDURE dbo.sp_Crm_ToggleOwnershipTypeActive
    @OwnershipTypeID INT,
    @UserID          INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmOwnershipTypes WHERE OwnershipTypeID = @OwnershipTypeID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'نوعِ مالکیت یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmOwnershipTypes
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE OwnershipTypeID = @OwnershipTypeID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'نوعِ مالکیت غیرفعال شد.' ELSE N'نوعِ مالکیت فعال شد.' END AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetPartySupplementaryInfo
-- 4) SPهایِ اطلاعاتِ تکمیلی
CREATE PROCEDURE dbo.sp_Crm_GetPartySupplementaryInfo
    @PartyID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT s.PartyID, s.OwnershipTypeID,
           ot.DisplayName AS OwnershipTypeName, ot.IsActive AS OwnershipTypeIsActive,
           s.AreaSqm,
           s.Date_InsertFirst, s.UserID_InsertFirst, s.Date_LastUpdate, s.UserID_LastUpdate,
           COALESCE(s.Date_LastUpdate, s.Date_InsertFirst) AS ModifiedAt,
           (u.FirstName + N' ' + u.LastName) AS ModifiedByName
    FROM dbo.CrmPartySupplementaryInfo s
    LEFT JOIN dbo.CrmOwnershipTypes ot ON ot.OwnershipTypeID = s.OwnershipTypeID
    LEFT JOIN dbo.Users u ON u.UserID = COALESCE(s.UserID_LastUpdate, s.UserID_InsertFirst)
    WHERE s.PartyID = @PartyID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SavePartySupplementaryInfo
CREATE PROCEDURE dbo.sp_Crm_SavePartySupplementaryInfo
    @PartyID         INT,
    @OwnershipTypeID INT           = NULL,
    @AreaSqm         DECIMAL(12,2) = NULL,
    @UserID          INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حساب یافت نشد.' AS Message; RETURN; END

    IF @AreaSqm IS NOT NULL AND @AreaSqm < 0
    BEGIN SELECT 0 AS Success, N'متراژ نمی‌تواند منفی باشد.' AS Message; RETURN; END

    IF @OwnershipTypeID IS NOT NULL
    BEGIN
        DECLARE @TypeActive BIT;
        SELECT @TypeActive = IsActive FROM dbo.CrmOwnershipTypes WHERE OwnershipTypeID = @OwnershipTypeID;

        IF @TypeActive IS NULL
        BEGIN SELECT 0 AS Success, N'نوعِ مالکیت یافت نشد.' AS Message; RETURN; END

        -- نوعِ غیرفعال برایِ انتخابِ جدید مجاز نیست؛ اما اگر همین حالا رویِ این طرف‌حساب ثبت است، حفظ می‌شود
        IF @TypeActive = 0 AND NOT EXISTS (
            SELECT 1 FROM dbo.CrmPartySupplementaryInfo WHERE PartyID = @PartyID AND OwnershipTypeID = @OwnershipTypeID)
        BEGIN SELECT 0 AS Success, N'نوعِ مالکیتِ انتخاب‌شده غیرفعال است.' AS Message; RETURN; END
    END

    BEGIN TRAN;
    IF EXISTS (SELECT 1 FROM dbo.CrmPartySupplementaryInfo WITH (UPDLOCK, HOLDLOCK) WHERE PartyID = @PartyID)
        UPDATE dbo.CrmPartySupplementaryInfo
        SET OwnershipTypeID = @OwnershipTypeID, AreaSqm = @AreaSqm,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE PartyID = @PartyID;
    ELSE
        INSERT INTO dbo.CrmPartySupplementaryInfo (PartyID, OwnershipTypeID, AreaSqm, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@PartyID, @OwnershipTypeID, @AreaSqm, SYSDATETIME(), @UserID);
    COMMIT;

    SELECT 1 AS Success, N'اطلاعاتِ تکمیلی ذخیره شد.' AS Message, @PartyID AS PartyID;
END
GO
