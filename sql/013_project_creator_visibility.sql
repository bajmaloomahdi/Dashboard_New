/* ==========================================================================
   پچ خودکار شماره: 013 | نام: project_creator_visibility
   تاریخ: 2026-09-08 13:44:48 | شامل 1 دستور SQL
   ========================================================================== */

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_GetProjects
ALTER PROCEDURE [dbo].[sp_GetProjects]
    @SearchText      NVARCHAR(250) = NULL,
    @IsActive        INT = NULL,
    @ProjectStatusID INT = NULL,
    @UserID          INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        p.ProjectID,
        p.ProjectCode,
        p.ProjectTitle,
        p.Description,
        p.StartDate,
        p.PlannedEndDate,
        p.ActualEndDate,
        p.ProjectStatusID,
        ps.Title      AS ProjectStatusTitle,
        p.ProgressPercent,
        p.IsActive,
        p.Date_InsertFirst,
        p.UserID_InsertFirst,
        p.ProjectPriorityID,
        pp.Name       AS PriorityName,
        pp.ColorHex   AS PriorityColor,
        pp.SortOrder  AS PrioritySortOrder,
        ISNULL(u.FullName, N'') AS CreatorName,
        ISNULL(resp.FullName, N'') AS ResponsibleName,
        ISNULL((SELECT COUNT(*)
                FROM dbo.ProjectMembers pm
                WHERE pm.ProjectID = p.ProjectID AND pm.IsActive = 1), 0) AS MemberCount
    FROM dbo.Projects p
    LEFT JOIN dbo.ProjectStatuses ps ON ps.ProjectStatusID = p.ProjectStatusID
    LEFT JOIN dbo.ProjectPriorities pp ON pp.ProjectPriorityID = p.ProjectPriorityID
    LEFT JOIN dbo.Users u ON u.UserID = p.UserID_InsertFirst
    OUTER APPLY (
        SELECT TOP 1 r.FullName
        FROM dbo.ProjectMembers pm2
        LEFT JOIN dbo.Users r ON r.UserID = pm2.UserID
        WHERE pm2.ProjectID = p.ProjectID AND pm2.IsResponsible = 1 AND pm2.IsActive = 1
    ) resp
    WHERE
        (@SearchText IS NULL OR @SearchText = '' OR p.ProjectTitle LIKE N'%' + @SearchText + N'%' OR p.ProjectCode LIKE N'%' + @SearchText + N'%')
        AND (@IsActive IS NULL OR p.IsActive = @IsActive)
        AND (@ProjectStatusID IS NULL OR p.ProjectStatusID = @ProjectStatusID)
        AND (@UserID IS NULL
             OR p.UserID_InsertFirst = @UserID          -- سازنده همیشه پروژه‌ی خودش را می‌بیند
             OR EXISTS (
                SELECT 1 FROM dbo.ProjectMembers pmx
                WHERE pmx.ProjectID = p.ProjectID AND pmx.UserID = @UserID AND pmx.IsActive = 1
            ))
    ORDER BY p.ProjectID DESC;
END
GO

