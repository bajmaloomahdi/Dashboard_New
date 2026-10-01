/* ==========================================================================
   پچ خودکار شماره: 076 | نام: crm_interaction_images_round2
   تاریخ: 2026-10-01 19:31:12 | شامل 5 دستور SQL
   ========================================================================== */

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
           (per.FirstName + N' ' + per.LastName) AS PersonName,
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

-- [ALTER_TABLE] روی TABLE: CrmPartyImages
ALTER TABLE dbo.CrmPartyImages ADD Description NVARCHAR(500) NULL
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_GetPartyImages
-- 3) sp_Crm_GetPartyImages: افزودنِ Description + CreatedByName
ALTER PROCEDURE dbo.sp_Crm_GetPartyImages
    @PartyID      INT = NULL,
    @PartyImageID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT img.PartyImageID, img.PartyID, img.ImagePath, img.ImageMimeType, img.Description, img.SortOrder, img.IsActive,
           img.Date_InsertFirst, img.UserID_InsertFirst,
           (cu.FirstName + N' ' + cu.LastName) AS CreatedByName
    FROM dbo.CrmPartyImages img
    LEFT JOIN dbo.Users cu ON cu.UserID = img.UserID_InsertFirst
    WHERE img.IsActive = 1
      AND (@PartyID      IS NULL OR img.PartyID      = @PartyID)
      AND (@PartyImageID IS NULL OR img.PartyImageID = @PartyImageID)
    ORDER BY img.SortOrder, img.PartyImageID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_AddPartyImage
-- 4) sp_Crm_AddPartyImage: پذیرشِ @Description اختیاری
ALTER PROCEDURE dbo.sp_Crm_AddPartyImage
    @PartyID       INT,
    @ImagePath     NVARCHAR(500),
    @ImageMimeType NVARCHAR(50),
    @Description   NVARCHAR(500) = NULL,
    @SortOrder     INT = NULL,
    @UserID        INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حساب یافت نشد.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@ImagePath)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'مسیرِ تصویر نامعتبر است.' AS Message; RETURN; END

    IF @SortOrder IS NULL
        SELECT @SortOrder = ISNULL(MAX(SortOrder), 0) + 1 FROM dbo.CrmPartyImages WHERE PartyID = @PartyID AND IsActive = 1;

    INSERT INTO dbo.CrmPartyImages (PartyID, ImagePath, ImageMimeType, Description, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
    VALUES (@PartyID, @ImagePath, @ImageMimeType, NULLIF(LTRIM(RTRIM(@Description)), N''), @SortOrder, 1, SYSDATETIME(), @UserID);

    SELECT 1 AS Success, N'تصویر ثبت شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS PartyImageID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_UpdatePartyImageDescription
-- 5) sp_Crm_UpdatePartyImageDescription: SPِ کوچک و تک‌منظوره، فقط برایِ ویرایشِ توضیحِ یک تصویر
CREATE PROCEDURE dbo.sp_Crm_UpdatePartyImageDescription
    @PartyImageID INT,
    @Description  NVARCHAR(500) = NULL,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmPartyImages WHERE PartyImageID = @PartyImageID AND IsActive = 1)
    BEGIN SELECT 0 AS Success, N'تصویر یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmPartyImages
    SET Description = NULLIF(LTRIM(RTRIM(@Description)), N''),
        Date_LastUpdate = SYSDATETIME(),
        UserID_LastUpdate = @UserID
    WHERE PartyImageID = @PartyImageID;

    SELECT 1 AS Success, N'توضیحاتِ تصویر به‌روزرسانی شد.' AS Message;
END
GO

