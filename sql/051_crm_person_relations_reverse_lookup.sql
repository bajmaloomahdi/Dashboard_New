/* ==========================================================================
   پچ خودکار شماره: 051 | نام: crm_person_relations_reverse_lookup
   تاریخ: 2026-09-24 21:16:23 | شامل 1 دستور SQL
   ========================================================================== */

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetPersonRelations
CREATE PROCEDURE dbo.sp_Crm_GetPersonRelations
    @PersonID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT r.RelationID, r.PartyID, r.PersonID, r.PositionID, r.IsPrimaryContact, r.IsActive,
           r.Date_InsertFirst, r.UserID_InsertFirst, r.Date_LastUpdate, r.UserID_LastUpdate,
           p.PartyNature, ISNULL(p.OfficialName, N'') AS PartyDisplayName,
           pos.DisplayName AS PositionName,
           (
               SELECT STRING_AGG(cr.DisplayName, N'، ') WITHIN GROUP (ORDER BY cr.SortOrder)
               FROM dbo.CrmPartyPersonRelationRoles rr
               JOIN dbo.CrmContactRoles cr ON cr.ContactRoleID = rr.RoleID
               WHERE rr.RelationID = r.RelationID
           ) AS RoleNames
    FROM dbo.CrmPartyPersonRelations r
    JOIN dbo.CrmParties p ON p.PartyID = r.PartyID
    LEFT JOIN dbo.CrmPositions pos ON pos.PositionID = r.PositionID
    WHERE r.PersonID = @PersonID
    ORDER BY r.IsPrimaryContact DESC, r.RelationID;
END
GO

