/* ==========================================================================
   پچ خودکار شماره: 052 | نام: crm_party_classifications_multi_value
   تاریخ: 2026-09-24 21:48:04 | شامل 5 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: CrmPartyClassifications
CREATE TABLE dbo.CrmPartyClassifications (
    ClassificationID   INT             IDENTITY(1,1) NOT NULL,
    PartyID             INT             NOT NULL,
    DepartmentID        INT             NULL,
    PartyTypeID          INT             NULL,
    ActivityID           INT             NULL,
    IsActive             BIT             NOT NULL DEFAULT (1),
    RowGuid              UNIQUEIDENTIFIER NOT NULL DEFAULT (NEWID()),
    Date_InsertFirst     DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    UserID_InsertFirst   INT             NULL,
    Date_LastUpdate      DATETIME2       NULL,
    UserID_LastUpdate    INT             NULL,
    CONSTRAINT PK_CrmPartyClassifications PRIMARY KEY (ClassificationID),
    CONSTRAINT FK_CrmPartyClassifications_Party FOREIGN KEY (PartyID) REFERENCES dbo.CrmParties(PartyID),
    CONSTRAINT FK_CrmPartyClassifications_Department FOREIGN KEY (DepartmentID) REFERENCES dbo.CrmDepartments(DepartmentID),
    CONSTRAINT FK_CrmPartyClassifications_PartyType FOREIGN KEY (PartyTypeID) REFERENCES dbo.CrmPartyTypes(PartyTypeID),
    CONSTRAINT FK_CrmPartyClassifications_Activity FOREIGN KEY (ActivityID) REFERENCES dbo.CrmActivities(ActivityID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmPartyClassifications_PartyID
CREATE INDEX IX_CrmPartyClassifications_PartyID ON dbo.CrmPartyClassifications(PartyID)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetPartyClassifications
CREATE PROCEDURE dbo.sp_Crm_GetPartyClassifications
    @PartyID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT pc.ClassificationID, pc.PartyID, pc.DepartmentID, pc.PartyTypeID, pc.ActivityID, pc.IsActive,
           pc.Date_InsertFirst, pc.UserID_InsertFirst, pc.Date_LastUpdate, pc.UserID_LastUpdate,
           d.DisplayName AS DepartmentName, pt.DisplayName AS PartyTypeName, a.DisplayName AS ActivityName
    FROM dbo.CrmPartyClassifications pc
    LEFT JOIN dbo.CrmDepartments d ON d.DepartmentID = pc.DepartmentID
    LEFT JOIN dbo.CrmPartyTypes pt ON pt.PartyTypeID = pc.PartyTypeID
    LEFT JOIN dbo.CrmActivities a ON a.ActivityID = pc.ActivityID
    WHERE pc.PartyID = @PartyID
    ORDER BY pc.ClassificationID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SavePartyClassification
CREATE PROCEDURE dbo.sp_Crm_SavePartyClassification
    @ClassificationID INT = NULL,
    @PartyID          INT,
    @DepartmentID     INT = NULL,
    @PartyTypeID      INT = NULL,
    @ActivityID       INT = NULL,
    @UserID           INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حسابِ مربوطه یافت نشد.' AS Message; RETURN; END

    IF @DepartmentID IS NULL AND @PartyTypeID IS NULL AND @ActivityID IS NULL
    BEGIN SELECT 0 AS Success, N'حداقل یکی از دپارتمان/نوع/فعالیت باید انتخاب شود.' AS Message; RETURN; END

    IF @ActivityID IS NOT NULL AND @PartyTypeID IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM dbo.CrmActivities WHERE ActivityID = @ActivityID AND PartyTypeID = @PartyTypeID)
    BEGIN SELECT 0 AS Success, N'فعالیتِ انتخاب‌شده متعلق به نوعِ انتخاب‌شده نیست.' AS Message; RETURN; END

    IF @ClassificationID IS NULL
    BEGIN
        INSERT INTO dbo.CrmPartyClassifications (PartyID, DepartmentID, PartyTypeID, ActivityID, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@PartyID, @DepartmentID, @PartyTypeID, @ActivityID, 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'دسته‌بندی ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS ClassificationID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmPartyClassifications WHERE ClassificationID = @ClassificationID)
        BEGIN SELECT 0 AS Success, N'دسته‌بندی یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmPartyClassifications
        SET DepartmentID = @DepartmentID, PartyTypeID = @PartyTypeID, ActivityID = @ActivityID,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE ClassificationID = @ClassificationID;

        SELECT 1 AS Success, N'دسته‌بندی ویرایش شد.' AS Message, @ClassificationID AS ClassificationID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_TogglePartyClassificationActive
CREATE PROCEDURE dbo.sp_Crm_TogglePartyClassificationActive
    @ClassificationID INT,
    @UserID           INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmPartyClassifications WHERE ClassificationID = @ClassificationID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'دسته‌بندی یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmPartyClassifications
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE ClassificationID = @ClassificationID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'دسته‌بندی غیرفعال شد.' ELSE N'دسته‌بندی فعال شد.' END AS Message;
END
GO

-- [DML — دستی، خارج از DDL Trigger] مهاجرتِ مقادیرِ تک‌ارزشیِ فعلیِ CrmParties به اولین
-- ردیفِ دسته‌بندیِ هر طرف‌حساب، تا داده‌ای از بین نرود. ستون‌هایِ قدیمی رویِ CrmParties
-- (DepartmentID/PartyTypeID/ActivityID) حذف نشده‌اند، فقط دیگر در UI استفاده نمی‌شوند.
INSERT INTO dbo.CrmPartyClassifications (PartyID, DepartmentID, PartyTypeID, ActivityID, IsActive, Date_InsertFirst, UserID_InsertFirst)
SELECT PartyID, DepartmentID, PartyTypeID, ActivityID, 1, SYSDATETIME(), UserID_InsertFirst
FROM dbo.CrmParties
WHERE DepartmentID IS NOT NULL OR PartyTypeID IS NOT NULL OR ActivityID IS NOT NULL
GO

