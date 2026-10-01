/* ==========================================================================
   پچ خودکار شماره: 073 | نام: crm_interaction_type_master_data
   تاریخ: 2026-10-01 11:51:36 | شامل 11 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: CrmInteractionTypes
CREATE TABLE dbo.CrmInteractionTypes
(
    InteractionTypeID INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmInteractionTypes PRIMARY KEY,
    Code               NVARCHAR(64)  NOT NULL,
    DisplayName        NVARCHAR(200) NOT NULL,
    SortOrder          INT NOT NULL CONSTRAINT DF_CrmInteractionTypes_SortOrder DEFAULT (0),
    IsActive           BIT NOT NULL CONSTRAINT DF_CrmInteractionTypes_IsActive DEFAULT (1),
    RowGuid            UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmInteractionTypes_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmInteractionTypes_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate    DATETIME2 NULL,
    UserID_LastUpdate  INT NULL,
    CONSTRAINT UQ_CrmInteractionTypes_Code UNIQUE (Code)
)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetInteractionTypes
-- 2) SPs (mirror sp_Crm_Get/Save/ToggleContactType, autocode numeric from 101 like other CRM registries)
CREATE PROCEDURE dbo.sp_Crm_GetInteractionTypes
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT InteractionTypeID, Code, DisplayName, SortOrder, IsActive,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate
    FROM dbo.CrmInteractionTypes t
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR t.DisplayName LIKE N'%' + @SearchText + N'%'
           OR t.Code LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR t.IsActive = @IsActive)
    ORDER BY t.SortOrder, t.InteractionTypeID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SaveInteractionType
CREATE PROCEDURE dbo.sp_Crm_SaveInteractionType
    @InteractionTypeID INT           = NULL,
    @DisplayName        NVARCHAR(200),
    @SortOrder           INT           = 0,
    @UserID              INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نامِ نمایشی الزامی است.' AS Message; RETURN; END

    IF @InteractionTypeID IS NULL
    BEGIN
        DECLARE @NextCode INT;
        SELECT @NextCode = ISNULL(MAX(CAST(Code AS INT)), 100) + 1 FROM dbo.CrmInteractionTypes WHERE ISNUMERIC(Code) = 1;
        IF @NextCode < 101 SET @NextCode = 101;

        INSERT INTO dbo.CrmInteractionTypes (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (CAST(@NextCode AS NVARCHAR(64)), @DisplayName, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'نوعِ تعامل ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS InteractionTypeID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmInteractionTypes WHERE InteractionTypeID = @InteractionTypeID)
        BEGIN SELECT 0 AS Success, N'نوعِ تعامل یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.CrmInteractionTypes
        SET DisplayName = @DisplayName, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE InteractionTypeID = @InteractionTypeID;

        SELECT 1 AS Success, N'نوعِ تعامل ویرایش شد.' AS Message, @InteractionTypeID AS InteractionTypeID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_ToggleInteractionTypeActive
CREATE PROCEDURE dbo.sp_Crm_ToggleInteractionTypeActive
    @InteractionTypeID INT,
    @UserID              INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmInteractionTypes WHERE InteractionTypeID = @InteractionTypeID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'نوعِ تعامل یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmInteractionTypes
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE InteractionTypeID = @InteractionTypeID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'نوعِ تعامل غیرفعال شد.' ELSE N'نوعِ تعامل فعال شد.' END AS Message;
END
GO

/* [DATA SEED] — Idempotent؛ این بخش توسطِ Triggerِ DDL ردیابی نمی‌شود (DML است، نه تغییرِ
   Schema)، پس دستی به پچ اضافه شد — هم‌الگو با patch 038/028. Codeهای زیر عیناً با مقادیرِ
   قدیمیِ Hard-codeِ CrmInteractions.InteractionType یکسان نگه داشته شده‌اند تا Backfillِ
   زیر (که پیش از NOT NULL شدنِ ستون اجرا می‌شود) به‌درستی با Code تطبیق پیدا کند. نوع‌هایِ
   جدیدی که Admin بعداً از صفحهٔ Master Data اضافه کند، Codeِ خودکارِ عددی (۱۰۱ به بعد)
   می‌گیرند — هم‌الگو با سایرِ Registryهایِ CRM. */

-- CrmInteractionTypes defaults (migrated from the old hardcoded enum)
IF NOT EXISTS (SELECT 1 FROM dbo.CrmInteractionTypes WHERE Code = N'CALL')
    INSERT INTO dbo.CrmInteractionTypes (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst) VALUES (N'CALL', N'تماس', 1, 1, SYSDATETIME());
IF NOT EXISTS (SELECT 1 FROM dbo.CrmInteractionTypes WHERE Code = N'MEETING')
    INSERT INTO dbo.CrmInteractionTypes (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst) VALUES (N'MEETING', N'جلسه', 2, 1, SYSDATETIME());
IF NOT EXISTS (SELECT 1 FROM dbo.CrmInteractionTypes WHERE Code = N'NOTE')
    INSERT INTO dbo.CrmInteractionTypes (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst) VALUES (N'NOTE', N'یادداشت', 3, 1, SYSDATETIME());
IF NOT EXISTS (SELECT 1 FROM dbo.CrmInteractionTypes WHERE Code = N'FOLLOWUP')
    INSERT INTO dbo.CrmInteractionTypes (Code, DisplayName, SortOrder, IsActive, Date_InsertFirst) VALUES (N'FOLLOWUP', N'پیگیری', 4, 1, SYSDATETIME());
GO

-- [ALTER_TABLE] روی TABLE: CrmInteractions
ALTER TABLE dbo.CrmInteractions ADD InteractionTypeID INT NULL
GO

/* [DATA BACKFILL] — Idempotent؛ این هم DML است و توسطِ Trigger ردیابی نمی‌شود. باید دقیقاً
   اینجا، بعد از افزودنِ ستون (Nullable) و پیش از NOT NULL شدنِ آن، اجرا شود — وگرنه خطِ
   بعدی (ALTER COLUMN ... NOT NULL) روی هر محیطی که Interactionِ موجود دارد شکست می‌خورد.
   ردیف‌هایِ موجودِ CrmInteractions را بر اساسِ CrmInteractions.InteractionType (Codeِ متنیِ
   قدیمی) به CrmInteractionTypes.InteractionTypeID متصل می‌کند. روی DBِ توسعه این Backfill
   تأیید شد: ۴ از ۴ ردیف، صفر NULLِ باقی‌مانده. */
UPDATE i
SET i.InteractionTypeID = t.InteractionTypeID
FROM dbo.CrmInteractions i
JOIN dbo.CrmInteractionTypes t ON t.Code = i.InteractionType
WHERE i.InteractionTypeID IS NULL;
GO

-- [ALTER_TABLE] روی TABLE: CrmInteractions
ALTER TABLE dbo.CrmInteractions ALTER COLUMN InteractionTypeID INT NOT NULL
GO

-- [ALTER_TABLE] روی TABLE: CrmInteractions
ALTER TABLE dbo.CrmInteractions ADD CONSTRAINT FK_CrmInteractions_InteractionType FOREIGN KEY (InteractionTypeID) REFERENCES dbo.CrmInteractionTypes(InteractionTypeID)
GO

-- [ALTER_TABLE] روی TABLE: CrmInteractions
ALTER TABLE dbo.CrmInteractions DROP CONSTRAINT CK_CrmInteractions_Type
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmInteractions_InteractionTypeID
CREATE INDEX IX_CrmInteractions_InteractionTypeID ON dbo.CrmInteractions(InteractionTypeID)
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetInteractions
-- 7) sp_Crm_GetInteractions: join Master Data for DisplayName, keep legacy InteractionType (Code) for back-compat,
--    accept both @Type (legacy Code filter) and @InteractionTypeID (new ID filter)
ALTER PROCEDURE dbo.sp_Crm_GetInteractions
    @PartyID            INT           = NULL,
    @PersonID           INT           = NULL,
    @Type                NVARCHAR(20)  = NULL,
    @InteractionTypeID  INT           = NULL,
    @Status              NVARCHAR(20)  = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT i.InteractionID, i.InteractionType, i.InteractionTypeID, it.DisplayName AS InteractionTypeDisplayName,
           i.PartyID, i.PersonID, i.Subject, i.Description, i.Outcome,
           i.InteractionDate, i.Status, i.FollowUpOfID, i.OwnerUserID, i.IsActive,
           i.Date_InsertFirst, i.UserID_InsertFirst, i.Date_LastUpdate, i.UserID_LastUpdate,
           (per.FirstName + N' ' + per.LastName) AS PersonName,
           (u.FirstName + N' ' + u.LastName)     AS OwnerName,
           p.OfficialName                        AS PartyName,
           fo.Subject                            AS FollowUpOfSubject
    FROM dbo.CrmInteractions i
    JOIN dbo.CrmParties p ON p.PartyID = i.PartyID
    JOIN dbo.CrmInteractionTypes it ON it.InteractionTypeID = i.InteractionTypeID
    LEFT JOIN dbo.CrmPersons per ON per.PersonID = i.PersonID
    LEFT JOIN dbo.Users u ON u.UserID = i.OwnerUserID
    LEFT JOIN dbo.CrmInteractions fo ON fo.InteractionID = i.FollowUpOfID
    WHERE (@PartyID  IS NULL OR i.PartyID  = @PartyID)
      AND (@PersonID IS NULL OR i.PersonID = @PersonID)
      AND (@Type     IS NULL OR i.InteractionType = @Type)
      AND (@InteractionTypeID IS NULL OR i.InteractionTypeID = @InteractionTypeID)
      AND (@Status   IS NULL OR i.Status = @Status)
    ORDER BY i.InteractionDate DESC, i.InteractionID DESC;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveInteraction
-- 8) sp_Crm_SaveInteraction: validate @InteractionTypeID against Master Data (no more hardcoded IN(...)),
--    mirror the resolved Code into the legacy InteractionType column for back-compat (column kept, not dropped)
ALTER PROCEDURE dbo.sp_Crm_SaveInteraction
    @InteractionID      INT            = NULL,
    @InteractionTypeID  INT,
    @PartyID             INT,
    @PersonID            INT            = NULL,
    @Subject              NVARCHAR(200),
    @Description          NVARCHAR(MAX)  = NULL,
    @Outcome              NVARCHAR(MAX)  = NULL,
    @InteractionDate      DATETIME2(0),
    @Status               NVARCHAR(20)   = NULL,
    @FollowUpOfID         INT            = NULL,
    @OwnerUserID          INT            = NULL,
    @UserID               INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @TypeCode NVARCHAR(64);
    SELECT @TypeCode = Code FROM dbo.CrmInteractionTypes WHERE InteractionTypeID = @InteractionTypeID AND IsActive = 1;
    IF @TypeCode IS NULL
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
        IF @TypeCode <> N'FOLLOWUP'
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
        -- قواعدِ وضعیت هنگامِ ایجاد (بر اساسِ Codeِ Master Data، نه مقدارِ ثابتِ متنی)
        IF @TypeCode = N'NOTE'
        BEGIN
            IF @Status IS NOT NULL AND @Status <> N'DONE'
            BEGIN SELECT 0 AS Success, N'یادداشت همیشه «انجام‌شده» ثبت می‌شود.' AS Message; RETURN; END
            SET @Status = N'DONE';
        END
        ELSE IF @TypeCode = N'FOLLOWUP'
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

        INSERT INTO dbo.CrmInteractions (InteractionType, InteractionTypeID, PartyID, PersonID, Subject, Description, Outcome, InteractionDate,
                                         Status, FollowUpOfID, OwnerUserID, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@TypeCode, @InteractionTypeID, @PartyID, @PersonID, @Subject, @Description, @Outcome, @InteractionDate,
                @Status, @FollowUpOfID, @OwnerUserID, 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'تعامل ثبت شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS InteractionID;
    END
    ELSE
    BEGIN
        DECLARE @CurTypeID INT, @CurParty INT;
        SELECT @CurTypeID = InteractionTypeID, @CurParty = PartyID FROM dbo.CrmInteractions WHERE InteractionID = @InteractionID;
        IF @CurTypeID IS NULL
        BEGIN SELECT 0 AS Success, N'تعامل یافت نشد.' AS Message; RETURN; END
        IF @CurTypeID <> @InteractionTypeID OR @CurParty <> @PartyID
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

