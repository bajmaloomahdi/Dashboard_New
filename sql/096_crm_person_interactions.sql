/* ==========================================================================
   پچ خودکار شماره: 096 | نام: crm_person_interactions
   تاریخ: 2026-10-17 13:37:06 | شامل 7 دستور SQL
   ========================================================================== */

-- [DROP_INDEX] روی INDEX: IX_CrmInteractions_Party_Date
DROP INDEX IX_CrmInteractions_Party_Date ON dbo.CrmInteractions
GO

-- [ALTER_TABLE] روی TABLE: CrmInteractions
ALTER TABLE dbo.CrmInteractions ALTER COLUMN PartyID INT NULL
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmInteractions_Party_Date
CREATE NONCLUSTERED INDEX IX_CrmInteractions_Party_Date ON dbo.CrmInteractions (PartyID ASC, InteractionDate DESC)
GO

-- [ALTER_TABLE] روی TABLE: CrmInteractions
ALTER TABLE dbo.CrmInteractions WITH CHECK ADD CONSTRAINT CK_CrmInteractions_Owner CHECK (PartyID IS NOT NULL OR PersonID IS NOT NULL)
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_SaveInteraction
-- 3) sp_Crm_SaveInteraction: accept @ProjectID (optional) + Server-side validation against ProjectContractors
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
    BEGIN SELECT 0 AS Success, N'طرف‌حساب یا مخاطبِ تعامل باید مشخص باشد.' AS Message; RETURN; END
    IF @PartyID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حسابِ مربوطه یافت نشد.' AS Message; RETURN; END
    IF @PartyID IS NULL AND NOT EXISTS (SELECT 1 FROM dbo.CrmPersons WHERE PersonID = @PersonID)
    BEGIN SELECT 0 AS Success, N'مخاطبِ مربوطه یافت نشد.' AS Message; RETURN; END
    IF @PartyID IS NULL AND @ProjectID IS NOT NULL
    BEGIN SELECT 0 AS Success, N'ارتباط با پروژه فقط برایِ تعاملِ یک طرف‌حساب ممکن است.' AS Message; RETURN; END
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

    IF @PartyID IS NOT NULL AND @PersonID IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM dbo.CrmPartyPersonRelations WHERE PartyID = @PartyID AND PersonID = @PersonID AND IsActive = 1)
    BEGIN SELECT 0 AS Success, N'مخاطبِ انتخاب‌شده با این طرف‌حساب رابطهٔ فعال ندارد.' AS Message; RETURN; END

    IF @FollowUpOfID IS NOT NULL
    BEGIN
        IF @TypeCode <> N'FOLLOWUP'
        BEGIN SELECT 0 AS Success, N'فقط «پیگیری» می‌تواند به تعاملِ دیگری ارجاع داشته باشد.' AS Message; RETURN; END
        IF @FollowUpOfID = ISNULL(@InteractionID, 0)
        BEGIN SELECT 0 AS Success, N'یک تعامل نمی‌تواند پیگیریِ خودش باشد.' AS Message; RETURN; END
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmInteractions WHERE InteractionID = @FollowUpOfID
                         AND ((@PartyID IS NOT NULL AND PartyID = @PartyID)
                              OR (@PartyID IS NULL AND PartyID IS NULL AND PersonID = @PersonID)))
        BEGIN SELECT 0 AS Success, N'تعاملِ مبدأِ پیگیری در همین طرف‌حساب/مخاطب یافت نشد.' AS Message; RETURN; END
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
        BEGIN SELECT 0 AS Success, N'مخاطبِ تعاملی که بدونِ طرف‌حساب ثبت شده، پس از ثبت قابلِ‌تغییر نیست.' AS Message; RETURN; END

        UPDATE dbo.CrmInteractions
        SET PersonID = @PersonID, ProjectID = @ProjectID, Subject = @Subject, Description = @Description, Outcome = @Outcome,
            InteractionDate = @InteractionDate, FollowUpOfID = @FollowUpOfID, OwnerUserID = @OwnerUserID,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE InteractionID = @InteractionID;

        SELECT 1 AS Success, N'تعامل ویرایش شد.' AS Message, @InteractionID AS InteractionID;
    END
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetInteractions
-- Round 2: CreatedByName در Interaction/PartyImages + Description مستقل برای هر تصویر + ویرایش Description

-- 1) sp_Crm_GetInteractions: افزودنِ CreatedByName (کاربرِ ثبت‌کنندهٔ رکورد؛ UserID_InsertFirst — نه Owner/مسئول)
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
           LTRIM(per.FirstName + N' ' + per.LastName) AS PersonName,
           (u.FirstName + N' ' + u.LastName)     AS OwnerName,
           (cu.FirstName + N' ' + cu.LastName)   AS CreatedByName,
           p.OfficialName                        AS PartyName,
           fo.Subject                            AS FollowUpOfSubject
    FROM dbo.CrmInteractions i
    LEFT JOIN dbo.CrmParties p ON p.PartyID = i.PartyID -- تعاملِ مستقیمِ مخاطب طرف‌حساب ندارد
    JOIN dbo.CrmInteractionTypes it ON it.InteractionTypeID = i.InteractionTypeID
    LEFT JOIN dbo.CrmPersons per ON per.PersonID = i.PersonID
    LEFT JOIN dbo.Users u ON u.UserID = i.OwnerUserID
    LEFT JOIN dbo.Users cu ON cu.UserID = i.UserID_InsertFirst
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

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Gs_Search
ALTER PROCEDURE dbo.sp_Gs_Search
    @UserID    INT,
    @Group     NVARCHAR(20),
    @Query     NVARCHAR(100),        -- normalized by PersianNormalizer (not escaped)
    @QueryLike NVARCHAR(400),        -- normalized + LIKE-escaped
    @T1 NVARCHAR(400) = NULL, @T2 NVARCHAR(400) = NULL, @T3 NVARCHAR(400) = NULL,
    @T4 NVARCHAR(400) = NULL, @T5 NVARCHAR(400) = NULL, @T6 NVARCHAR(400) = NULL,   -- LIKE-escaped tokens (AND)
    @Digits    NVARCHAR(50) = NULL,  -- digits-only query (>= 4 digits) for phone matching
    @Top       INT = 5
AS
BEGIN
    SET NOCOUNT ON;
    SET LOCK_TIMEOUT 2000;

    -- PRIVACY (audit finding 2): the search text must not be kept as a sniffed ParameterCompiledValue
    -- in the plan cache or Query Store. Parameters are copied once into local variables (plain scalar
    -- assignments), only the locals are used below, and the search statement is compiled with
    -- OPTIMIZE FOR UNKNOWN so that not even a statement-level recompile sniffs their values.
    DECLARE @vUserID INT = @UserID, @vGroup NVARCHAR(20) = @Group,
            @vQuery NVARCHAR(100) = @Query, @vQueryLike NVARCHAR(400) = @QueryLike,
            @vT1 NVARCHAR(400) = @T1, @vT2 NVARCHAR(400) = @T2, @vT3 NVARCHAR(400) = @T3,
            @vT4 NVARCHAR(400) = @T4, @vT5 NVARCHAR(400) = @T5, @vT6 NVARCHAR(400) = @T6,
            @vDigits NVARCHAR(50) = @Digits, @vTop INT = @Top;
    DECLARE @vValid BIT = CASE WHEN @vQuery IS NOT NULL AND LEN(@vQuery) >= 1 AND @vQueryLike IS NOT NULL THEN 1 ELSE 0 END;

    IF @vTop IS NULL OR @vTop < 1 SET @vTop = 1;
    IF @vTop > 50 SET @vTop = 50;

    DECLARE @allowed BIT = 0;

    IF @vValid = 1 AND @vGroup = N'crm'
       AND EXISTS (SELECT 1 FROM dbo.Users u WHERE u.UserID = @vUserID AND u.IsActive = 1)
       AND EXISTS (SELECT 1
                   FROM dbo.RolePermissions rp
                   JOIN dbo.Permissions p ON p.PermissionID = rp.PermissionID
                   JOIN dbo.UserRoles ur  ON ur.RoleID = rp.RoleID
                   JOIN dbo.Roles r       ON r.RoleID = ur.RoleID
                   WHERE ur.UserID = @vUserID AND p.PermissionCode = N'CRM_VIEW'
                     AND p.IsActive = 1 AND rp.IsActive = 1 AND rp.CanAccess = 1 AND ur.IsActive = 1 AND r.IsActive = 1)
        SET @allowed = 1;

    ;WITH cand AS (
        /* ---- parties ---- */
        SELECT N'party' AS ItemType, p.PartyID AS ItemID,
               COALESCE(NULLIF(p.OfficialName, N''), p.TradeName, N'') AS Title,
               p.TradeName AS Sub1, p.PartyNature AS Sub2,
               N'party' AS TargetType, p.PartyID AS TargetID,
               CAST(CASE WHEN p.IsActive = 0 THEN 1 ELSE 0 END AS BIT) AS IsInactive,
               COALESCE(p.Date_LastUpdate, p.Date_InsertFirst) AS SortDate,
               dbo.fn_Gs_Normalize(p.OfficialName) AS N1, dbo.fn_Gs_Normalize(p.TradeName) AS N2,
               dbo.fn_Gs_Normalize(p.IdentifierNumber) AS C1, dbo.fn_Gs_Normalize(p.RegistrationNumber) AS C2,
               dbo.fn_Gs_Normalize(p.EconomicCode) AS C3, CAST(NULL AS NVARCHAR(400)) AS Dg, 1 AS TypeOrder
        FROM dbo.CrmParties p
        WHERE @allowed = 1
        UNION ALL
        /* ---- persons ---- */
        SELECT N'person', pe.PersonID, LTRIM(RTRIM(pe.FirstName + N' ' + pe.LastName)), NULL, NULL,
               N'person', pe.PersonID, CAST(CASE WHEN pe.IsActive = 0 THEN 1 ELSE 0 END AS BIT),
               COALESCE(pe.Date_LastUpdate, pe.Date_InsertFirst),
               dbo.fn_Gs_Normalize(pe.FirstName + N' ' + pe.LastName), NULL,
               dbo.fn_Gs_Normalize(pe.IdentifierNumber), NULL, NULL, NULL, 2
        FROM dbo.CrmPersons pe
        WHERE @allowed = 1
        UNION ALL
        /* ---- brands ---- */
        SELECT N'brand', b.BrandID, b.Name, NULL, NULL,
               N'brand', b.BrandID, CAST(CASE WHEN b.IsActive = 0 THEN 1 ELSE 0 END AS BIT),
               COALESCE(b.Date_LastUpdate, b.Date_InsertFirst),
               dbo.fn_Gs_Normalize(b.Name), NULL, NULL, NULL, NULL, NULL, 3
        FROM dbo.CrmBrands b
        WHERE @allowed = 1
        UNION ALL
        /* ---- addresses (title = owner). v1.2: besides the address text and postal code, the names of the
                province / county / city / municipal zone / neighbourhood the address points to are searchable
                (N2) and shown in the subtitle. Same rows and same CRM_VIEW gate as before - no new visibility. ---- */
        SELECT N'address', a.AddressID,
               COALESCE(NULLIF(op.OfficialName, N''), op.TradeName, LTRIM(RTRIM(ope.FirstName + N' ' + ope.LastName)), N''),
               at.DisplayName,
               LEFT(CONCAT_WS(NCHAR(0x060C) + N' ',
                    CASE WHEN gp.DisplayName <> ISNULL(gc.DisplayName, N'') THEN NULLIF(gp.DisplayName, N'') END,
                    CASE WHEN gk.DisplayName <> ISNULL(gc.DisplayName, N'') THEN NULLIF(gk.DisplayName, N'') END,
                    NULLIF(gc.DisplayName, N''),
                    NULLIF(a.AddressText, N'')), 90),
               CASE WHEN a.PartyID IS NOT NULL THEN N'party' ELSE N'person' END, COALESCE(a.PartyID, a.PersonID),
               CAST(CASE WHEN a.IsActive = 0 OR op.IsActive = 0 OR ope.IsActive = 0 THEN 1 ELSE 0 END AS BIT),
               COALESCE(a.Date_LastUpdate, a.Date_InsertFirst),
               dbo.fn_Gs_Normalize(a.AddressText),
               dbo.fn_Gs_Normalize(CONCAT_WS(N' ', gp.DisplayName, gk.DisplayName, gc.DisplayName, gz.DisplayName, gn.DisplayName)),
               dbo.fn_Gs_Normalize(a.PostalCode), NULL, NULL, NULL, 4
        FROM dbo.CrmAddresses a
        LEFT JOIN dbo.CrmParties op ON op.PartyID = a.PartyID
        LEFT JOIN dbo.CrmPersons ope ON ope.PersonID = a.PersonID
        LEFT JOIN dbo.CrmAddressTitles at ON at.AddressTitleID = a.AddressTitleID
        LEFT JOIN dbo.CrmProvinces gp ON gp.ProvinceID = a.ProvinceID
        LEFT JOIN dbo.CrmCounties gk ON gk.CountyID = a.CountyID
        LEFT JOIN dbo.CrmCities gc ON gc.CityID = a.CityID
        LEFT JOIN dbo.CrmMunicipalZones gz ON gz.MunicipalZoneID = a.MunicipalZoneID
        LEFT JOIN dbo.CrmNeighborhoods gn ON gn.NeighborhoodID = a.NeighborhoodID
        WHERE @allowed = 1
        UNION ALL
        /* ---- contacts (title = owner) ---- */
        SELECT N'contact', c.ContactID,
               COALESCE(NULLIF(op.OfficialName, N''), op.TradeName, LTRIM(RTRIM(ope.FirstName + N' ' + ope.LastName)), N''),
               ct.DisplayName, c.ContactValue,
               CASE WHEN c.PartyID IS NOT NULL THEN N'party' ELSE N'person' END, COALESCE(c.PartyID, c.PersonID),
               CAST(CASE WHEN c.IsActive = 0 OR op.IsActive = 0 OR ope.IsActive = 0 THEN 1 ELSE 0 END AS BIT),
               COALESCE(c.Date_LastUpdate, c.Date_InsertFirst),
               dbo.fn_Gs_Normalize(c.ContactValue), NULL, dbo.fn_Gs_Normalize(c.ContactValue), NULL, NULL,
               REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(dbo.fn_Gs_Normalize(c.ContactValue),
                   N' ', N''), N'-', N''), N'(', N''), N')', N''), N'.', N''), N'+', N''), N'/', N''), 5
        FROM dbo.CrmContacts c
        LEFT JOIN dbo.CrmParties op ON op.PartyID = c.PartyID
        LEFT JOIN dbo.CrmPersons ope ON ope.PersonID = c.PersonID
        LEFT JOIN dbo.CrmContactTypes ct ON ct.ContactTypeID = c.ContactTypeID
        WHERE @allowed = 1
        UNION ALL
        /* ---- interactions (target = party, or the person when it has no party; project data deliberately NOT read) ---- */
        SELECT N'interaction', i.InteractionID, i.Subject,
               COALESCE(NULLIF(op.OfficialName, N''), op.TradeName, LTRIM(RTRIM(ope.FirstName + N' ' + ope.LastName)), N''), CONVERT(NVARCHAR(10), i.InteractionDate, 120),
               CASE WHEN i.PartyID IS NOT NULL THEN N'party' ELSE N'person' END, COALESCE(i.PartyID, i.PersonID),
               CAST(CASE WHEN i.IsActive = 0 OR op.IsActive = 0 OR ope.IsActive = 0 THEN 1 ELSE 0 END AS BIT),
               i.InteractionDate,
               dbo.fn_Gs_Normalize(i.Subject), NULL, NULL, NULL, NULL, NULL, 6
        FROM dbo.CrmInteractions i
        LEFT JOIN dbo.CrmParties op ON op.PartyID = i.PartyID
        LEFT JOIN dbo.CrmPersons ope ON i.PartyID IS NULL AND ope.PersonID = i.PersonID
        WHERE @allowed = 1
    ),
    hay AS (
        SELECT cand.*,
               ISNULL(N1, N'') + N' ' + ISNULL(N2, N'') + N' ' + ISNULL(C1, N'') + N' ' + ISNULL(C2, N'') + N' ' + ISNULL(C3, N'') AS H
        FROM cand
    ),
    matched AS (
        SELECT hay.*,
               CASE
                   WHEN C1 = @vQuery OR C2 = @vQuery OR C3 = @vQuery
                        OR (@vDigits IS NOT NULL AND Dg = @vDigits)                                     THEN 1000
                   WHEN N1 = @vQuery OR N2 = @vQuery                                                      THEN 500
                   WHEN N1 LIKE @vQueryLike + N'%' OR N2 LIKE @vQueryLike + N'%'                          THEN 400
                   WHEN N1 LIKE N'% ' + @vQueryLike + N'%' OR N2 LIKE N'% ' + @vQueryLike + N'%'          THEN 300
                   WHEN N1 LIKE N'%' + @vQueryLike + N'%' OR N2 LIKE N'%' + @vQueryLike + N'%'            THEN 200
                   ELSE 100
               END AS Score
        FROM hay
        WHERE (
                  (@vT1 IS NULL OR H LIKE N'%' + @vT1 + N'%')
              AND (@vT2 IS NULL OR H LIKE N'%' + @vT2 + N'%')
              AND (@vT3 IS NULL OR H LIKE N'%' + @vT3 + N'%')
              AND (@vT4 IS NULL OR H LIKE N'%' + @vT4 + N'%')
              AND (@vT5 IS NULL OR H LIKE N'%' + @vT5 + N'%')
              AND (@vT6 IS NULL OR H LIKE N'%' + @vT6 + N'%')
              AND (@vT1 IS NOT NULL OR H LIKE N'%' + @vQueryLike + N'%')
              )
           OR (@vDigits IS NOT NULL AND Dg LIKE N'%' + @vDigits + N'%')
    )
    SELECT TOP (@vTop + 1)
           ItemType, ItemID, Title, Sub1, Sub2, TargetType, TargetID, IsInactive, Score
    FROM matched
    ORDER BY Score DESC, IsInactive ASC, TypeOrder ASC, SortDate DESC, LEN(Title) ASC, ItemID ASC
    OPTION (OPTIMIZE FOR UNKNOWN);
END
GO

