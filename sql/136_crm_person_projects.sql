/* ==========================================================================
   پچ خودکار شماره: 136 | نام: crm_person_projects
   تاریخ: 2026-11-02 12:20:40 | شامل 1 دستور SQL
   ========================================================================== */

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetPersonProjects
-- پچِ ۱۳۶: پروژه‌هایِ مرتبط با یک «فردِ مرتبط» برایِ تبِ «پروژه‌ها»ی صفحهٔ فرد:
--   ۱) از راهِ طرف‌حساب‌هایی که فرد با آن‌ها رابطهٔ فعال دارد (مالکِ پروژه یا پیمانکارِ فعال) — ViaParties
--   ۲) کاربرِ متصل به همین فرد (Users.CrmPersonID) عضوِ فعالِ پروژه است — IsMember
-- CanView همان قاعدهٔ sp_GetProjects (سازنده یا عضوِ فعال)؛ برنامه فقط CanView = 1 را نشان می‌دهد و از بقیه فقط تعداد.
CREATE PROCEDURE dbo.sp_Crm_GetPersonProjects
    @PersonID INT,
    @UserID   INT
AS
BEGIN
    SET NOCOUNT ON;
    ;WITH RelParties AS (
        SELECT DISTINCT r.PartyID FROM dbo.CrmPartyPersonRelations r WHERE r.PersonID = @PersonID AND r.IsActive = 1
    ),
    Links AS (
        SELECT p.ProjectID, rp.PartyID, N'OWNER' AS Rel FROM dbo.Projects p INNER JOIN RelParties rp ON rp.PartyID = p.OwnerPartyID
        UNION
        SELECT pc.ProjectID, pc.PartyID, N'CONTRACTOR' FROM dbo.ProjectContractors pc INNER JOIN RelParties rp ON rp.PartyID = pc.PartyID WHERE pc.IsActive = 1
    ),
    Memberships AS (
        SELECT DISTINCT pm.ProjectID
        FROM dbo.ProjectMembers pm INNER JOIN dbo.Users lu ON lu.UserID = pm.UserID
        WHERE lu.CrmPersonID = @PersonID AND pm.IsActive = 1
    ),
    ProjectIds AS (
        SELECT ProjectID FROM Links UNION SELECT ProjectID FROM Memberships
    )
    SELECT
        p.ProjectID, p.ProjectCode, p.ProjectTitle,
        ps.Title AS ProjectStatusTitle,
        pp.Name AS PriorityName, pp.ColorHex AS PriorityColor,
        p.ProgressPercent, p.StartDate, p.PlannedEndDate, p.ActualEndDate, p.IsActive,
        ISNULL(resp.FullName, N'') AS ResponsibleName,
        ISNULL((SELECT STRING_AGG(CONCAT(cp.OfficialName, N' (', CASE l.Rel WHEN N'OWNER' THEN N'مالک' ELSE N'پیمانکار' END, N')'), N'، ')
                       WITHIN GROUP (ORDER BY cp.OfficialName)
                FROM Links l INNER JOIN dbo.CrmParties cp ON cp.PartyID = l.PartyID
                WHERE l.ProjectID = p.ProjectID), N'') AS ViaParties,
        CAST(CASE WHEN EXISTS (SELECT 1 FROM Memberships m WHERE m.ProjectID = p.ProjectID) THEN 1 ELSE 0 END AS BIT) AS IsMember,
        CAST(CASE WHEN p.UserID_InsertFirst = @UserID
                    OR EXISTS (SELECT 1 FROM dbo.ProjectMembers vm WHERE vm.ProjectID = p.ProjectID AND vm.UserID = @UserID AND vm.IsActive = 1)
                  THEN 1 ELSE 0 END AS BIT) AS CanView
    FROM ProjectIds ids
    INNER JOIN dbo.Projects p ON p.ProjectID = ids.ProjectID
    LEFT JOIN dbo.ProjectStatuses ps ON ps.ProjectStatusID = p.ProjectStatusID
    LEFT JOIN dbo.ProjectPriorities pp ON pp.ProjectPriorityID = p.ProjectPriorityID
    OUTER APPLY (
        SELECT TOP 1 r.FullName
        FROM dbo.ProjectMembers pm2
        LEFT JOIN dbo.Users r ON r.UserID = pm2.UserID
        WHERE pm2.ProjectID = p.ProjectID AND pm2.IsResponsible = 1 AND pm2.IsActive = 1
    ) resp
    ORDER BY p.IsActive DESC, p.ProjectID DESC;
END
GO

