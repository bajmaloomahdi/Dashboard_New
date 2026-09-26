/* ==========================================================================
   پچ خودکار شماره: 054 | نام: crm_contact_related_person
   تاریخ: 2026-09-24 22:05:11 | شامل 4 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: CrmContacts
ALTER TABLE dbo.CrmContacts ADD RelatedPersonID INT NULL
GO

-- [ALTER_TABLE] روی TABLE: CrmContacts
ALTER TABLE dbo.CrmContacts ADD CONSTRAINT FK_CrmContacts_RelatedPerson FOREIGN KEY (RelatedPersonID) REFERENCES dbo.CrmPersons(PersonID)
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetContacts
ALTER PROCEDURE dbo.sp_Crm_GetContacts
    @PartyID  INT = NULL,
    @PersonID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT c.ContactID, c.PartyID, c.PersonID, c.ContactTypeID, c.ContactValue, c.Extension, c.Description,
           c.IsPrimary, c.IsActive, c.RelatedPersonID,
           c.Date_InsertFirst, c.UserID_InsertFirst, c.Date_LastUpdate, c.UserID_LastUpdate,
           ct.DisplayName AS ContactTypeName,
           (rp.FirstName + N' ' + rp.LastName) AS RelatedPersonName
    FROM dbo.CrmContacts c
    JOIN dbo.CrmContactTypes ct ON ct.ContactTypeID = c.ContactTypeID
    LEFT JOIN dbo.CrmPersons rp ON rp.PersonID = c.RelatedPersonID
    WHERE (@PartyID IS NOT NULL AND c.PartyID = @PartyID)
       OR (@PersonID IS NOT NULL AND c.PersonID = @PersonID)
    ORDER BY c.IsPrimary DESC, c.ContactID;
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

