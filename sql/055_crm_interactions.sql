/* ==========================================================================
   پچ خودکار شماره: 055 | نام: crm_interactions
   تاریخ: 2026-09-25 09:32:22 | شامل 7 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: CrmInteractions
CREATE TABLE dbo.CrmInteractions (
    InteractionID      INT              IDENTITY(1,1) NOT NULL,
    RowGuid            UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmInteractions_RowGuid DEFAULT (NEWID()),
    InteractionType    NVARCHAR(20)     NOT NULL,
    PartyID            INT              NOT NULL,
    PersonID           INT              NULL,
    Subject            NVARCHAR(200)    NOT NULL,
    Description        NVARCHAR(MAX)    NULL,
    Outcome            NVARCHAR(MAX)    NULL,
    InteractionDate    DATETIME2(0)     NOT NULL,
    Status             NVARCHAR(20)     NOT NULL,
    FollowUpOfID       INT              NULL,
    OwnerUserID        INT              NULL,
    IsActive           BIT              NOT NULL CONSTRAINT DF_CrmInteractions_IsActive DEFAULT (1),
    Date_InsertFirst   DATETIME2        NOT NULL CONSTRAINT DF_CrmInteractions_Insert DEFAULT SYSDATETIME(),
    UserID_InsertFirst INT              NULL,
    Date_LastUpdate    DATETIME2        NULL,
    UserID_LastUpdate  INT              NULL,
    CONSTRAINT PK_CrmInteractions PRIMARY KEY (InteractionID),
    CONSTRAINT CK_CrmInteractions_Type   CHECK (InteractionType IN (N'CALL', N'MEETING', N'NOTE', N'FOLLOWUP')),
    CONSTRAINT CK_CrmInteractions_Status CHECK (Status IN (N'PLANNED', N'DONE', N'CANCELED')),
    CONSTRAINT FK_CrmInteractions_Party    FOREIGN KEY (PartyID)      REFERENCES dbo.CrmParties(PartyID),
    CONSTRAINT FK_CrmInteractions_Person   FOREIGN KEY (PersonID)     REFERENCES dbo.CrmPersons(PersonID),
    CONSTRAINT FK_CrmInteractions_FollowUp FOREIGN KEY (FollowUpOfID) REFERENCES dbo.CrmInteractions(InteractionID),
    CONSTRAINT FK_CrmInteractions_Owner    FOREIGN KEY (OwnerUserID)  REFERENCES dbo.Users(UserID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmInteractions_Party_Date
CREATE INDEX IX_CrmInteractions_Party_Date ON dbo.CrmInteractions(PartyID, InteractionDate DESC)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmInteractions_PersonID
CREATE INDEX IX_CrmInteractions_PersonID ON dbo.CrmInteractions(PersonID)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetInteractions
CREATE PROCEDURE dbo.sp_Crm_GetInteractions
    @PartyID  INT           = NULL,
    @PersonID INT           = NULL,
    @Type     NVARCHAR(20)  = NULL,
    @Status   NVARCHAR(20)  = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT i.InteractionID, i.InteractionType, i.PartyID, i.PersonID, i.Subject, i.Description, i.Outcome,
           i.InteractionDate, i.Status, i.FollowUpOfID, i.OwnerUserID, i.IsActive,
           i.Date_InsertFirst, i.UserID_InsertFirst, i.Date_LastUpdate, i.UserID_LastUpdate,
           (per.FirstName + N' ' + per.LastName) AS PersonName,
           (u.FirstName + N' ' + u.LastName)     AS OwnerName,
           p.OfficialName                        AS PartyName,
           fo.Subject                            AS FollowUpOfSubject
    FROM dbo.CrmInteractions i
    JOIN dbo.CrmParties p ON p.PartyID = i.PartyID
    LEFT JOIN dbo.CrmPersons per ON per.PersonID = i.PersonID
    LEFT JOIN dbo.Users u ON u.UserID = i.OwnerUserID
    LEFT JOIN dbo.CrmInteractions fo ON fo.InteractionID = i.FollowUpOfID
    WHERE (@PartyID  IS NULL OR i.PartyID  = @PartyID)
      AND (@PersonID IS NULL OR i.PersonID = @PersonID)
      AND (@Type     IS NULL OR i.InteractionType = @Type)
      AND (@Status   IS NULL OR i.Status = @Status)
    ORDER BY i.InteractionDate DESC, i.InteractionID DESC;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SaveInteraction
CREATE PROCEDURE dbo.sp_Crm_SaveInteraction
    @InteractionID   INT            = NULL,
    @InteractionType NVARCHAR(20),
    @PartyID         INT,
    @PersonID        INT            = NULL,
    @Subject         NVARCHAR(200),
    @Description     NVARCHAR(MAX)  = NULL,
    @Outcome         NVARCHAR(MAX)  = NULL,
    @InteractionDate DATETIME2(0),
    @Status          NVARCHAR(20)   = NULL,
    @FollowUpOfID    INT            = NULL,
    @OwnerUserID     INT            = NULL,
    @UserID          INT
AS
BEGIN
    SET NOCOUNT ON;

    IF @InteractionType IS NULL OR @InteractionType NOT IN (N'CALL', N'MEETING', N'NOTE', N'FOLLOWUP')
    BEGIN SELECT 0 AS Success, N'نوعِ تعامل نامعتبر است.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حسابِ مربوطه یافت نشد.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@Subject)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'موضوع الزامی است.' AS Message; RETURN; END
    IF @InteractionDate IS NULL
    BEGIN SELECT 0 AS Success, N'تاریخ و ساعت الزامی است.' AS Message; RETURN; END

    IF @PersonID IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM dbo.CrmPartyPersonRelations WHERE PartyID = @PartyID AND PersonID = @PersonID AND IsActive = 1)
    BEGIN SELECT 0 AS Success, N'مخاطبِ انتخاب‌شده با این طرف‌حساب رابطهٔ فعال ندارد.' AS Message; RETURN; END

    IF @FollowUpOfID IS NOT NULL
    BEGIN
        IF @InteractionType <> N'FOLLOWUP'
        BEGIN SELECT 0 AS Success, N'فقط «پیگیری» می‌تواند به تعاملِ دیگری ارجاع داشته باشد.' AS Message; RETURN; END
        IF @FollowUpOfID = ISNULL(@InteractionID, 0)
        BEGIN SELECT 0 AS Success, N'یک تعامل نمی‌تواند پیگیریِ خودش باشد.' AS Message; RETURN; END
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmInteractions WHERE InteractionID = @FollowUpOfID AND PartyID = @PartyID)
        BEGIN SELECT 0 AS Success, N'تعاملِ مبدأِ پیگیری در همین طرف‌حساب یافت نشد.' AS Message; RETURN; END
    END

    SET @OwnerUserID = ISNULL(@OwnerUserID, @UserID);
    IF @OwnerUserID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @OwnerUserID)
    BEGIN SELECT 0 AS Success, N'مسئولِ انتخاب‌شده معتبر نیست.' AS Message; RETURN; END

    IF @InteractionID IS NULL
    BEGIN
        -- قواعدِ وضعیت هنگامِ ایجاد
        IF @InteractionType = N'NOTE'
        BEGIN
            IF @Status IS NOT NULL AND @Status <> N'DONE'
            BEGIN SELECT 0 AS Success, N'یادداشت همیشه «انجام‌شده» ثبت می‌شود.' AS Message; RETURN; END
            SET @Status = N'DONE';
        END
        ELSE IF @InteractionType = N'FOLLOWUP'
        BEGIN
            IF @Status IS NOT NULL AND @Status <> N'PLANNED'
            BEGIN SELECT 0 AS Success, N'پیگیری هنگامِ ثبت باید «برنامه‌ریزی‌شده» باشد.' AS Message; RETURN; END
            SET @Status = N'PLANNED';
        END
        ELSE
        BEGIN
            SET @Status = ISNULL(@Status, N'DONE');
            IF @Status NOT IN (N'PLANNED', N'DONE')
            BEGIN SELECT 0 AS Success, N'تماس/جلسه هنگامِ ثبت فقط می‌تواند «برنامه‌ریزی‌شده» یا «انجام‌شده» باشد.' AS Message; RETURN; END
        END

        INSERT INTO dbo.CrmInteractions (InteractionType, PartyID, PersonID, Subject, Description, Outcome, InteractionDate,
                                         Status, FollowUpOfID, OwnerUserID, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@InteractionType, @PartyID, @PersonID, @Subject, @Description, @Outcome, @InteractionDate,
                @Status, @FollowUpOfID, @OwnerUserID, 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'تعامل ثبت شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS InteractionID;
    END
    ELSE
    BEGIN
        DECLARE @CurType NVARCHAR(20), @CurParty INT;
        SELECT @CurType = InteractionType, @CurParty = PartyID FROM dbo.CrmInteractions WHERE InteractionID = @InteractionID;
        IF @CurType IS NULL
        BEGIN SELECT 0 AS Success, N'تعامل یافت نشد.' AS Message; RETURN; END
        IF @CurType <> @InteractionType OR @CurParty <> @PartyID
        BEGIN SELECT 0 AS Success, N'نوع و طرف‌حسابِ تعامل پس از ثبت قابلِ‌تغییر نیست.' AS Message; RETURN; END

        -- وضعیت فقط از طریقِ sp_Crm_SetInteractionStatus تغییر می‌کند
        UPDATE dbo.CrmInteractions
        SET PersonID = @PersonID, Subject = @Subject, Description = @Description, Outcome = @Outcome,
            InteractionDate = @InteractionDate, FollowUpOfID = @FollowUpOfID, OwnerUserID = @OwnerUserID,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE InteractionID = @InteractionID;

        SELECT 1 AS Success, N'تعامل ویرایش شد.' AS Message, @InteractionID AS InteractionID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SetInteractionStatus
CREATE PROCEDURE dbo.sp_Crm_SetInteractionStatus
    @InteractionID INT,
    @Status        NVARCHAR(20),
    @UserID        INT
AS
BEGIN
    SET NOCOUNT ON;

    IF @Status IS NULL OR @Status NOT IN (N'DONE', N'CANCELED')
    BEGIN SELECT 0 AS Success, N'وضعیتِ مقصد فقط می‌تواند «انجام‌شده» یا «لغوشده» باشد.' AS Message; RETURN; END

    DECLARE @Type NVARCHAR(20), @Cur NVARCHAR(20);
    SELECT @Type = InteractionType, @Cur = Status FROM dbo.CrmInteractions WHERE InteractionID = @InteractionID;

    IF @Type IS NULL
    BEGIN SELECT 0 AS Success, N'تعامل یافت نشد.' AS Message; RETURN; END
    IF @Type = N'NOTE'
    BEGIN SELECT 0 AS Success, N'وضعیتِ یادداشت قابلِ‌تغییر نیست.' AS Message; RETURN; END
    IF @Cur <> N'PLANNED'
    BEGIN SELECT 0 AS Success, N'فقط تعاملِ «برنامه‌ریزی‌شده» قابلِ‌تغییرِ وضعیت است.' AS Message; RETURN; END

    UPDATE dbo.CrmInteractions
    SET Status = @Status, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE InteractionID = @InteractionID;

    SELECT 1 AS Success,
           CASE WHEN @Status = N'DONE' THEN N'تعامل «انجام‌شده» شد.' ELSE N'تعامل لغو شد.' END AS Message;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_ToggleInteractionActive
CREATE PROCEDURE dbo.sp_Crm_ToggleInteractionActive
    @InteractionID INT,
    @UserID        INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmInteractions WHERE InteractionID = @InteractionID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'تعامل یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmInteractions
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE InteractionID = @InteractionID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'تعامل غیرفعال شد.' ELSE N'تعامل فعال شد.' END AS Message;
END
GO

