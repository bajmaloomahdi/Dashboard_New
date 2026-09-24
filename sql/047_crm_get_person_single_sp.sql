/* ==========================================================================
   پچ خودکار شماره: 047 | نام: crm_get_person_single_sp
   تاریخ: 2026-09-24 14:04:12 | شامل 1 دستور SQL
   ========================================================================== */

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetPerson
CREATE PROCEDURE dbo.sp_Crm_GetPerson
    @PersonID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT per.PersonID, per.FirstName, per.LastName, per.TitleID, per.IdentifierNumber, per.IdentifierDate,
           per.Description, per.IsActive, per.RowGuid,
           (per.FirstName + N' ' + per.LastName) AS DisplayName,
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

