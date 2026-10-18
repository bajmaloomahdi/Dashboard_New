/* ==========================================================================
   پچ خودکار شماره: 101 | نام: crm_unique_names
   تاریخ: 2026-10-18 09:47:11 | شامل 3 دستور SQL
   ========================================================================== */

-- [CREATE_FUNCTION] روی FUNCTION: fn_Crm_NormalizeName
-- کلیدِ مقایسهٔ نام برایِ جلوگیری از نامِ تکراری: ي/ى→ی، ك→ک، ارقامِ فارسی/عربی→لاتین، حذفِ فاصله/نیم‌فاصله/تب، حروفِ کوچک
CREATE FUNCTION dbo.fn_Crm_NormalizeName (@Value NVARCHAR(400))
RETURNS NVARCHAR(400)
WITH SCHEMABINDING
AS
BEGIN
    IF @Value IS NULL RETURN NULL;
    DECLARE @v NVARCHAR(400) = @Value;
    SET @v = TRANSLATE(@v, NCHAR(1610) + NCHAR(1609) + NCHAR(1603), NCHAR(1740) + NCHAR(1740) + NCHAR(1705));
    SET @v = TRANSLATE(@v, N'۰۱۲۳۴۵۶۷۸۹', N'0123456789');
    SET @v = TRANSLATE(@v, N'٠١٢٣٤٥٦٧٨٩', N'0123456789');
    SET @v = REPLACE(REPLACE(REPLACE(REPLACE(@v, NCHAR(8204), N''), NCHAR(160), N''), NCHAR(9), N''), N' ', N'');
    RETURN LOWER(@v);
END
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

    -- نامِ طرف‌حساب یکتا (بینِ همهٔ طرف‌حساب‌ها، فعال و غیرفعال؛ پس از یکسان‌سازی)
    DECLARE @DupParty NVARCHAR(200), @DupPartyActive BIT;
    SELECT TOP 1 @DupParty = OfficialName, @DupPartyActive = IsActive
    FROM dbo.CrmParties
    WHERE (@PartyID IS NULL OR PartyID <> @PartyID)
      AND dbo.fn_Crm_NormalizeName(OfficialName) = dbo.fn_Crm_NormalizeName(@OfficialName)
    ORDER BY IsActive DESC, PartyID;
    IF @DupParty IS NOT NULL
    BEGIN
        SELECT 0 AS Success, N'طرف‌حسابی با نامِ «' + @DupParty + N'» از قبل ثبت شده است' + CASE WHEN @DupPartyActive = 0 THEN N' (غیرفعال)' ELSE N'' END + N'؛ نامِ تکراری مجاز نیست.' AS Message;
        RETURN;
    END

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

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SavePerson
ALTER PROCEDURE dbo.sp_Crm_SavePerson
    @PersonID         INT           = NULL,
    @FirstName        NVARCHAR(100) = NULL,
    @LastName         NVARCHAR(100),
    @TitleID          INT           = NULL,
    @IdentifierNumber NVARCHAR(20)  = NULL,
    @IdentifierDate   DATE          = NULL,
    @Description      NVARCHAR(MAX) = NULL,
    @UserID           INT
AS
BEGIN
    SET NOCOUNT ON;

    -- نام اختیاری است (خالی ذخیره می‌شود)؛ نامِ خانوادگی و عنوان الزامی‌اند.
    SET @FirstName = ISNULL(LTRIM(RTRIM(@FirstName)), N'');
    SET @LastName = LTRIM(RTRIM(@LastName));

    IF NULLIF(@LastName, N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ خانوادگی الزامی است.' AS Message; RETURN; END
    IF @TitleID IS NULL
    BEGIN SELECT 0 AS Success, N'عنوان الزامی است.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.CrmTitles WHERE TitleID = @TitleID)
    BEGIN SELECT 0 AS Success, N'عنوانِ انتخاب‌شده معتبر نیست.' AS Message; RETURN; END

    -- نام و نامِ خانوادگیِ مخاطب یکتا (بینِ همهٔ مخاطبین، فعال و غیرفعال؛ پس از یکسان‌سازی؛ عنوان در مقایسه نیست)
    DECLARE @DupPerson NVARCHAR(220), @DupPersonActive BIT;
    SELECT TOP 1 @DupPerson = LTRIM(FirstName + N' ' + LastName), @DupPersonActive = IsActive
    FROM dbo.CrmPersons
    WHERE (@PersonID IS NULL OR PersonID <> @PersonID)
      AND dbo.fn_Crm_NormalizeName(FirstName + N' ' + LastName) = dbo.fn_Crm_NormalizeName(@FirstName + N' ' + @LastName)
    ORDER BY IsActive DESC, PersonID;
    IF @DupPerson IS NOT NULL
    BEGIN
        SELECT 0 AS Success, N'مخاطبی با نامِ «' + @DupPerson + N'» از قبل ثبت شده است' + CASE WHEN @DupPersonActive = 0 THEN N' (غیرفعال)' ELSE N'' END + N'؛ نامِ تکراری مجاز نیست.' AS Message;
        RETURN;
    END

    IF @PersonID IS NULL
    BEGIN
        INSERT INTO dbo.CrmPersons (FirstName, LastName, TitleID, IdentifierNumber, IdentifierDate, Description, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@FirstName, @LastName, @TitleID, @IdentifierNumber, @IdentifierDate, @Description, 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'شخص ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS PersonID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmPersons WHERE PersonID = @PersonID)
        BEGIN SELECT 0 AS Success, N'شخص یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmPersons
        SET FirstName = @FirstName, LastName = @LastName, TitleID = @TitleID,
            IdentifierNumber = @IdentifierNumber, IdentifierDate = @IdentifierDate, Description = @Description,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE PersonID = @PersonID;

        SELECT 1 AS Success, N'شخص ویرایش شد.' AS Message, @PersonID AS PersonID;
    END
END
GO

