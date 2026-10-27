/* ==========================================================================
   پچ خودکار شماره: 123 | نام: crm_individual_national_code
   تاریخ: 2026-10-27 15:37:56 | شامل 1 دستور SQL
   ========================================================================== */

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
    @UserID             INT,
    @AllowDuplicateName BIT           = 0, -- پچِ ۱۱۴: ۱ = کاربر هشدارِ نامِ تکراری را دیده و ثبت را تأیید کرده است
    -- پچِ ۱۱۹: قطعِ همکاری — NULL = بدونِ تغییر، ۱ = قطعِ همکاری در @CooperationEndedAt، ۰ = برقراریِ دوبارهٔ همکاری
    @CooperationEnded   BIT           = NULL,
    @CooperationEndedAt DATETIME2(0)  = NULL
AS
BEGIN
    SET NOCOUNT ON;

    IF @CooperationEnded = 1 AND @CooperationEndedAt IS NULL
    BEGIN SELECT 0 AS Success, N'تاریخ و ساعتِ قطعِ همکاری الزامی است.' AS Message; RETURN; END

    IF @PartyNature NOT IN (N'INDIVIDUAL', N'LEGAL')
    BEGIN SELECT 0 AS Success, N'ماهیتِ طرف‌حساب نامعتبر است.' AS Message; RETURN; END

    IF NULLIF(LTRIM(RTRIM(@OfficialName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نام الزامی است.' AS Message; RETURN; END

    IF @PartyNature = N'LEGAL' AND NULLIF(LTRIM(RTRIM(@IdentifierNumber)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'شناسهٔ ملی الزامی است.' AS Message; RETURN; END

    -- پچِ ۱۲۳: حقیقی هم می‌تواند کدِ ملی (اختیاری، ۱۰ رقم — بررسی در سرویس) در همین ستون داشته باشد
    SET @IdentifierNumber = NULLIF(LTRIM(RTRIM(@IdentifierNumber)), N'');

    IF @ActivityID IS NOT NULL AND @PartyTypeID IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM dbo.CrmActivities WHERE ActivityID = @ActivityID AND PartyTypeID = @PartyTypeID)
    BEGIN SELECT 0 AS Success, N'فعالیتِ انتخاب‌شده متعلق به نوعِ انتخاب‌شده نیست.' AS Message; RETURN; END

    IF @IdentifierNumber IS NOT NULL AND EXISTS (
        SELECT 1 FROM dbo.CrmParties
        WHERE IdentifierNumber = @IdentifierNumber
          AND (@PartyID IS NULL OR PartyID <> @PartyID)
    )
    BEGIN SELECT 0 AS Success, N'طرف‌حسابی با این شناسه از قبل ثبت شده است.' AS Message; RETURN; END

    -- نامِ تکراری (بینِ همهٔ طرف‌حساب‌ها، فعال و غیرفعال؛ پس از یکسان‌سازی): بدونِ تأییدِ کاربر ثبت نمی‌شود و
    -- هشدار + تعداد و تا ۵ موردِ مشابه برمی‌گردد؛ با @AllowDuplicateName = 1 ثبت/ویرایش انجام می‌شود (پچِ ۱۱۴)
    DECLARE @DupParty NVARCHAR(200), @DupPartyActive BIT;
    SELECT TOP 1 @DupParty = OfficialName, @DupPartyActive = IsActive
    FROM dbo.CrmParties
    WHERE (@PartyID IS NULL OR PartyID <> @PartyID)
      AND dbo.fn_Crm_NormalizeName(OfficialName) = dbo.fn_Crm_NormalizeName(@OfficialName)
    ORDER BY IsActive DESC, PartyID;
    IF @DupParty IS NOT NULL AND ISNULL(@AllowDuplicateName, 0) = 0
       /* ویرایش بدونِ تغییرِ نام (مثلاً رکوردی که قبلاً با تأیید ثبت شده) دوباره هشدار نمی‌دهد */
       AND (@PartyID IS NULL OR NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID AND dbo.fn_Crm_NormalizeName(OfficialName) = dbo.fn_Crm_NormalizeName(@OfficialName)))
    BEGIN
        SELECT 0 AS Success, N'DUPLICATE_NAME' AS ErrorCode,
               N'طرف‌حسابی با نامِ «' + @DupParty + N'» از قبل ثبت شده است' + CASE WHEN @DupPartyActive = 0 THEN N' (غیرفعال)' ELSE N'' END + N'. آیا با همین نام ثبت شود؟' AS Message,
               (SELECT COUNT(*) FROM dbo.CrmParties WHERE (@PartyID IS NULL OR PartyID <> @PartyID) AND dbo.fn_Crm_NormalizeName(OfficialName) = dbo.fn_Crm_NormalizeName(@OfficialName)) AS DuplicateCount,
               (SELECT TOP 5 PartyID AS id, OfficialName AS name, CAST(IsActive AS BIT) AS isActive FROM dbo.CrmParties WHERE (@PartyID IS NULL OR PartyID <> @PartyID) AND dbo.fn_Crm_NormalizeName(OfficialName) = dbo.fn_Crm_NormalizeName(@OfficialName) ORDER BY IsActive DESC, PartyID FOR JSON PATH) AS Duplicates;
        RETURN;
    END

    IF @PartyID IS NULL
    BEGIN
        INSERT INTO dbo.CrmParties (
            PartyNature, OfficialName, TradeName, RegistrationNumber, EconomicCode, IdentifierNumber, IdentifierDate,
            Description, DepartmentID, PartyTypeID, ActivityID, IsActive,
            Date_InsertFirst, UserID_InsertFirst, CooperationEndedAt
        )
        VALUES (
            @PartyNature, @OfficialName, @TradeName, @RegistrationNumber, @EconomicCode, @IdentifierNumber, @IdentifierDate,
            @Description, @DepartmentID, @PartyTypeID, @ActivityID, 1,
            SYSDATETIME(), @UserID, CASE WHEN @CooperationEnded = 1 THEN @CooperationEndedAt END
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
            CooperationEndedAt = CASE WHEN @CooperationEnded IS NULL THEN CooperationEndedAt WHEN @CooperationEnded = 1 THEN @CooperationEndedAt END,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE PartyID = @PartyID;

        SELECT 1 AS Success, N'طرف‌حساب ویرایش شد.' AS Message, @PartyID AS PartyID;
    END
END
GO

