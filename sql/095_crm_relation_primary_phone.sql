/* ==========================================================================
   پچ خودکار شماره: 095 | نام: crm_relation_primary_phone
   تاریخ: 2026-10-17 10:31:17 | شامل 1 دستور SQL
   ========================================================================== */

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
           ) AS RoleNames,
           ph.ContactValue AS PrimaryPhone, ph.Extension AS PrimaryPhoneExtension, ph.TypeName AS PrimaryPhoneType
    FROM dbo.CrmPartyPersonRelations r
    JOIN dbo.CrmPersons per ON per.PersonID = r.PersonID
    -- شمارهٔ تماسِ اصلیِ مخاطب: موبایل/تلفنِ فعالِ خودِ شخص یا تماس‌هایِ همین طرف‌حساب که به او مرتبط شده‌اند؛
    -- اولویت با «تماسِ اصلی»، بعد شمارهٔ خودِ شخص، بعد موبایل.
    OUTER APPLY (
        SELECT TOP 1 c.ContactValue, c.Extension, LTRIM(RTRIM(t.DisplayName)) AS TypeName
        FROM dbo.CrmContacts c
        JOIN dbo.CrmContactTypes t ON t.ContactTypeID = c.ContactTypeID AND LTRIM(RTRIM(t.DisplayName)) IN (N'موبایل', N'تلفن')
        WHERE c.IsActive = 1
          AND (c.PersonID = r.PersonID OR (c.PartyID = r.PartyID AND c.RelatedPersonID = r.PersonID))
        ORDER BY c.IsPrimary DESC, CASE WHEN c.PersonID = r.PersonID THEN 0 ELSE 1 END,
                 CASE WHEN LTRIM(RTRIM(t.DisplayName)) = N'موبایل' THEN 0 ELSE 1 END, c.ContactID
    ) ph
    WHERE r.PartyID = @PartyID
    ORDER BY r.IsPrimaryContact DESC, r.RelationID;
END
GO

