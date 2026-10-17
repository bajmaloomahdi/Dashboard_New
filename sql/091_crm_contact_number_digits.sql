/* ==========================================================================
   پچ خودکار شماره: 091 | نام: crm_contact_number_digits
   تاریخ: 2026-10-17 09:48:20 | شامل 1 دستور SQL
   ========================================================================== */

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveContact
ALTER PROCEDURE dbo.sp_Crm_SaveContact
    @ContactID       INT           = NULL,
    @PartyID         INT           = NULL,
    @PersonID        INT           = NULL,
    @ContactTypeID   INT,
    @ContactValue    NVARCHAR(200),
    @Extension       NVARCHAR(20)  = NULL,
    @Description     NVARCHAR(200) = NULL,
    @IsPrimary       BIT           = 0,
    @RelatedPersonID INT           = NULL,
    @UserID          INT
AS
BEGIN
    SET NOCOUNT ON;

    IF (@PartyID IS NULL AND @PersonID IS NULL) OR (@PartyID IS NOT NULL AND @PersonID IS NOT NULL)
    BEGIN SELECT 0 AS Success, N'دقیقاً یکی از طرف‌حساب یا مخاطب باید مشخص باشد.' AS Message; RETURN; END
    IF @PartyID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حساب انتخاب‌شده معتبر نیست.' AS Message; RETURN; END
    IF @PersonID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmPersons WHERE PersonID = @PersonID)
    BEGIN SELECT 0 AS Success, N'مخاطبِ انتخاب‌شده معتبر نیست.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.CrmContactTypes WHERE ContactTypeID = @ContactTypeID)
    BEGIN SELECT 0 AS Success, N'نوعِ تماس معتبر نیست.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@ContactValue)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'مقدارِ تماس الزامی است.' AS Message; RETURN; END
    IF @RelatedPersonID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmPersons WHERE PersonID = @RelatedPersonID)
    BEGIN SELECT 0 AS Success, N'مخاطبِ مرتبط‌شده معتبر نیست.' AS Message; RETURN; END

    -- کنترلِ تعدادِ ارقامِ شماره‌ها پس از یکسان‌سازی (ارقامِ فارسی → لاتین، حذفِ فاصله/خط‌تیره/پرانتز، +98/0098 → 0):
    -- موبایل = ۱۱ رقم و شروع با ۰۹؛ تلفن/فکس = ۱۱ رقم با پیش‌شماره (شروع با ۰) یا ۸ رقم بدونِ پیش‌شماره.
    DECLARE @TypeName NVARCHAR(100) = (SELECT LTRIM(RTRIM(DisplayName)) FROM dbo.CrmContactTypes WHERE ContactTypeID = @ContactTypeID);
    IF @TypeName IN (N'موبایل', N'تلفن', N'فکس')
    BEGIN
        DECLARE @Digits NVARCHAR(200) = dbo.fn_Crm_NormalizeMobile(@ContactValue);
        IF @Digits = N'' OR @Digits LIKE N'%[^0-9]%'
        BEGIN SELECT 0 AS Success, N'شمارهٔ ' + @TypeName + N' فقط می‌تواند شاملِ رقم باشد (فاصله، خط‌تیره و +98 مجاز است).' AS Message; RETURN; END
        IF @TypeName = N'موبایل' AND NOT (LEN(@Digits) = 11 AND LEFT(@Digits, 2) = N'09')
        BEGIN SELECT 0 AS Success, N'شمارهٔ موبایل باید ۱۱ رقم باشد و با ۰۹ شروع شود (مثلاً 09121234567).' AS Message; RETURN; END
        IF @TypeName IN (N'تلفن', N'فکس') AND NOT ((LEN(@Digits) = 11 AND LEFT(@Digits, 1) = N'0') OR (LEN(@Digits) = 8 AND LEFT(@Digits, 1) <> N'0'))
        BEGIN SELECT 0 AS Success, N'شمارهٔ ' + @TypeName + N' باید ۱۱ رقم با پیش‌شماره (مثلاً 02112345678) یا ۸ رقم بدونِ پیش‌شماره باشد.' AS Message; RETURN; END
    END
    BEGIN TRY
        BEGIN TRAN;

        -- شمارهٔ موبایل یکتا: هیچ اطلاعاتِ تماسِ «موبایل»ِ فعالِ دیگری (برایِ هر طرف‌حساب یا مخاطبی) همین شماره را
        -- نداشته باشد. applock دو ثبتِ هم‌زمانِ یک شماره را پشتِ‌سرِهم می‌کند.
        IF EXISTS (SELECT 1 FROM dbo.CrmContactTypes WHERE ContactTypeID = @ContactTypeID AND LTRIM(RTRIM(DisplayName)) = N'موبایل')
        BEGIN
            EXEC sp_getapplock @Resource = N'CrmMobileUnique', @LockMode = N'Exclusive', @LockOwner = N'Transaction', @LockTimeout = 10000;

            DECLARE @Mobile NVARCHAR(200) = dbo.fn_Crm_NormalizeMobile(@ContactValue);
            DECLARE @DupOwner NVARCHAR(400) = (
                SELECT TOP 1 COALESCE(p.OfficialName, LTRIM(RTRIM(ISNULL(pe.FirstName, N'') + N' ' + ISNULL(pe.LastName, N''))))
                FROM dbo.CrmContacts c
                JOIN dbo.CrmContactTypes t ON t.ContactTypeID = c.ContactTypeID AND LTRIM(RTRIM(t.DisplayName)) = N'موبایل'
                LEFT JOIN dbo.CrmParties p ON p.PartyID = c.PartyID
                LEFT JOIN dbo.CrmPersons pe ON pe.PersonID = c.PersonID
                WHERE c.IsActive = 1
                  AND (@ContactID IS NULL OR c.ContactID <> @ContactID)
                  AND dbo.fn_Crm_NormalizeMobile(c.ContactValue) = @Mobile
                ORDER BY c.ContactID
            );
            IF @DupOwner IS NOT NULL
            BEGIN
                COMMIT TRAN; -- هنوز چیزی نوشته نشده؛ ROLLBACK تراکنشِ بیرونیِ فراخوان را هم از بین می‌برد
                SELECT 0 AS Success, N'این شمارهٔ موبایل قبلاً برایِ «' + @DupOwner + N'» ثبت شده است؛ شمارهٔ موبایلِ تکراری مجاز نیست.' AS Message;
                RETURN;
            END
        END

        IF @ContactID IS NULL
        BEGIN
            INSERT INTO dbo.CrmContacts (PartyID, PersonID, ContactTypeID, ContactValue, Extension, Description, IsPrimary, RelatedPersonID, IsActive, Date_InsertFirst, UserID_InsertFirst)
            VALUES (@PartyID, @PersonID, @ContactTypeID, @ContactValue, @Extension, @Description, ISNULL(@IsPrimary, 0), @RelatedPersonID, 1, SYSDATETIME(), @UserID);

            SET @ContactID = CAST(SCOPE_IDENTITY() AS INT);
        END
        ELSE
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM dbo.CrmContacts WHERE ContactID = @ContactID)
            BEGIN
                ROLLBACK TRAN;
                SELECT 0 AS Success, N'اطلاعاتِ تماس یافت نشد.' AS Message;
                RETURN;
            END

            UPDATE dbo.CrmContacts
            SET ContactTypeID = @ContactTypeID, ContactValue = @ContactValue, Extension = @Extension,
                Description = @Description, IsPrimary = ISNULL(@IsPrimary, 0), RelatedPersonID = @RelatedPersonID,
                Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
            WHERE ContactID = @ContactID;
        END

        -- تکِ اصلی بودن، به‌ازایِ همان مالک (طرف‌حساب یا مخاطب)
        IF ISNULL(@IsPrimary, 0) = 1
            UPDATE dbo.CrmContacts
            SET IsPrimary = 0
            WHERE ContactID <> @ContactID
              AND ((@PartyID IS NOT NULL AND PartyID = @PartyID) OR (@PersonID IS NOT NULL AND PersonID = @PersonID));

        COMMIT TRAN;
        SELECT 1 AS Success, N'اطلاعاتِ تماس ذخیره شد.' AS Message, @ContactID AS ContactID;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRAN;
        SELECT 0 AS Success, N'خطا در ذخیره: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

