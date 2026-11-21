SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO
ALTER PROCEDURE dbo.sp_GetProjectsForParty @PartyID int
AS
BEGIN
    SET NOCOUNT ON;
    SELECT p.ProjectID, p.ProjectTitle, p.ProjectCode
    FROM dbo.Projects p
    WHERE p.OwnerPartyID = @PartyID
       OR EXISTS (SELECT 1 FROM dbo.ProjectContractors pc
                 WHERE pc.ProjectID = p.ProjectID AND pc.PartyID = @PartyID AND pc.IsActive = 1)
    ORDER BY p.ProjectTitle;
END
GO
ALTER PROCEDURE dbo.sp_Crm_SaveInteraction
    @InteractionID      INT            = NULL,
    @InteractionTypeID  INT,
    @PartyID             INT            = NULL,
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
    -- مالکِ تعامل: طرف‌حساب (با مخاطبِ اختیاری از مخاطبینِ مرتبطش) یا مستقیماً یک مخاطب (بدونِ طرف‌حساب)
    IF @PartyID IS NULL AND @PersonID IS NULL
    BEGIN SELECT 0 AS Success, N'طرف‌حساب یا فردِ مرتبطِ تعامل باید مشخص باشد.' AS Message; RETURN; END
    IF @PartyID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حسابِ مربوطه یافت نشد.' AS Message; RETURN; END
    IF @PartyID IS NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmPersons WHERE PersonID = @PersonID)
    BEGIN SELECT 0 AS Success, N'فردِ مرتبطِ مربوطه یافت نشد.' AS Message; RETURN; END
    IF @PartyID IS NULL AND @ProjectID IS NOT NULL
    BEGIN SELECT 0 AS Success, N'ارتباط با پروژه فقط برایِ تعاملِ یک طرف‌حساب ممکن است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@Subject)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'موضوع الزامی است.' AS Message; RETURN; END
    IF @InteractionDate IS NULL
    BEGIN SELECT 0 AS Success, N'تاریخ و ساعت الزامی است.' AS Message; RETURN; END

    -- ارتباطِ اختیاریِ Project: اگر ارسال شد، Backend مستقل از Frontend بررسی می‌کند که این
    -- طرف‌حساب مالک یا پیمانکار فعال پروژه باشد (دفاع در برابرِ دستکاریِ Request).
    IF @ProjectID IS NOT NULL
    BEGIN
        IF NOT EXISTS (
            SELECT 1 FROM dbo.Projects WHERE ProjectID = @ProjectID AND OwnerPartyID = @PartyID)
        AND NOT EXISTS (
            SELECT 1 FROM dbo.ProjectContractors
            WHERE ProjectID = @ProjectID AND PartyID = @PartyID AND IsActive = 1)
        BEGIN SELECT 0 AS Success, N'این طرف‌حساب مالک یا پیمانکار فعال پروژهٔ انتخاب‌شده نیست.' AS Message; RETURN; END
    END

    IF @PartyID IS NOT NULL AND @PersonID IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM dbo.CrmPartyPersonRelations WHERE PartyID = @PartyID AND PersonID = @PersonID AND IsActive = 1)
    BEGIN SELECT 0 AS Success, N'فردِ مرتبطِ انتخاب‌شده با این طرف‌حساب رابطهٔ فعال ندارد.' AS Message; RETURN; END

    IF @FollowUpOfID IS NOT NULL
    BEGIN
        IF @TypeCode <> N'FOLLOWUP'
        BEGIN SELECT 0 AS Success, N'فقط «پیگیری» می‌تواند به تعاملِ دیگری ارجاع داشته باشد.' AS Message; RETURN; END
        IF @FollowUpOfID = ISNULL(@InteractionID, 0)
        BEGIN SELECT 0 AS Success, N'یک تعامل نمی‌تواند پیگیریِ خودش باشد.' AS Message; RETURN; END
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmInteractions WHERE InteractionID = @FollowUpOfID
                         AND ((@PartyID IS NOT NULL AND PartyID = @PartyID)
                              OR (@PartyID IS NULL AND PartyID IS NULL AND PersonID = @PersonID)))
        BEGIN SELECT 0 AS Success, N'تعاملِ مبدأِ پیگیری در همین طرف‌حساب/فرد مرتبط یافت نشد.' AS Message; RETURN; END
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
        DECLARE @CurTypeID INT, @CurParty INT, @CurPerson INT;
        SELECT @CurTypeID = InteractionTypeID, @CurParty = PartyID, @CurPerson = PersonID FROM dbo.CrmInteractions WHERE InteractionID = @InteractionID;
        IF @CurTypeID IS NULL
        BEGIN SELECT 0 AS Success, N'تعامل یافت نشد.' AS Message; RETURN; END
        IF @CurTypeID <> @InteractionTypeID OR ISNULL(@CurParty, 0) <> ISNULL(@PartyID, 0)
        BEGIN SELECT 0 AS Success, N'نوع و طرف‌حسابِ تعامل پس از ثبت قابلِ‌تغییر نیست.' AS Message; RETURN; END
        IF @CurParty IS NULL AND ISNULL(@CurPerson, 0) <> ISNULL(@PersonID, 0)
        BEGIN SELECT 0 AS Success, N'فردِ مرتبطِ تعاملی که بدونِ طرف‌حساب ثبت شده، پس از ثبت قابلِ‌تغییر نیست.' AS Message; RETURN; END

        UPDATE dbo.CrmInteractions
        SET PersonID = @PersonID, ProjectID = @ProjectID, Subject = @Subject, Description = @Description, Outcome = @Outcome,
            InteractionDate = @InteractionDate, FollowUpOfID = @FollowUpOfID, OwnerUserID = @OwnerUserID,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE InteractionID = @InteractionID;

        SELECT 1 AS Success, N'تعامل ویرایش شد.' AS Message, @InteractionID AS InteractionID;
    END
END
GO
