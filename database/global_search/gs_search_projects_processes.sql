/* ==========================================================================
   Raga 360 - Global Search: projects and processes (engine B, base tables)
   Version : 1.1 (2026-10-15) - 1.1: search text kept out of plan cache and Query Store - applied ONLY to (localdb)\GsTest / Dashboard_GsTest
   Objects : dbo.sp_Gs_SearchProjects, dbo.sp_Gs_SearchProcesses
             (both use dbo.fn_Gs_Normalize from gs_search_crm.sql)

   ACCESS RULES (taken from the existing pages; nothing new is invented)
   - Projects (ProjectsController::index/show -> sp_GetProjects @UserID):
       visible to the creator (Projects.UserID_InsertFirst) OR an ACTIVE member
       (ProjectMembers.IsActive = 1). An inactive membership alone gives no access.
       No permission gate, no admin override. Inactive projects stay visible to those
       users (the page does not filter IsActive) and are flagged IsInactive.
       Only code, title and status title are returned - no owner party/brand, members,
       interactions or messages.
   - Processes (ProcessDefinitionController / ProcessInstanceController):
       WORKFLOW_VIEW sees ALL definitions (active and inactive) and ALL instances
       (any status); there is no per-record or per-user filter. Definitions and
       instances are returned as separate item types. The entity an instance is
       attached to (EntityType / EntityID, e.g. a message) is never returned, so
       WORKFLOW_VIEW gives no visibility into messages or CRM.
   - Both procedures require Users.IsActive = 1; otherwise they return nothing.
   - Fully parameterized; LIKE wildcards are escaped by the caller. Read-only.
   - Plain ASCII on purpose.
   ========================================================================== */
SET NOCOUNT ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;
GO

IF CAST(SERVERPROPERTY('IsLocalDB') AS INT) <> 1 OR DB_NAME() <> N'Dashboard_GsTest'
    THROW 53000, N'SAFETY: this script may only run on (localdb)\GsTest / Dashboard_GsTest.', 1;
IF OBJECT_ID(N'dbo.fn_Gs_Normalize', N'FN') IS NULL
    THROW 53001, N'dbo.fn_Gs_Normalize is missing - apply gs_search_crm.sql first.', 1;
GO

CREATE OR ALTER PROCEDURE dbo.sp_Gs_SearchProjects
    @UserID    INT,
    @Query     NVARCHAR(100),
    @QueryLike NVARCHAR(400),
    @T1 NVARCHAR(400) = NULL, @T2 NVARCHAR(400) = NULL, @T3 NVARCHAR(400) = NULL,
    @T4 NVARCHAR(400) = NULL, @T5 NVARCHAR(400) = NULL, @T6 NVARCHAR(400) = NULL,
    @Top       INT = 5
AS
BEGIN
    SET NOCOUNT ON;
    SET LOCK_TIMEOUT 2000;

    -- PRIVACY (audit finding 2): the search text must not be kept as a sniffed ParameterCompiledValue
    -- in the plan cache or Query Store. Parameters are copied once into local variables (plain scalar
    -- assignments), only the locals are used below, and the search statement is compiled with
    -- OPTIMIZE FOR UNKNOWN so that not even a statement-level recompile sniffs their values.
    DECLARE @vUserID INT = @UserID,
            @vQuery NVARCHAR(100) = @Query, @vQueryLike NVARCHAR(400) = @QueryLike,
            @vT1 NVARCHAR(400) = @T1, @vT2 NVARCHAR(400) = @T2, @vT3 NVARCHAR(400) = @T3,
            @vT4 NVARCHAR(400) = @T4, @vT5 NVARCHAR(400) = @T5, @vT6 NVARCHAR(400) = @T6,
            @vTop INT = @Top;
    DECLARE @vValid BIT = CASE WHEN @vQuery IS NOT NULL AND LEN(@vQuery) >= 1 AND @vQueryLike IS NOT NULL THEN 1 ELSE 0 END;

    IF @vTop IS NULL OR @vTop < 1 SET @vTop = 1;
    IF @vTop > 50 SET @vTop = 50;

    DECLARE @allowed BIT = 0;
    IF @vValid = 1
       AND EXISTS (SELECT 1 FROM dbo.Users u WHERE u.UserID = @vUserID AND u.IsActive = 1)
        SET @allowed = 1;

    ;WITH cand AS (
        SELECT p.ProjectID, p.ProjectCode, p.ProjectTitle, ps.Title AS StatusTitle,
               CAST(CASE WHEN p.IsActive = 0 THEN 1 ELSE 0 END AS BIT) AS IsInactive,
               COALESCE(p.Date_LastUpdate, p.Date_InsertFirst) AS SortDate,
               dbo.fn_Gs_Normalize(p.ProjectTitle) AS N1,
               dbo.fn_Gs_Normalize(p.ProjectCode) AS C1
        FROM dbo.Projects p
        LEFT JOIN dbo.ProjectStatuses ps ON ps.ProjectStatusID = p.ProjectStatusID
        WHERE @allowed = 1
          AND (p.UserID_InsertFirst = @vUserID
               OR EXISTS (SELECT 1 FROM dbo.ProjectMembers pm
                          WHERE pm.ProjectID = p.ProjectID AND pm.UserID = @vUserID AND pm.IsActive = 1))
    ),
    hay AS (
        SELECT cand.*, ISNULL(N1, N'') + N' ' + ISNULL(C1, N'') AS H FROM cand
    ),
    matched AS (
        SELECT hay.*,
               CASE
                   WHEN C1 = @vQuery                         THEN 1000
                   WHEN N1 = @vQuery                         THEN 500
                   WHEN N1 LIKE @vQueryLike + N'%'           THEN 400
                   WHEN N1 LIKE N'% ' + @vQueryLike + N'%'   THEN 300
                   WHEN N1 LIKE N'%' + @vQueryLike + N'%'    THEN 200
                   ELSE 100
               END AS Score
        FROM hay
        WHERE (@vT1 IS NULL OR H LIKE N'%' + @vT1 + N'%')
          AND (@vT2 IS NULL OR H LIKE N'%' + @vT2 + N'%')
          AND (@vT3 IS NULL OR H LIKE N'%' + @vT3 + N'%')
          AND (@vT4 IS NULL OR H LIKE N'%' + @vT4 + N'%')
          AND (@vT5 IS NULL OR H LIKE N'%' + @vT5 + N'%')
          AND (@vT6 IS NULL OR H LIKE N'%' + @vT6 + N'%')
          AND (@vT1 IS NOT NULL OR H LIKE N'%' + @vQueryLike + N'%')
    )
    SELECT TOP (@vTop + 1)
           N'project' AS ItemType, ProjectID AS ItemID, ProjectTitle AS Title, ProjectCode,
           StatusTitle, IsInactive, Score
    FROM matched
    ORDER BY Score DESC, IsInactive ASC, SortDate DESC, ProjectID DESC
    OPTION (OPTIMIZE FOR UNKNOWN);
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_Gs_SearchProcesses
    @UserID    INT,
    @Query     NVARCHAR(100),
    @QueryLike NVARCHAR(400),
    @T1 NVARCHAR(400) = NULL, @T2 NVARCHAR(400) = NULL, @T3 NVARCHAR(400) = NULL,
    @T4 NVARCHAR(400) = NULL, @T5 NVARCHAR(400) = NULL, @T6 NVARCHAR(400) = NULL,
    @Top       INT = 5
AS
BEGIN
    SET NOCOUNT ON;
    SET LOCK_TIMEOUT 2000;

    -- PRIVACY (audit finding 2): the search text must not be kept as a sniffed ParameterCompiledValue
    -- in the plan cache or Query Store. Parameters are copied once into local variables (plain scalar
    -- assignments), only the locals are used below, and the search statement is compiled with
    -- OPTIMIZE FOR UNKNOWN so that not even a statement-level recompile sniffs their values.
    DECLARE @vUserID INT = @UserID,
            @vQuery NVARCHAR(100) = @Query, @vQueryLike NVARCHAR(400) = @QueryLike,
            @vT1 NVARCHAR(400) = @T1, @vT2 NVARCHAR(400) = @T2, @vT3 NVARCHAR(400) = @T3,
            @vT4 NVARCHAR(400) = @T4, @vT5 NVARCHAR(400) = @T5, @vT6 NVARCHAR(400) = @T6,
            @vTop INT = @Top;
    DECLARE @vValid BIT = CASE WHEN @vQuery IS NOT NULL AND LEN(@vQuery) >= 1 AND @vQueryLike IS NOT NULL THEN 1 ELSE 0 END;

    IF @vTop IS NULL OR @vTop < 1 SET @vTop = 1;
    IF @vTop > 50 SET @vTop = 50;

    DECLARE @allowed BIT = 0;
    IF @vValid = 1
       AND EXISTS (SELECT 1 FROM dbo.Users u WHERE u.UserID = @vUserID AND u.IsActive = 1)
       AND EXISTS (SELECT 1
                   FROM dbo.RolePermissions rp
                   JOIN dbo.Permissions p ON p.PermissionID = rp.PermissionID
                   JOIN dbo.UserRoles ur  ON ur.RoleID = rp.RoleID
                   JOIN dbo.Roles r       ON r.RoleID = ur.RoleID
                   WHERE ur.UserID = @vUserID AND p.PermissionCode = N'WORKFLOW_VIEW'
                     AND p.IsActive = 1 AND rp.IsActive = 1 AND rp.CanAccess = 1 AND ur.IsActive = 1 AND r.IsActive = 1)
        SET @allowed = 1;

    ;WITH cand AS (
        /* ---- definitions ---- */
        SELECT N'process_definition' AS ItemType, d.DefinitionID AS ItemID, d.Name AS Title,
               d.Code AS RefCode, CAST(NULL AS NVARCHAR(20)) AS StatusCode, CAST(NULL AS NVARCHAR(10)) AS StartedOn,
               CAST(CASE WHEN d.IsActive = 0 THEN 1 ELSE 0 END AS BIT) AS IsInactive,
               COALESCE(d.Date_LastUpdate, d.Date_InsertFirst) AS SortDate,
               dbo.fn_Gs_Normalize(d.Name) AS N1, dbo.fn_Gs_Normalize(d.Code) AS C1, 1 AS TypeOrder
        FROM dbo.WorkflowDefinitions d
        WHERE @allowed = 1
        UNION ALL
        /* ---- instances (entity data deliberately NOT read) ---- */
        SELECT N'process_instance', i.InstanceID, d.Name,
               i.InstanceNumber, i.Status, CONVERT(NVARCHAR(10), i.StartedAt, 120),
               CAST(0 AS BIT),
               i.StartedAt,
               dbo.fn_Gs_Normalize(d.Name), dbo.fn_Gs_Normalize(i.InstanceNumber), 2
        FROM dbo.WorkflowInstances i
        JOIN dbo.WorkflowDefinitions d ON d.DefinitionID = i.DefinitionID
        WHERE @allowed = 1
    ),
    hay AS (
        SELECT cand.*, ISNULL(N1, N'') + N' ' + ISNULL(C1, N'') AS H FROM cand
    ),
    matched AS (
        SELECT hay.*,
               CASE
                   WHEN C1 = @vQuery                         THEN 1000
                   WHEN N1 = @vQuery                         THEN 500
                   WHEN N1 LIKE @vQueryLike + N'%'           THEN 400
                   WHEN N1 LIKE N'% ' + @vQueryLike + N'%'   THEN 300
                   WHEN N1 LIKE N'%' + @vQueryLike + N'%'    THEN 200
                   ELSE 100
               END AS Score
        FROM hay
        WHERE (@vT1 IS NULL OR H LIKE N'%' + @vT1 + N'%')
          AND (@vT2 IS NULL OR H LIKE N'%' + @vT2 + N'%')
          AND (@vT3 IS NULL OR H LIKE N'%' + @vT3 + N'%')
          AND (@vT4 IS NULL OR H LIKE N'%' + @vT4 + N'%')
          AND (@vT5 IS NULL OR H LIKE N'%' + @vT5 + N'%')
          AND (@vT6 IS NULL OR H LIKE N'%' + @vT6 + N'%')
          AND (@vT1 IS NOT NULL OR H LIKE N'%' + @vQueryLike + N'%')
    )
    SELECT TOP (@vTop + 1)
           ItemType, ItemID, Title, RefCode, StatusCode, StartedOn, IsInactive, Score
    FROM matched
    ORDER BY Score DESC, IsInactive ASC, TypeOrder ASC, SortDate DESC, ItemID DESC
    OPTION (OPTIMIZE FOR UNKNOWN);
END
GO
