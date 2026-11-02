/* ==========================================================================
   پچ خودکار شماره: 135 | نام: crm_party_projects
   تاریخ: 2026-11-02 12:14:27 | شامل 1 دستور SQL
   ========================================================================== */

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetPartyProjects
-- پچِ ۱۳۵: پروژه‌هایِ مرتبط با یک طرف‌حساب برایِ تبِ «پروژه‌ها»ی صفحهٔ طرف‌حساب — مالکِ پروژه (Projects.OwnerPartyID)
-- یا پیمانکارِ فعال (ProjectContractors). CanView همان قاعدهٔ sp_GetProjects است (سازنده یا عضوِ فعال)؛
-- برنامه فقط ردیف‌هایِ CanView = 1 را نشان می‌دهد و از بقیه فقط تعداد.
CREATE PROCEDURE dbo.sp_Crm_GetPartyProjects
    @PartyID INT,
    @UserID  INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        p.ProjectID, p.ProjectCode, p.ProjectTitle,
        ps.Title AS ProjectStatusTitle,
        pp.Name AS PriorityName, pp.ColorHex AS PriorityColor,
        p.ProgressPercent, p.StartDate, p.PlannedEndDate, p.ActualEndDate, p.IsActive,
        ISNULL(resp.FullName, N'') AS ResponsibleName,
        CAST(CASE WHEN p.OwnerPartyID = @PartyID THEN 1 ELSE 0 END AS BIT) AS IsOwner,
        CAST(CASE WHEN EXISTS (SELECT 1 FROM dbo.ProjectContractors pc2 WHERE pc2.ProjectID = p.ProjectID AND pc2.PartyID = @PartyID AND pc2.IsActive = 1) THEN 1 ELSE 0 END AS BIT) AS IsContractor,
        CAST(CASE WHEN p.UserID_InsertFirst = @UserID
                    OR EXISTS (SELECT 1 FROM dbo.ProjectMembers pm WHERE pm.ProjectID = p.ProjectID AND pm.UserID = @UserID AND pm.IsActive = 1)
                  THEN 1 ELSE 0 END AS BIT) AS CanView
    FROM dbo.Projects p
    LEFT JOIN dbo.ProjectStatuses ps ON ps.ProjectStatusID = p.ProjectStatusID
    LEFT JOIN dbo.ProjectPriorities pp ON pp.ProjectPriorityID = p.ProjectPriorityID
    OUTER APPLY (
        SELECT TOP 1 r.FullName
        FROM dbo.ProjectMembers pm2
        LEFT JOIN dbo.Users r ON r.UserID = pm2.UserID
        WHERE pm2.ProjectID = p.ProjectID AND pm2.IsResponsible = 1 AND pm2.IsActive = 1
    ) resp
    WHERE p.OwnerPartyID = @PartyID
       OR EXISTS (SELECT 1 FROM dbo.ProjectContractors pc WHERE pc.ProjectID = p.ProjectID AND pc.PartyID = @PartyID AND pc.IsActive = 1)
    ORDER BY p.IsActive DESC, p.ProjectID DESC;
END
GO

