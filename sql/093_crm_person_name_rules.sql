/* ==========================================================================
   پچ خودکار شماره: 093 | نام: crm_person_name_rules
   تاریخ: 2026-10-17 10:00:31 | شامل 6 دستور SQL
   ========================================================================== */

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

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetPersons
/* ===================== sp_Crm_GetPersons (+ Description) ===================== */

ALTER PROCEDURE dbo.sp_Crm_GetPersons
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT per.PersonID, per.FirstName, per.LastName, per.TitleID, per.IdentifierNumber, per.IdentifierDate,
           per.Description, per.IsActive,
           LTRIM(per.FirstName + N' ' + per.LastName) AS DisplayName,
           t.DisplayName AS TitleName,
           per.Date_InsertFirst, per.UserID_InsertFirst, per.Date_LastUpdate, per.UserID_LastUpdate
    FROM dbo.CrmPersons per
    LEFT JOIN dbo.CrmTitles t ON t.TitleID = per.TitleID
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR per.FirstName LIKE N'%' + @SearchText + N'%'
           OR per.LastName LIKE N'%' + @SearchText + N'%'
           OR LTRIM(per.FirstName + N' ' + per.LastName) LIKE N'%' + @SearchText + N'%'
           OR per.IdentifierNumber LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR per.IsActive = @IsActive)
    ORDER BY per.PersonID DESC;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetPerson
ALTER PROCEDURE dbo.sp_Crm_GetPerson
    @PersonID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT per.PersonID, per.FirstName, per.LastName, per.TitleID, per.IdentifierNumber, per.IdentifierDate,
           per.Description, per.IsActive, per.RowGuid,
           LTRIM(per.FirstName + N' ' + per.LastName) AS DisplayName,
           t.DisplayName AS TitleName,
           per.Date_InsertFirst, per.UserID_InsertFirst, per.Date_LastUpdate, per.UserID_LastUpdate,
           cu.FirstName + N' ' + cu.LastName AS CreatedByName,
           mu.FirstName + N' ' + mu.LastName AS ModifiedByName
    FROM dbo.CrmPersons per
    LEFT JOIN dbo.CrmTitles t ON t.TitleID = per.TitleID
    LEFT JOIN dbo.Users cu ON cu.UserID = per.UserID_InsertFirst
    LEFT JOIN dbo.Users mu ON mu.UserID = per.UserID_LastUpdate
    WHERE per.PersonID = @PersonID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetPartyRelations
ALTER PROCEDURE dbo.sp_Crm_GetPartyRelations
    @PartyID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT r.RelationID, r.PartyID, r.PersonID, r.PositionID, r.IsPrimaryContact, r.IsActive,
           r.Date_InsertFirst, r.UserID_InsertFirst, r.Date_LastUpdate, r.UserID_LastUpdate,
           per.FirstName AS PersonFirstName, per.LastName AS PersonLastName,
           LTRIM(per.FirstName + N' ' + per.LastName) AS PersonName,
           (
               SELECT STRING_AGG(pos.DisplayName, N'، ') WITHIN GROUP (ORDER BY CASE WHEN pos.SortOrder > 0 THEN pos.SortOrder ELSE CAST(pos.Code AS INT) END, CAST(pos.Code AS INT), pos.PositionID)
               FROM dbo.CrmPartyPersonRelationPositions rp
               JOIN dbo.CrmPositions pos ON pos.PositionID = rp.PositionID
               WHERE rp.RelationID = r.RelationID
           ) AS PositionName,
           (
               SELECT STRING_AGG(cr.DisplayName, N'، ') WITHIN GROUP (ORDER BY CASE WHEN cr.SortOrder > 0 THEN cr.SortOrder ELSE CAST(cr.Code AS INT) END, CAST(cr.Code AS INT), cr.ContactRoleID)
               FROM dbo.CrmPartyPersonRelationRoles rr
               JOIN dbo.CrmContactRoles cr ON cr.ContactRoleID = rr.RoleID
               WHERE rr.RelationID = r.RelationID
           ) AS RoleNames
    FROM dbo.CrmPartyPersonRelations r
    JOIN dbo.CrmPersons per ON per.PersonID = r.PersonID
    WHERE r.PartyID = @PartyID
    ORDER BY r.IsPrimaryContact DESC, r.RelationID;
END
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
           LTRIM(rp.FirstName + N' ' + rp.LastName) AS RelatedPersonName
    FROM dbo.CrmContacts c
    JOIN dbo.CrmContactTypes ct ON ct.ContactTypeID = c.ContactTypeID
    LEFT JOIN dbo.CrmPersons rp ON rp.PersonID = c.RelatedPersonID
    WHERE (@PartyID IS NOT NULL AND c.PartyID = @PartyID)
       OR (@PersonID IS NOT NULL AND c.PersonID = @PersonID)
    ORDER BY c.IsPrimary DESC, c.ContactID;
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
    JOIN dbo.CrmParties p ON p.PartyID = i.PartyID
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

