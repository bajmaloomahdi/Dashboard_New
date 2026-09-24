/* ==========================================================================
   پچ خودکار شماره: 049 | نام: crm_party_optional_identifier_for_individual
   تاریخ: 2026-09-24 16:54:56 | شامل 4 دستور SQL

   نکته: ساختِ ایندکسِ یکتایِ فیلترشده (زیر) و هر SP یِ بعدی که رویِ CrmParties
   INSERT/UPDATE/DELETE می‌کند نیاز به QUOTED_IDENTIFIER=ON دارد — وگرنه SQL
   Server با خطا ردّ می‌کند. رجوع کنید به sql/050 برایِ اصلاحِ همین مشکل روی
   یک SP قدیمی‌ترِ همین جدول (sp_Crm_TogglePartyActive) که بدونِ این تنظیم
   ساخته شده بود.
   ========================================================================== */

SET QUOTED_IDENTIFIER ON
GO

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties DROP CONSTRAINT UQ_CrmParties_Identifier
GO

-- [ALTER_TABLE] روی TABLE: CrmParties
ALTER TABLE dbo.CrmParties ALTER COLUMN IdentifierNumber NVARCHAR(20) NULL
GO

-- [CREATE_INDEX] روی INDEX: UQ_CrmParties_Identifier
CREATE UNIQUE NONCLUSTERED INDEX UQ_CrmParties_Identifier ON dbo.CrmParties (IdentifierNumber) WHERE IdentifierNumber IS NOT NULL
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveParty
ALTER PROCEDURE dbo.sp_Crm_SaveParty
    @PartyID            INT           = NULL,
    @PartyNature        NVARCHAR(20),
    @OfficialName       NVARCHAR(200),
    @TradeName          NVARCHAR(200) = NULL,
    @RegistrationNumber NVARCHAR(50)  = NULL,
    @EconomicCode       NVARCHAR(50)  = NULL,
    @IdentifierNumber   NVARCHAR(20)  = NULL,
    @IdentifierDate     DATE          = NULL,
    @Description        NVARCHAR(MAX) = NULL,
    @DepartmentID       INT           = NULL,
    @PartyTypeID        INT           = NULL,
    @ActivityID         INT           = NULL,
    @UserID             INT
AS
BEGIN
    SET NOCOUNT ON;

    IF @PartyNature NOT IN (N'INDIVIDUAL', N'LEGAL')
    BEGIN SELECT 0 AS Success, N'ماهیتِ طرف‌حساب نامعتبر است.' AS Message; RETURN; END

    IF NULLIF(LTRIM(RTRIM(@OfficialName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نام الزامی است.' AS Message; RETURN; END

    IF @PartyNature = N'LEGAL' AND NULLIF(LTRIM(RTRIM(@IdentifierNumber)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'شناسهٔ ملی الزامی است.' AS Message; RETURN; END

    IF @PartyNature = N'INDIVIDUAL' SET @IdentifierNumber = NULL;

    IF @ActivityID IS NOT NULL AND @PartyTypeID IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM dbo.CrmActivities WHERE ActivityID = @ActivityID AND PartyTypeID = @PartyTypeID)
    BEGIN SELECT 0 AS Success, N'فعالیتِ انتخاب‌شده متعلق به نوعِ انتخاب‌شده نیست.' AS Message; RETURN; END

    IF @IdentifierNumber IS NOT NULL AND EXISTS (
        SELECT 1 FROM dbo.CrmParties
        WHERE IdentifierNumber = @IdentifierNumber
          AND (@PartyID IS NULL OR PartyID <> @PartyID)
    )
    BEGIN SELECT 0 AS Success, N'طرف‌حسابی با این شناسه از قبل ثبت شده است.' AS Message; RETURN; END

    IF @PartyID IS NULL
    BEGIN
        INSERT INTO dbo.CrmParties (
            PartyNature, OfficialName, TradeName, RegistrationNumber, EconomicCode, IdentifierNumber, IdentifierDate,
            Description, DepartmentID, PartyTypeID, ActivityID, IsActive,
            Date_InsertFirst, UserID_InsertFirst
        )
        VALUES (
            @PartyNature, @OfficialName, @TradeName, @RegistrationNumber, @EconomicCode, @IdentifierNumber, @IdentifierDate,
            @Description, @DepartmentID, @PartyTypeID, @ActivityID, 1,
            SYSDATETIME(), @UserID
        );

        SELECT 1 AS Success, N'طرف‌حساب ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS PartyID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
        BEGIN SELECT 0 AS Success, N'طرف‌حساب یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmParties
        SET PartyNature = @PartyNature, OfficialName = @OfficialName, TradeName = @TradeName, RegistrationNumber = @RegistrationNumber,
            EconomicCode = @EconomicCode, IdentifierNumber = @IdentifierNumber, IdentifierDate = @IdentifierDate,
            Description = @Description, DepartmentID = @DepartmentID, PartyTypeID = @PartyTypeID, ActivityID = @ActivityID,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE PartyID = @PartyID;

        SELECT 1 AS Success, N'طرف‌حساب ویرایش شد.' AS Message, @PartyID AS PartyID;
    END
END
GO

