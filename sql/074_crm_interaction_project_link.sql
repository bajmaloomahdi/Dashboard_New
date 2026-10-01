/* ==========================================================================
   پچ خودکار شماره: 074 | نام: crm_interaction_project_link
   تاریخ: 2026-10-01 12:31:15 | شامل 6 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: CrmInteractions
ALTER TABLE dbo.CrmInteractions ADD ProjectID BIGINT NULL
GO

-- [ALTER_TABLE] روی TABLE: CrmInteractions
ALTER TABLE dbo.CrmInteractions ADD CONSTRAINT FK_CrmInteractions_Project FOREIGN KEY (ProjectID) REFERENCES dbo.Projects(ProjectID)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmInteractions_ProjectID
CREATE INDEX IX_CrmInteractions_ProjectID ON dbo.CrmInteractions(ProjectID)
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetInteractions
-- 2) sp_Crm_GetInteractions: add @ProjectID filter + joined ProjectTitle (no extra Frontend queries needed)
ALTER PROCEDURE dbo.sp_Crm_GetInteractions
    @PartyID            INT           = NULL,
    @PersonID           INT           = NULL,
    @Type                NVARCHAR(20)  = NULL,
    @InteractionTypeID  INT           = NULL,
    @Status              NVARCHAR(20)  = NULL,
    @ProjectID           BIGINT        = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT i.InteractionID, i.InteractionType, i.InteractionTypeID, it.DisplayName AS InteractionTypeDisplayName,
           i.PartyID, i.PersonID, i.ProjectID, proj.ProjectTitle AS ProjectTitle,
           i.Subject, i.Description, i.Outcome,
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
    LEFT JOIN dbo.Projects proj ON proj.ProjectID = i.ProjectID
    WHERE (@PartyID  IS NULL OR i.PartyID  = @PartyID)
      AND (@PersonID IS NULL OR i.PersonID = @PersonID)
      AND (@Type     IS NULL OR i.InteractionType = @Type)
      AND (@InteractionTypeID IS NULL OR i.InteractionTypeID = @InteractionTypeID)
      AND (@Status   IS NULL OR i.Status = @Status)
      AND (@ProjectID IS NULL OR i.ProjectID = @ProjectID)
    ORDER BY i.InteractionDate DESC, i.InteractionID DESC;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveInteraction
-- 3) sp_Crm_SaveInteraction: accept @ProjectID (optional) + Server-side validation against ProjectContractors
ALTER PROCEDURE dbo.sp_Crm_SaveInteraction
    @InteractionID      INT            = NULL,
    @InteractionTypeID  INT,
    @PartyID             INT,
    @PersonID            INT            = NULL,
    @ProjectID            BIGINT         = NULL,
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

    -- ارتباطِ اختیاریِ Project: اگر ارسال شد، Backend مستقل از Frontend بررسی می‌کند که این
    -- Party واقعاً پیمانکارِ فعالِ همین Project باشد (دفاع در برابرِ دستکاریِ Request).
    IF @ProjectID IS NOT NULL
    BEGIN
        IF NOT EXISTS (
            SELECT 1 FROM dbo.ProjectContractors
            WHERE ProjectID = @ProjectID AND PartyID = @PartyID AND IsActive = 1)
        BEGIN SELECT 0 AS Success, N'این طرف‌حساب، پیمانکارِ فعالِ پروژهٔ انتخاب‌شده نیست.' AS Message; RETURN; END
    END

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

        INSERT INTO dbo.CrmInteractions (InteractionType, InteractionTypeID, PartyID, PersonID, ProjectID, Subject, Description, Outcome, InteractionDate,
                                         Status, FollowUpOfID, OwnerUserID, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@TypeCode, @InteractionTypeID, @PartyID, @PersonID, @ProjectID, @Subject, @Description, @Outcome, @InteractionDate,
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

        UPDATE dbo.CrmInteractions
        SET PersonID = @PersonID, ProjectID = @ProjectID, Subject = @Subject, Description = @Description, Outcome = @Outcome,
            InteractionDate = @InteractionDate, FollowUpOfID = @FollowUpOfID, OwnerUserID = @OwnerUserID,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE InteractionID = @InteractionID;

        SELECT 1 AS Success, N'تعامل ویرایش شد.' AS Message, @InteractionID AS InteractionID;
    END
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetProjectsForParty
-- 4) sp_GetProjectsForParty: projects where this Party is an active Contractor (used later in Stage C/D for the Project selector)
CREATE PROCEDURE dbo.sp_GetProjectsForParty
    @PartyID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT p.ProjectID, p.ProjectTitle, p.ProjectCode
    FROM dbo.ProjectContractors pc
    JOIN dbo.Projects p ON p.ProjectID = pc.ProjectID
    WHERE pc.PartyID = @PartyID AND pc.IsActive = 1
    ORDER BY p.ProjectTitle;
END
GO

