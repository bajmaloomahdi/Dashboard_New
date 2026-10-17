/* ==========================================================================
   پچ خودکار شماره: 090 | نام: crm_unique_mobile
   تاریخ: 2026-10-17 09:41:46 | شامل 3 دستور SQL
   ========================================================================== */

-- [CREATE_FUNCTION] روی FUNCTION: fn_Crm_NormalizeMobile
-- شکلِ یکسانِ شمارهٔ موبایل برایِ مقایسه: ارقامِ فارسی/عربی → لاتین، حذفِ فاصله و جداکننده‌ها،
-- پیش‌شمارهٔ ایران (+98 / 0098 / 98) → 0، و ۹xxxxxxxxx → ۰۹xxxxxxxxx. فقط برایِ مقایسه؛ مقدارِ ذخیره‌شده تغییر نمی‌کند.
CREATE FUNCTION dbo.fn_Crm_NormalizeMobile (@Value NVARCHAR(200))
RETURNS NVARCHAR(200)
WITH SCHEMABINDING
AS
BEGIN
    IF @Value IS NULL RETURN NULL;

    DECLARE @v NVARCHAR(200) = TRANSLATE(@Value, N'۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', N'01234567890123456789');
    SET @v = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(@v, N' ', N''), N'-', N''), N'(', N''), N')', N''), N'.', N''), N'+', N'');
    SET @v = REPLACE(REPLACE(REPLACE(@v, NCHAR(160), N''), NCHAR(8204), N''), NCHAR(9), N'');

    IF LEFT(@v, 4) = N'0098' SET @v = N'0' + SUBSTRING(@v, 5, 200);
    ELSE IF LEFT(@v, 2) = N'98' AND LEN(@v) = 12 SET @v = N'0' + SUBSTRING(@v, 3, 200);
    ELSE IF LEFT(@v, 1) = N'9' AND LEN(@v) = 10 SET @v = N'0' + @v;

    RETURN @v;
END
GO

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

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_ToggleContactActive
ALTER PROCEDURE dbo.sp_Crm_ToggleContactActive
    @ContactID INT,
    @UserID    INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT, @TypeID INT, @Value NVARCHAR(200);
    SELECT @Current = IsActive, @TypeID = ContactTypeID, @Value = ContactValue FROM dbo.CrmContacts WHERE ContactID = @ContactID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'اطلاعاتِ تماس یافت نشد.' AS Message; RETURN; END

    -- فعال‌سازیِ دوبارهٔ یک موبایل فقط وقتی مجاز است که همین شماره اکنون برایِ تماسِ فعالِ دیگری ثبت نباشد
    IF @Current = 0
       AND EXISTS (SELECT 1 FROM dbo.CrmContactTypes WHERE ContactTypeID = @TypeID AND LTRIM(RTRIM(DisplayName)) = N'موبایل')
       AND EXISTS (
            SELECT 1
            FROM dbo.CrmContacts c
            JOIN dbo.CrmContactTypes t ON t.ContactTypeID = c.ContactTypeID AND LTRIM(RTRIM(t.DisplayName)) = N'موبایل'
            WHERE c.IsActive = 1 AND c.ContactID <> @ContactID
              AND dbo.fn_Crm_NormalizeMobile(c.ContactValue) = dbo.fn_Crm_NormalizeMobile(@Value)
       )
    BEGIN SELECT 0 AS Success, N'این شمارهٔ موبایل اکنون برایِ اطلاعاتِ تماسِ فعالِ دیگری ثبت است؛ فعال‌سازیِ دوباره مجاز نیست.' AS Message; RETURN; END

    UPDATE dbo.CrmContacts
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE ContactID = @ContactID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'اطلاعاتِ تماس غیرفعال شد.' ELSE N'اطلاعاتِ تماس فعال شد.' END AS Message;
END
GO

