/* ==========================================================================
   پچ 015 | Workflow Engine — فاز ۱ — لایه‌ی Stored Procedure

   قوانین:
   - فقط SPهای جدید با پیشوند sp_Wf_* و sp_BusinessCalendar_*.
   - هیچ SP/Function/View موجودی تغییر نمی‌کند.
   - خروجی استاندارد پروژه: SELECT ... AS Success, ... AS Message  (برای SPهای نوشتنی).
   - SPهای نوشتنی «تراکنش داخلی» ندارند؛ مرزِ تراکنش را لایه‌ی PHP (DB::transaction)
     مدیریت می‌کند — هم‌الگو با sp_SaveRolePermissions موجود.
   - SQL پارامتریک؛ Dynamic SQL استفاده نشده.
   - Concurrency: sp_Wf_CompleteTask با شرط RowVersion + Status.
   - Idempotency: guardهای @@ROWCOUNT روی تغییرِ وضعیت + Decision.
   ========================================================================== */

SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;
GO

/* =====================================================================
   ۱) شماره‌گذاری  —  از dbo.CodeCounters موجود
   ===================================================================== */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_NextNumber
    @EntityName NVARCHAR(50),      -- WF_DEFINITION | WF_INSTANCE | WF_TASK
    @Prefix     NVARCHAR(10)       -- WFD | WFI | WFT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Next INT = NULL;

    UPDATE dbo.CodeCounters WITH (UPDLOCK, SERIALIZABLE)
        SET @Next = LastNumber = LastNumber + 1
    WHERE EntityName = @EntityName;

    IF @@ROWCOUNT = 0
    BEGIN
        INSERT INTO dbo.CodeCounters (EntityName, LastNumber) VALUES (@EntityName, 1);
        SET @Next = 1;
    END

    SELECT
        (@Prefix + N'-' + RIGHT(REPLICATE(N'0', 6) + CAST(@Next AS NVARCHAR(20)), 6)) AS Number,
        @Next AS Seq;
END
GO


/* =====================================================================
   ۲) Definition  —  CRUD
   ===================================================================== */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetDefinitions
    @SearchText NVARCHAR(200) = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        d.DefinitionID, d.Code, d.Name, d.Description, d.EntityType, d.IsActive,
        d.Date_InsertFirst, d.UserID_InsertFirst,
        cu.FullName AS CreatedByName,
        (SELECT COUNT(*) FROM dbo.WorkflowVersions v WHERE v.DefinitionID = d.DefinitionID)                          AS VersionCount,
        (SELECT MAX(v.VersionNo) FROM dbo.WorkflowVersions v WHERE v.DefinitionID = d.DefinitionID AND v.Status = N'ACTIVE') AS ActiveVersionNo,
        (SELECT COUNT(*) FROM dbo.WorkflowInstances i WHERE i.DefinitionID = d.DefinitionID)                         AS InstanceCount
    FROM dbo.WorkflowDefinitions d
    LEFT JOIN dbo.Users cu ON cu.UserID = d.UserID_InsertFirst
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR d.Name LIKE N'%' + @SearchText + N'%'
           OR d.Code LIKE N'%' + @SearchText + N'%'
           OR d.EntityType LIKE N'%' + @SearchText + N'%')
      AND (@IsActive IS NULL OR d.IsActive = @IsActive)
    ORDER BY d.DefinitionID DESC;
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetDefinition
    @DefinitionID INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT d.DefinitionID, d.Code, d.Name, d.Description, d.EntityType, d.IsActive,
           d.Date_InsertFirst, d.UserID_InsertFirst, d.Date_LastUpdate, d.UserID_LastUpdate
    FROM dbo.WorkflowDefinitions d
    WHERE d.DefinitionID = @DefinitionID;

    -- نسخه‌ها
    SELECT v.VersionID, v.VersionNo, v.Status, v.PublishedAt, v.ArchivedAt, v.ClonedFromVersionID,
           pu.FullName AS PublishedByName, v.ValidationResultJson,
           v.Date_InsertFirst,
           (SELECT COUNT(*) FROM dbo.WorkflowSteps s WHERE s.VersionID = v.VersionID)       AS StepCount,
           (SELECT COUNT(*) FROM dbo.WorkflowInstances i WHERE i.VersionID = v.VersionID)    AS InstanceCount
    FROM dbo.WorkflowVersions v
    LEFT JOIN dbo.Users pu ON pu.UserID = v.PublishedByUserID
    WHERE v.DefinitionID = @DefinitionID
    ORDER BY v.VersionNo DESC;
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_Wf_SaveDefinition
    @DefinitionID INT           = NULL,   -- NULL = ایجاد
    @Code         NVARCHAR(64),
    @Name         NVARCHAR(200),
    @Description  NVARCHAR(1000) = NULL,
    @EntityType   NVARCHAR(64),
    @IsActive     BIT           = 1,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@Code)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'کد فرایند الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@Name)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نام فرایند الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@EntityType)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نوع موجودیت (EntityType) الزامی است.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.WorkflowDefinitions
               WHERE Code = @Code AND (@DefinitionID IS NULL OR DefinitionID <> @DefinitionID))
    BEGIN SELECT 0 AS Success, N'کد فرایند تکراری است.' AS Message; RETURN; END

    IF @DefinitionID IS NULL
    BEGIN
        INSERT INTO dbo.WorkflowDefinitions (Code, Name, Description, EntityType, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@Code, @Name, @Description, @EntityType, @IsActive, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'فرایند ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS DefinitionID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowDefinitions WHERE DefinitionID = @DefinitionID)
        BEGIN SELECT 0 AS Success, N'فرایند یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.WorkflowDefinitions
        SET Code = @Code, Name = @Name, Description = @Description, EntityType = @EntityType, IsActive = @IsActive,
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE DefinitionID = @DefinitionID;

        SELECT 1 AS Success, N'فرایند به‌روزرسانی شد.' AS Message, @DefinitionID AS DefinitionID;
    END
END
GO


/* =====================================================================
   ۳) Version  —  ایجاد Draft / Clone / خواندن گراف / ذخیره‌ی گراف / انتشار
   ===================================================================== */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_CreateDraftVersion
    @DefinitionID INT,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowDefinitions WHERE DefinitionID = @DefinitionID)
    BEGIN SELECT 0 AS Success, N'فرایند یافت نشد.' AS Message; RETURN; END

    DECLARE @No INT = ISNULL((SELECT MAX(VersionNo) FROM dbo.WorkflowVersions WHERE DefinitionID = @DefinitionID), 0) + 1;

    INSERT INTO dbo.WorkflowVersions (DefinitionID, VersionNo, Status, Date_InsertFirst, UserID_InsertFirst)
    VALUES (@DefinitionID, @No, N'DRAFT', SYSDATETIME(), @UserID);

    SELECT 1 AS Success, N'نسخه‌ی پیش‌نویس ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS VersionID, @No AS VersionNo;
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_Wf_CloneVersion
    @SourceVersionID INT,
    @UserID          INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @DefinitionID INT;
    SELECT @DefinitionID = DefinitionID FROM dbo.WorkflowVersions WHERE VersionID = @SourceVersionID;
    IF @DefinitionID IS NULL
    BEGIN SELECT 0 AS Success, N'نسخه‌ی مبدأ یافت نشد.' AS Message; RETURN; END

    DECLARE @No INT = ISNULL((SELECT MAX(VersionNo) FROM dbo.WorkflowVersions WHERE DefinitionID = @DefinitionID), 0) + 1;

    INSERT INTO dbo.WorkflowVersions (DefinitionID, VersionNo, Status, ClonedFromVersionID, Date_InsertFirst, UserID_InsertFirst)
    VALUES (@DefinitionID, @No, N'DRAFT', @SourceVersionID, SYSDATETIME(), @UserID);
    DECLARE @NewVersionID INT = CAST(SCOPE_IDENTITY() AS INT);

    -- Steps  (نگاشت StepID قدیمی → جدید)
    DECLARE @Map TABLE (OldStepID INT PRIMARY KEY, NewStepID INT, Code NVARCHAR(64));

    MERGE dbo.WorkflowSteps AS T
    USING (SELECT * FROM dbo.WorkflowSteps WHERE VersionID = @SourceVersionID) AS S
        ON 1 = 0
    WHEN NOT MATCHED THEN
        INSERT (VersionID, Code, Name, StepType, AssignPolicy, RequiredApprovals, AllowForward, ForwardMax, AllowDelegation, SortOrder, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@NewVersionID, S.Code, S.Name, S.StepType, S.AssignPolicy, S.RequiredApprovals, S.AllowForward, S.ForwardMax, S.AllowDelegation, S.SortOrder, SYSDATETIME(), @UserID)
    OUTPUT S.StepID, INSERTED.StepID, INSERTED.Code INTO @Map (OldStepID, NewStepID, Code);

    -- Actions  (نگاشت ActionID قدیمی → جدید)
    DECLARE @AMap TABLE (OldActionID INT PRIMARY KEY, NewActionID INT);

    MERGE dbo.WorkflowStepActions AS T
    USING (SELECT a.*, m.NewStepID FROM dbo.WorkflowStepActions a JOIN @Map m ON m.OldStepID = a.StepID WHERE a.VersionID = @SourceVersionID) AS S
        ON 1 = 0
    WHEN NOT MATCHED THEN
        INSERT (VersionID, StepID, Code, Kind, Label, Icon, Style, RequiresComment, RequiresConfirm, ConfirmMessage, PermissionCode, SortOrder, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@NewVersionID, S.NewStepID, S.Code, S.Kind, S.Label, S.Icon, S.Style, S.RequiresComment, S.RequiresConfirm, S.ConfirmMessage, S.PermissionCode, S.SortOrder, SYSDATETIME(), @UserID)
    OUTPUT S.ActionID, INSERTED.ActionID INTO @AMap (OldActionID, NewActionID);

    -- Assignments
    INSERT INTO dbo.WorkflowStepAssignments (VersionID, StepID, AssigneeType, RefID, RefExpression, SortOrder, Date_InsertFirst, UserID_InsertFirst)
    SELECT @NewVersionID, m.NewStepID, a.AssigneeType, a.RefID, a.RefExpression, a.SortOrder, SYSDATETIME(), @UserID
    FROM dbo.WorkflowStepAssignments a
    JOIN @Map m ON m.OldStepID = a.StepID
    WHERE a.VersionID = @SourceVersionID;

    -- Transitions  (با نگاشت Step و Action)
    INSERT INTO dbo.WorkflowTransitions (VersionID, Code, FromStepID, ToStepID, TriggerActionID, Priority, IsDefault, Label, Date_InsertFirst, UserID_InsertFirst)
    SELECT @NewVersionID, tr.Code, fm.NewStepID, tm.NewStepID,
           am.NewActionID, tr.Priority, tr.IsDefault, tr.Label, SYSDATETIME(), @UserID
    FROM dbo.WorkflowTransitions tr
    JOIN @Map fm ON fm.OldStepID = tr.FromStepID
    JOIN @Map tm ON tm.OldStepID = tr.ToStepID
    LEFT JOIN @AMap am ON am.OldActionID = tr.TriggerActionID
    WHERE tr.VersionID = @SourceVersionID;

    SELECT 1 AS Success, N'نسخه‌ی جدید از روی نسخه‌ی قبلی ساخته شد.' AS Message, @NewVersionID AS VersionID, @No AS VersionNo;
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetVersionMeta
    @VersionID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT v.VersionID, v.DefinitionID, v.VersionNo, v.Status, v.ValidationResultJson,
           v.PublishedAt, v.PublishedByUserID, v.ArchivedAt, v.ClonedFromVersionID,
           d.Code AS DefinitionCode, d.Name AS DefinitionName, d.EntityType
    FROM dbo.WorkflowVersions v
    JOIN dbo.WorkflowDefinitions d ON d.DefinitionID = v.DefinitionID
    WHERE v.VersionID = @VersionID;
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetVersionSteps
    @VersionID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT StepID, VersionID, Code, Name, StepType, AssignPolicy, RequiredApprovals,
           AllowForward, ForwardMax, AllowDelegation, SortOrder
    FROM dbo.WorkflowSteps WHERE VersionID = @VersionID ORDER BY SortOrder, StepID;
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetVersionStepActions
    @VersionID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT ActionID, StepID, Code, Kind, Label, Icon, Style, RequiresComment, RequiresConfirm,
           ConfirmMessage, PermissionCode, SortOrder
    FROM dbo.WorkflowStepActions WHERE VersionID = @VersionID ORDER BY StepID, SortOrder, ActionID;
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetVersionStepAssignments
    @VersionID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT StepAssignmentID, StepID, AssigneeType, RefID, RefExpression, SortOrder
    FROM dbo.WorkflowStepAssignments WHERE VersionID = @VersionID ORDER BY StepID, SortOrder, StepAssignmentID;
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetVersionTransitions
    @VersionID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TransitionID, Code, FromStepID, ToStepID, TriggerActionID, Priority, IsDefault, Label
    FROM dbo.WorkflowTransitions WHERE VersionID = @VersionID ORDER BY FromStepID, Priority, TransitionID;
END
GO

/* ذخیره‌ی گرافِ نسخه — فقط وقتی DRAFT. جایگزینیِ کاملِ steps/actions/assignments/transitions از JSON.
   قراردادِ JSON:
   @StepsJson       : [{"code","name","stepType","assignPolicy","requiredApprovals","allowForward","forwardMax","allowDelegation","sortOrder"}]
   @ActionsJson     : [{"stepCode","code","kind","label","icon","style","requiresComment","requiresConfirm","confirmMessage","permissionCode","sortOrder"}]
   @AssignmentsJson : [{"stepCode","assigneeType","refId","refExpression","sortOrder"}]
   @TransitionsJson : [{"code","fromStepCode","toStepCode","triggerActionCode","priority","isDefault","label"}] */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_SaveVersionGraph
    @VersionID       INT,
    @StepsJson       NVARCHAR(MAX),
    @ActionsJson     NVARCHAR(MAX) = N'[]',
    @AssignmentsJson NVARCHAR(MAX) = N'[]',
    @TransitionsJson NVARCHAR(MAX) = N'[]',
    @UserID          INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Status NVARCHAR(20);
    SELECT @Status = Status FROM dbo.WorkflowVersions WHERE VersionID = @VersionID;
    IF @Status IS NULL
    BEGIN SELECT 0 AS Success, N'نسخه یافت نشد.' AS Message; RETURN; END
    IF @Status <> N'DRAFT'
    BEGIN SELECT 0 AS Success, N'فقط نسخه‌ی پیش‌نویس (DRAFT) قابل ویرایش است.' AS Message; RETURN; END

    IF ISJSON(@StepsJson) = 0 OR ISJSON(@ActionsJson) = 0 OR ISJSON(@AssignmentsJson) = 0 OR ISJSON(@TransitionsJson) = 0
    BEGIN SELECT 0 AS Success, N'ساختار JSON نامعتبر است.' AS Message; RETURN; END

    -- پاک‌سازی (ترتیب معکوس FK)
    DELETE FROM dbo.WorkflowTransitions       WHERE VersionID = @VersionID;
    DELETE FROM dbo.WorkflowStepAssignments   WHERE VersionID = @VersionID;
    DELETE FROM dbo.WorkflowStepActions       WHERE VersionID = @VersionID;
    DELETE FROM dbo.WorkflowSteps             WHERE VersionID = @VersionID;

    -- Steps
    INSERT INTO dbo.WorkflowSteps (VersionID, Code, Name, StepType, AssignPolicy, RequiredApprovals, AllowForward, ForwardMax, AllowDelegation, SortOrder, Date_InsertFirst, UserID_InsertFirst)
    SELECT @VersionID,
           j.Code, j.Name, j.StepType,
           ISNULL(j.AssignPolicy, N'ANY'), j.RequiredApprovals,
           ISNULL(j.AllowForward, 0), j.ForwardMax, ISNULL(j.AllowDelegation, 1),
           ISNULL(j.SortOrder, 0), SYSDATETIME(), @UserID
    FROM OPENJSON(@StepsJson) WITH (
        Code NVARCHAR(64) N'$.code', Name NVARCHAR(200) N'$.name', StepType NVARCHAR(30) N'$.stepType',
        AssignPolicy NVARCHAR(10) N'$.assignPolicy', RequiredApprovals INT N'$.requiredApprovals',
        AllowForward BIT N'$.allowForward', ForwardMax INT N'$.forwardMax', AllowDelegation BIT N'$.allowDelegation',
        SortOrder INT N'$.sortOrder'
    ) j;

    -- Actions
    INSERT INTO dbo.WorkflowStepActions (VersionID, StepID, Code, Kind, Label, Icon, Style, RequiresComment, RequiresConfirm, ConfirmMessage, PermissionCode, SortOrder, Date_InsertFirst, UserID_InsertFirst)
    SELECT @VersionID, s.StepID, j.Code, ISNULL(j.Kind, N'CUSTOM'), j.Label, j.Icon, j.Style,
           ISNULL(j.RequiresComment, 0), ISNULL(j.RequiresConfirm, 0), j.ConfirmMessage, j.PermissionCode,
           ISNULL(j.SortOrder, 0), SYSDATETIME(), @UserID
    FROM OPENJSON(@ActionsJson) WITH (
        StepCode NVARCHAR(64) N'$.stepCode', Code NVARCHAR(64) N'$.code', Kind NVARCHAR(20) N'$.kind',
        Label NVARCHAR(100) N'$.label', Icon NVARCHAR(50) N'$.icon', Style NVARCHAR(20) N'$.style',
        RequiresComment BIT N'$.requiresComment', RequiresConfirm BIT N'$.requiresConfirm',
        ConfirmMessage NVARCHAR(300) N'$.confirmMessage', PermissionCode NVARCHAR(100) N'$.permissionCode',
        SortOrder INT N'$.sortOrder'
    ) j
    JOIN dbo.WorkflowSteps s ON s.VersionID = @VersionID AND s.Code = j.StepCode;

    -- Assignments
    INSERT INTO dbo.WorkflowStepAssignments (VersionID, StepID, AssigneeType, RefID, RefExpression, SortOrder, Date_InsertFirst, UserID_InsertFirst)
    SELECT @VersionID, s.StepID, j.AssigneeType, j.RefID, j.RefExpression, ISNULL(j.SortOrder, 0), SYSDATETIME(), @UserID
    FROM OPENJSON(@AssignmentsJson) WITH (
        StepCode NVARCHAR(64) N'$.stepCode', AssigneeType NVARCHAR(30) N'$.assigneeType',
        RefID INT N'$.refId', RefExpression NVARCHAR(400) N'$.refExpression', SortOrder INT N'$.sortOrder'
    ) j
    JOIN dbo.WorkflowSteps s ON s.VersionID = @VersionID AND s.Code = j.StepCode;

    -- Transitions
    INSERT INTO dbo.WorkflowTransitions (VersionID, Code, FromStepID, ToStepID, TriggerActionID, Priority, IsDefault, Label, Date_InsertFirst, UserID_InsertFirst)
    SELECT @VersionID, j.Code, fs.StepID, ts.StepID, ta.ActionID, ISNULL(j.Priority, 100), ISNULL(j.IsDefault, 0), j.Label, SYSDATETIME(), @UserID
    FROM OPENJSON(@TransitionsJson) WITH (
        Code NVARCHAR(64) N'$.code', FromStepCode NVARCHAR(64) N'$.fromStepCode', ToStepCode NVARCHAR(64) N'$.toStepCode',
        TriggerActionCode NVARCHAR(64) N'$.triggerActionCode', Priority INT N'$.priority', IsDefault BIT N'$.isDefault',
        Label NVARCHAR(100) N'$.label'
    ) j
    JOIN dbo.WorkflowSteps fs ON fs.VersionID = @VersionID AND fs.Code = j.FromStepCode
    JOIN dbo.WorkflowSteps ts ON ts.VersionID = @VersionID AND ts.Code = j.ToStepCode
    LEFT JOIN dbo.WorkflowStepActions ta ON ta.StepID = fs.StepID AND ta.Code = j.TriggerActionCode;

    -- ذخیره‌ی گراف باعث بی‌اعتبارشدنِ نتیجه‌ی اعتبارسنجیِ قبلی می‌شود
    UPDATE dbo.WorkflowVersions SET ValidationResultJson = NULL, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE VersionID = @VersionID;

    SELECT 1 AS Success, N'گراف نسخه ذخیره شد.' AS Message;
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_Wf_SetValidationResult
    @VersionID INT,
    @Json      NVARCHAR(MAX),
    @UserID    INT
AS
BEGIN
    SET NOCOUNT ON;
    IF @Json IS NOT NULL AND ISJSON(@Json) = 0
    BEGIN SELECT 0 AS Success, N'نتیجه‌ی اعتبارسنجی JSON معتبر نیست.' AS Message; RETURN; END

    UPDATE dbo.WorkflowVersions SET ValidationResultJson = @Json, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE VersionID = @VersionID;

    IF @@ROWCOUNT = 0 BEGIN SELECT 0 AS Success, N'نسخه یافت نشد.' AS Message; RETURN; END
    SELECT 1 AS Success, N'ذخیره شد.' AS Message;
END
GO

/* انتشار — اتمیک: DRAFT → ACTIVE  +  ARCHIVE نسخه‌ی ACTIVE قبلی.
   PHP این SP را داخل DB::transaction صدا می‌زند؛ اعتبارسنجیِ گراف قبلاً در PHP انجام شده. */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_PublishVersion
    @VersionID INT,
    @UserID    INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @DefinitionID INT, @Status NVARCHAR(20), @EntityType NVARCHAR(64);
    SELECT @DefinitionID = v.DefinitionID, @Status = v.Status, @EntityType = d.EntityType
    FROM dbo.WorkflowVersions v JOIN dbo.WorkflowDefinitions d ON d.DefinitionID = v.DefinitionID
    WHERE v.VersionID = @VersionID;

    IF @DefinitionID IS NULL
    BEGIN SELECT 0 AS Success, N'نسخه یافت نشد.' AS Message; RETURN; END
    IF @Status <> N'DRAFT'
    BEGIN SELECT 0 AS Success, N'فقط نسخه‌ی پیش‌نویس قابل انتشار است.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowSteps WHERE VersionID = @VersionID AND StepType = N'START')
    BEGIN SELECT 0 AS Success, N'نسخه بدونِ Step شروع (START) قابل انتشار نیست.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowSteps WHERE VersionID = @VersionID AND StepType = N'END')
    BEGIN SELECT 0 AS Success, N'نسخه بدونِ Step پایان (END) قابل انتشار نیست.' AS Message; RETURN; END

    -- بایگانیِ نسخه‌ی ACTIVE فعلی
    UPDATE dbo.WorkflowVersions
    SET Status = N'ARCHIVED', ArchivedAt = SYSDATETIME(), Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE DefinitionID = @DefinitionID AND Status = N'ACTIVE';

    -- فعال‌سازیِ این نسخه
    UPDATE dbo.WorkflowVersions
    SET Status = N'ACTIVE', PublishedAt = SYSDATETIME(), PublishedByUserID = @UserID,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE VersionID = @VersionID;

    INSERT INTO dbo.WorkflowHistory (EntityType, EntityID, EventCode, ActorUserID, ActorType, OccurredAt, Summary, NewValueJson)
    VALUES (@EntityType, 0, N'VERSION_ACTIVATED', @UserID, N'USER', SYSDATETIME(),
            N'نسخه فعال شد', (SELECT @VersionID AS versionId, @DefinitionID AS definitionId FOR JSON PATH, WITHOUT_ARRAY_WRAPPER));

    SELECT 1 AS Success, N'نسخه با موفقیت منتشر و فعال شد.' AS Message;
END
GO


/* =====================================================================
   ۴) Assignment Resolution  —  انواع فاز ۱
   ===================================================================== */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_ResolveAssignees
    @AssigneeType       NVARCHAR(30),
    @RefID              INT           = NULL,
    @RefExpression      NVARCHAR(400) = NULL,
    @InitiatorUserID    INT           = NULL,
    @EntityOwnerUserID  INT           = NULL,
    @EntityUnitID       INT           = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Res TABLE (UserID INT PRIMARY KEY);

    IF @AssigneeType = N'USER' AND @RefID IS NOT NULL
        INSERT INTO @Res (UserID) SELECT @RefID;

    ELSE IF @AssigneeType = N'INITIATOR' AND @InitiatorUserID IS NOT NULL
        INSERT INTO @Res (UserID) SELECT @InitiatorUserID;

    ELSE IF @AssigneeType = N'ENTITY_OWNER' AND @EntityOwnerUserID IS NOT NULL
        INSERT INTO @Res (UserID) SELECT @EntityOwnerUserID;

    ELSE IF @AssigneeType = N'ROLE' AND @RefID IS NOT NULL
        INSERT INTO @Res (UserID)
        SELECT DISTINCT ur.UserID FROM dbo.UserRoles ur WHERE ur.RoleID = @RefID AND ur.IsActive = 1;

    ELSE IF @AssigneeType = N'POSITION' AND @RefID IS NOT NULL
        INSERT INTO @Res (UserID)
        SELECT DISTINCT up.UserID FROM dbo.UserPositions up WHERE up.PositionID = @RefID AND up.IsActive = 1;

    ELSE IF @AssigneeType = N'UNIT' AND @RefID IS NOT NULL
        INSERT INTO @Res (UserID)
        SELECT DISTINCT up.UserID FROM dbo.UserPositions up WHERE up.UnitID = @RefID AND up.IsActive = 1;

    ELSE IF @AssigneeType = N'UNIT_MANAGER'
    BEGIN
        DECLARE @UnitForMgr INT = ISNULL(@RefID, @EntityUnitID);
        IF @UnitForMgr IS NOT NULL
            INSERT INTO @Res (UserID)
            SELECT DISTINCT up.UserID
            FROM dbo.UserPositions up
            JOIN dbo.Positions p ON p.PositionID = up.PositionID
            WHERE up.UnitID = @UnitForMgr AND up.IsActive = 1 AND p.IsUnitManager = 1 AND p.IsActive = 1;
    END

    ELSE IF @AssigneeType = N'DIRECT_MANAGER' AND @InitiatorUserID IS NOT NULL
    BEGIN
        -- مدیرِ واحدِ ثبت‌کننده (آخرین سمتِ فعالِ او)
        DECLARE @InitUnit INT;
        SELECT TOP 1 @InitUnit = up.UnitID FROM dbo.UserPositions up
        WHERE up.UserID = @InitiatorUserID AND up.IsActive = 1
        ORDER BY up.CreateDate DESC, up.UserPositionID DESC;

        IF @InitUnit IS NOT NULL
            INSERT INTO @Res (UserID)
            SELECT DISTINCT up.UserID
            FROM dbo.UserPositions up
            JOIN dbo.Positions p ON p.PositionID = up.PositionID
            WHERE up.UnitID = @InitUnit AND up.IsActive = 1 AND p.IsUnitManager = 1 AND p.IsActive = 1
              AND up.UserID <> @InitiatorUserID;
    END

    SELECT DISTINCT r.UserID, u.FullName
    FROM @Res r
    JOIN dbo.Users u ON u.UserID = r.UserID
    WHERE u.IsActive = 1 AND u.IsLocked = 0;
END
GO


/* =====================================================================
   ۵) Runtime — Instance / StepInstance / Task  (بدونِ تراکنشِ داخلی)
   ===================================================================== */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_HasActiveInstance
    @DefinitionID INT,
    @EntityType   NVARCHAR(64),
    @EntityID     BIGINT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TOP 1 InstanceID, InstanceNumber
    FROM dbo.WorkflowInstances
    WHERE DefinitionID = @DefinitionID AND EntityType = @EntityType AND EntityID = @EntityID AND Status = N'RUNNING'
    ORDER BY InstanceID DESC;
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_Wf_StartInstance
    @DefinitionID     INT,
    @VersionID        INT,
    @EntityType       NVARCHAR(64),
    @EntityID         BIGINT,
    @StartedByUserID  INT           = NULL,
    @InstanceNumber   NVARCHAR(50)
AS
BEGIN
    SET NOCOUNT ON;

    INSERT INTO dbo.WorkflowInstances
        (InstanceNumber, DefinitionID, VersionID, EntityType, EntityID, Status, StartedByUserID, StartedAt, Date_InsertFirst, UserID_InsertFirst)
    VALUES
        (@InstanceNumber, @DefinitionID, @VersionID, @EntityType, @EntityID, N'RUNNING', @StartedByUserID, SYSDATETIME(), SYSDATETIME(), @StartedByUserID);

    SELECT 1 AS Success, N'Instance ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS BIGINT) AS InstanceID;
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_Wf_BumpTransitionCount
    @InstanceID BIGINT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @New INT;
    UPDATE dbo.WorkflowInstances SET @New = TransitionCount = TransitionCount + 1 WHERE InstanceID = @InstanceID;
    SELECT @New AS TransitionCount;
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_Wf_SetInstanceStatus
    @InstanceID BIGINT,
    @Status     NVARCHAR(20),
    @UserID     INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE dbo.WorkflowInstances
    SET Status = @Status,
        CompletedAt = CASE WHEN @Status IN (N'COMPLETED', N'CANCELLED') THEN SYSDATETIME() ELSE CompletedAt END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE InstanceID = @InstanceID;
    SELECT 1 AS Success, N'وضعیت Instance به‌روزرسانی شد.' AS Message;
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_Wf_InsertStepInstance
    @InstanceID             BIGINT,
    @StepID                 INT,
    @StepCode               NVARCHAR(64),
    @StepType               NVARCHAR(30),
    @EnteredViaTransitionID INT = NULL,
    @IterationNo            INT = 1,
    @UserID                 INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    INSERT INTO dbo.WorkflowStepInstances
        (InstanceID, StepID, StepCode, StepType, Status, EnteredViaTransitionID, EnteredAt, IterationNo, Date_InsertFirst, UserID_InsertFirst)
    VALUES
        (@InstanceID, @StepID, @StepCode, @StepType, N'ACTIVE', @EnteredViaTransitionID, SYSDATETIME(), @IterationNo, SYSDATETIME(), @UserID);
    SELECT 1 AS Success, N'StepInstance ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS BIGINT) AS StepInstanceID;
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_Wf_CompleteStepInstance
    @StepInstanceID      BIGINT,
    @Status              NVARCHAR(20),   -- COMPLETED | SKIPPED | FAILED
    @OutcomeActionCode   NVARCHAR(64) = NULL,
    @OutcomeTransitionID INT = NULL,
    @UserID              INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE dbo.WorkflowStepInstances
    SET Status = @Status, CompletedAt = SYSDATETIME(),
        OutcomeActionCode = @OutcomeActionCode, OutcomeTransitionID = @OutcomeTransitionID,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE StepInstanceID = @StepInstanceID AND Status = N'ACTIVE';

    IF @@ROWCOUNT = 0
        SELECT 0 AS Success, N'این مرحله دیگر فعال نیست (شاید هم‌زمان بسته شده باشد).' AS Message;
    ELSE
        SELECT 1 AS Success, N'مرحله بسته شد.' AS Message;
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_Wf_CountStepIterations
    @InstanceID BIGINT,
    @StepID     INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT COUNT(*) AS Iterations FROM dbo.WorkflowStepInstances WHERE InstanceID = @InstanceID AND StepID = @StepID;
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_Wf_InsertTask
    @InstanceID          BIGINT,
    @StepInstanceID      BIGINT,
    @StepID              INT,
    @DefinitionID        INT,
    @EntityType          NVARCHAR(64),
    @EntityID            BIGINT,
    @Title               NVARCHAR(300),
    @Description         NVARCHAR(MAX) = NULL,
    @PriorityID          INT = NULL,
    @AssignPolicy        NVARCHAR(10) = N'ANY',
    @RequiredApprovals   INT = NULL,
    @TaskNumber          NVARCHAR(50),
    @ParentTaskID        BIGINT = NULL,
    @ForwardedFromUserID INT = NULL,
    @UserID              INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    INSERT INTO dbo.WorkflowTasks
        (TaskNumber, InstanceID, StepInstanceID, StepID, DefinitionID, EntityType, EntityID, Title, Description,
         Status, PriorityID, AssignPolicy, RequiredApprovals, CreatedAt, ParentTaskID, ForwardedFromUserID,
         Date_InsertFirst, UserID_InsertFirst)
    VALUES
        (@TaskNumber, @InstanceID, @StepInstanceID, @StepID, @DefinitionID, @EntityType, @EntityID, @Title, @Description,
         N'PENDING', @PriorityID, ISNULL(@AssignPolicy, N'ANY'), @RequiredApprovals, SYSDATETIME(), @ParentTaskID, @ForwardedFromUserID,
         SYSDATETIME(), @UserID);
    SELECT 1 AS Success, N'تسک ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS BIGINT) AS TaskID;
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_Wf_InsertTaskAssignee
    @TaskID      BIGINT,
    @UserID      INT,
    @SourceType  NVARCHAR(30),
    @SourceRefID INT = NULL,
    @ActorUserID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @UserID AND IsActive = 1)
    BEGIN SELECT 0 AS Success, N'کاربر نامعتبر یا غیرفعال است.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.WorkflowTaskAssignees WHERE TaskID = @TaskID AND UserID = @UserID AND IsActive = 1)
    BEGIN SELECT 1 AS Success, N'کاربر از قبل انجام‌دهنده‌ی این تسک است.' AS Message; RETURN; END

    INSERT INTO dbo.WorkflowTaskAssignees (TaskID, UserID, SourceType, SourceRefID, IsActive, Date_InsertFirst, UserID_InsertFirst)
    VALUES (@TaskID, @UserID, @SourceType, @SourceRefID, 1, SYSDATETIME(), @ActorUserID);

    SELECT 1 AS Success, N'انجام‌دهنده افزوده شد.' AS Message, CAST(SCOPE_IDENTITY() AS BIGINT) AS TaskAssigneeID;
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_Wf_MarkTaskFirstOpened
    @TaskID BIGINT,
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE dbo.WorkflowTasks
    SET FirstOpenedAt = SYSDATETIME(), Status = N'IN_PROGRESS', Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE TaskID = @TaskID AND Status = N'PENDING';
    SELECT CASE WHEN @@ROWCOUNT > 0 THEN 1 ELSE 0 END AS Changed;
END
GO

/* ثبتِ تصمیمِ یک انجام‌دهنده (idempotent: فقط اگر Decision خالی باشد) + شمارنده‌ی تسک */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_SetTaskAssigneeDecision
    @TaskID     BIGINT,
    @UserID     INT,
    @Decision   NVARCHAR(20),   -- APPROVED | REJECTED | RETURNED
    @ActionCode NVARCHAR(64),
    @Comment    NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    UPDATE dbo.WorkflowTaskAssignees
    SET Decision = @Decision, ActionCode = @ActionCode, ActedAt = SYSDATETIME(), Comment = @Comment,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE TaskID = @TaskID AND UserID = @UserID AND IsActive = 1 AND Decision IS NULL;

    IF @@ROWCOUNT = 0
    BEGIN SELECT 0 AS Success, N'شما قبلاً روی این تسک اقدام کرده‌اید یا انجام‌دهنده‌ی آن نیستید.' AS Message; RETURN; END

    UPDATE dbo.WorkflowTasks
    SET ReceivedApprovals  = ReceivedApprovals  + CASE WHEN @Decision = N'APPROVED' THEN 1 ELSE 0 END,
        ReceivedRejections = ReceivedRejections + CASE WHEN @Decision = N'REJECTED' THEN 1 ELSE 0 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE TaskID = @TaskID;

    SELECT 1 AS Success, N'تصمیم ثبت شد.' AS Message,
           t.AssignPolicy, t.RequiredApprovals, t.ReceivedApprovals, t.ReceivedRejections,
           (SELECT COUNT(*) FROM dbo.WorkflowTaskAssignees a WHERE a.TaskID = @TaskID AND a.IsActive = 1) AS ActiveAssigneeCount,
           (SELECT COUNT(*) FROM dbo.WorkflowTaskAssignees a WHERE a.TaskID = @TaskID AND a.IsActive = 1 AND a.Decision IS NOT NULL) AS ActedCount
    FROM dbo.WorkflowTasks t WHERE t.TaskID = @TaskID;
END
GO

/* بستنِ تسک — کنترلِ Concurrency با RowVersion + Status.
   @ExpectedRowVersion به‌صورتِ رشتهٔ hex با پیشوندِ 0x دریافت می‌شود
   (سازگاری با درایورهای PDO که BINARY را مستقیم bind نمی‌کنند) و با style=1
   به BINARY(8) تبدیل می‌گردد. NULL/خالی → ناسازگاری. */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_CompleteTask
    @TaskID              BIGINT,
    @ExpectedRowVersion  NVARCHAR(34),
    @NewStatus           NVARCHAR(20),   -- COMPLETED | REJECTED | RETURNED
    @ActorUserID         INT,
    @ActionCode          NVARCHAR(64)
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @rv BINARY(8) = NULL;
    BEGIN TRY
        IF @ExpectedRowVersion IS NOT NULL AND LEN(@ExpectedRowVersion) > 0
            SET @rv = CONVERT(BINARY(8), @ExpectedRowVersion, 1);
    END TRY
    BEGIN CATCH
        SET @rv = NULL;
    END CATCH

    UPDATE dbo.WorkflowTasks
    SET Status = @NewStatus, CompletedAt = SYSDATETIME(), CompletedByUserID = @ActorUserID,
        CompletionActionCode = @ActionCode, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @ActorUserID
    WHERE TaskID = @TaskID
      AND @rv IS NOT NULL
      AND RowVersion = @rv
      AND Status IN (N'PENDING', N'IN_PROGRESS');

    IF @@ROWCOUNT = 0
    BEGIN
        DECLARE @cur NVARCHAR(20) = (SELECT Status FROM dbo.WorkflowTasks WHERE TaskID = @TaskID);
        SELECT 0 AS Success,
               CASE WHEN @cur IS NULL THEN N'تسک یافت نشد.'
                    WHEN @cur NOT IN (N'PENDING', N'IN_PROGRESS') THEN N'این تسک قبلاً بسته شده است.'
                    ELSE N'تسک هم‌زمان توسط کاربر دیگری تغییر کرده است. صفحه را تازه کنید.' END AS Message,
               ISNULL(@cur, N'') AS CurrentStatus, CAST(1 AS BIT) AS Concurrency;
        RETURN;
    END

    SELECT 1 AS Success, N'تسک بسته شد.' AS Message;
END
GO


/* =====================================================================
   ۶) History / Audit
   ===================================================================== */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_InsertHistory
    @EntityType     NVARCHAR(64),
    @EntityID       BIGINT,
    @EventCode      NVARCHAR(50),
    @InstanceID     BIGINT = NULL,
    @StepInstanceID BIGINT = NULL,
    @TaskID         BIGINT = NULL,
    @ActorUserID    INT = NULL,
    @ActorType      NVARCHAR(10) = N'USER',
    @Summary        NVARCHAR(500) = NULL,
    @OldValueJson   NVARCHAR(MAX) = NULL,
    @NewValueJson   NVARCHAR(MAX) = NULL,
    @DetailJson     NVARCHAR(MAX) = NULL,
    @IpAddress      NVARCHAR(45) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    INSERT INTO dbo.WorkflowHistory
        (InstanceID, StepInstanceID, TaskID, EntityType, EntityID, EventCode, ActorUserID, ActorType, OccurredAt,
         Summary, OldValueJson, NewValueJson, DetailJson, IpAddress)
    VALUES
        (@InstanceID, @StepInstanceID, @TaskID, @EntityType, @EntityID, @EventCode, @ActorUserID, ISNULL(@ActorType, N'USER'), SYSDATETIME(),
         @Summary, @OldValueJson, @NewValueJson, @DetailJson, @IpAddress);
    SELECT 1 AS Success, CAST(SCOPE_IDENTITY() AS BIGINT) AS HistoryID;
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetInstanceHistory
    @InstanceID BIGINT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT h.HistoryID, h.EventCode, h.ActorType, h.OccurredAt, h.Summary, h.DetailJson,
           h.TaskID, h.StepInstanceID, h.ActorUserID, u.FullName AS ActorName
    FROM dbo.WorkflowHistory h
    LEFT JOIN dbo.Users u ON u.UserID = h.ActorUserID
    WHERE h.InstanceID = @InstanceID
    ORDER BY h.OccurredAt, h.HistoryID;
END
GO


/* =====================================================================
   ۷) Query — Instance / My Tasks / Task Detail
   ===================================================================== */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetInstance
    @InstanceID BIGINT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT i.InstanceID, i.InstanceNumber, i.DefinitionID, i.VersionID, i.EntityType, i.EntityID, i.Status,
           i.StartedByUserID, su.FullName AS StartedByName, i.StartedAt, i.CompletedAt, i.TransitionCount,
           d.Code AS DefinitionCode, d.Name AS DefinitionName, v.VersionNo
    FROM dbo.WorkflowInstances i
    JOIN dbo.WorkflowDefinitions d ON d.DefinitionID = i.DefinitionID
    JOIN dbo.WorkflowVersions v ON v.VersionID = i.VersionID
    LEFT JOIN dbo.Users su ON su.UserID = i.StartedByUserID
    WHERE i.InstanceID = @InstanceID;

    -- Step instances
    SELECT si.StepInstanceID, si.StepCode, si.StepType, si.Status, si.EnteredAt, si.CompletedAt,
           si.OutcomeActionCode, si.IterationNo
    FROM dbo.WorkflowStepInstances si
    WHERE si.InstanceID = @InstanceID
    ORDER BY si.StepInstanceID;

    -- Open tasks
    SELECT t.TaskID, t.TaskNumber, t.Title, t.Status, t.CreatedAt,
           s.Name AS StepName,
           (SELECT STRING_AGG(u.FullName, N'، ') FROM dbo.WorkflowTaskAssignees a JOIN dbo.Users u ON u.UserID = a.UserID
             WHERE a.TaskID = t.TaskID AND a.IsActive = 1) AS AssigneeNames
    FROM dbo.WorkflowTasks t
    JOIN dbo.WorkflowSteps s ON s.StepID = t.StepID
    WHERE t.InstanceID = @InstanceID
    ORDER BY t.TaskID;
END
GO

/* «تسک‌های من» — کاربری که انجام‌دهنده‌ی فعال است (یا @AllTasks=1 برای دارندگانِ WORKFLOW_VIEW_ALL_TASKS) */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetMyTasks
    @UserID   INT,
    @Status   NVARCHAR(20) = NULL,   -- NULL = همه‌ی بازها (PENDING+IN_PROGRESS)
    @AllTasks BIT           = 0,
    @Page     INT           = 1,
    @PageSize INT           = 20
AS
BEGIN
    SET NOCOUNT ON;
    IF @Page < 1 SET @Page = 1;
    IF @PageSize < 1 OR @PageSize > 200 SET @PageSize = 20;

    ;WITH MyTask AS (
        SELECT t.TaskID
        FROM dbo.WorkflowTasks t
        WHERE (@AllTasks = 1
               OR EXISTS (SELECT 1 FROM dbo.WorkflowTaskAssignees a
                          WHERE a.TaskID = t.TaskID AND a.UserID = @UserID AND a.IsActive = 1))
    )
    SELECT
        t.TaskID, t.TaskNumber, t.Title, t.Status, t.PriorityID, mp.Name AS PriorityName,
        t.EntityType, t.EntityID, t.CreatedAt, t.FirstOpenedAt, t.CompletedAt,
        d.Name AS DefinitionName, s.Name AS StepName,
        i.InstanceID, i.InstanceNumber,
        COUNT(*) OVER () AS TotalCount
    FROM MyTask mt
    JOIN dbo.WorkflowTasks t ON t.TaskID = mt.TaskID
    JOIN dbo.WorkflowInstances i ON i.InstanceID = t.InstanceID
    JOIN dbo.WorkflowDefinitions d ON d.DefinitionID = t.DefinitionID
    JOIN dbo.WorkflowSteps s ON s.StepID = t.StepID
    LEFT JOIN dbo.msgPriorities mp ON mp.msgPriorityID = t.PriorityID
    WHERE (
            (@Status IS NULL AND t.Status IN (N'PENDING', N'IN_PROGRESS'))
            OR (@Status IS NOT NULL AND t.Status = @Status)
          )
    ORDER BY
        CASE t.Status WHEN N'PENDING' THEN 0 WHEN N'IN_PROGRESS' THEN 1 ELSE 2 END,
        t.CreatedAt DESC
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;

    -- شمارنده‌ها برای تب‌ها
    SELECT
        SUM(CASE WHEN t.Status = N'PENDING'      THEN 1 ELSE 0 END) AS PendingCount,
        SUM(CASE WHEN t.Status = N'IN_PROGRESS'  THEN 1 ELSE 0 END) AS InProgressCount,
        SUM(CASE WHEN t.Status IN (N'COMPLETED', N'REJECTED', N'RETURNED', N'FORWARDED') THEN 1 ELSE 0 END) AS DoneCount
    FROM dbo.WorkflowTasks t
    WHERE (@AllTasks = 1
           OR EXISTS (SELECT 1 FROM dbo.WorkflowTaskAssignees a
                      WHERE a.TaskID = t.TaskID AND a.UserID = @UserID AND a.IsActive = 1));
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetTaskDetail
    @TaskID BIGINT
AS
BEGIN
    SET NOCOUNT ON;

    -- تسک + مرحله + Instance + فرایند
    SELECT
        t.TaskID, t.TaskNumber, t.Title, t.Description, t.Status, t.AssignPolicy, t.RequiredApprovals,
        t.ReceivedApprovals, t.ReceivedRejections, t.PriorityID, mp.Name AS PriorityName,
        t.EntityType, t.EntityID, t.CreatedAt, t.FirstOpenedAt, t.CompletedAt, t.CompletedByUserID,
        cbu.FullName AS CompletedByName, t.CompletionActionCode, t.RowVersion,
        t.ParentTaskID, t.ForwardedFromUserID, t.ForwardedToUserID, t.IsDelegated, t.DelegatedToUserID,
        i.InstanceID, i.InstanceNumber, i.Status AS InstanceStatus, i.VersionID, i.StartedByUserID, isu.FullName AS StartedByName,
        d.DefinitionID, d.Code AS DefinitionCode, d.Name AS DefinitionName,
        s.StepID, s.Code AS StepCode, s.Name AS StepName, s.StepType, s.AllowForward, s.AllowDelegation,
        si.StepInstanceID
    FROM dbo.WorkflowTasks t
    JOIN dbo.WorkflowInstances i ON i.InstanceID = t.InstanceID
    JOIN dbo.WorkflowDefinitions d ON d.DefinitionID = t.DefinitionID
    JOIN dbo.WorkflowSteps s ON s.StepID = t.StepID
    JOIN dbo.WorkflowStepInstances si ON si.StepInstanceID = t.StepInstanceID
    LEFT JOIN dbo.msgPriorities mp ON mp.msgPriorityID = t.PriorityID
    LEFT JOIN dbo.Users cbu ON cbu.UserID = t.CompletedByUserID
    LEFT JOIN dbo.Users isu ON isu.UserID = i.StartedByUserID
    WHERE t.TaskID = @TaskID;

    -- انجام‌دهنده‌ها
    SELECT a.TaskAssigneeID, a.UserID, u.FullName, a.SourceType, a.SourceRefID, a.IsActive,
           a.Decision, a.ActionCode, a.ActedAt, a.Comment
    FROM dbo.WorkflowTaskAssignees a
    JOIN dbo.Users u ON u.UserID = a.UserID
    WHERE a.TaskID = @TaskID
    ORDER BY a.IsActive DESC, a.TaskAssigneeID;

    -- Actionهای قابل انجام روی این مرحله
    SELECT act.ActionID, act.Code, act.Kind, act.Label, act.Icon, act.Style,
           act.RequiresComment, act.RequiresConfirm, act.ConfirmMessage, act.PermissionCode, act.SortOrder
    FROM dbo.WorkflowStepActions act
    JOIN dbo.WorkflowTasks t ON t.StepID = act.StepID
    WHERE t.TaskID = @TaskID
    ORDER BY act.SortOrder, act.ActionID;

    -- تاریخچه‌ی مرتبط با این تسک
    SELECT h.HistoryID, h.EventCode, h.ActorType, h.OccurredAt, h.Summary, h.ActorUserID, u.FullName AS ActorName
    FROM dbo.WorkflowHistory h
    LEFT JOIN dbo.Users u ON u.UserID = h.ActorUserID
    WHERE h.TaskID = @TaskID
    ORDER BY h.OccurredAt, h.HistoryID;
END
GO


/* =====================================================================
   ۸) Business Calendar
   ===================================================================== */
CREATE OR ALTER PROCEDURE dbo.sp_BusinessCalendar_GetList
AS
BEGIN
    SET NOCOUNT ON;
    SELECT CalendarID, Code, Name, TimeZone, IsDefault, IsActive, Date_InsertFirst
    FROM dbo.BusinessCalendars
    ORDER BY IsDefault DESC, Name;
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_BusinessCalendar_GetDefinition
    @Code     NVARCHAR(32) = NULL,   -- NULL → تقویمِ پیش‌فرض
    @FromDate DATE = NULL,
    @ToDate   DATE = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @CalId INT;
    IF @Code IS NULL
        SELECT @CalId = CalendarID FROM dbo.BusinessCalendars WHERE IsDefault = 1;
    ELSE
        SELECT @CalId = CalendarID FROM dbo.BusinessCalendars WHERE Code = @Code;

    -- تقویم
    SELECT CalendarID, Code, Name, TimeZone, IsDefault, IsActive
    FROM dbo.BusinessCalendars WHERE CalendarID = @CalId;

    -- ساعاتِ کاری
    SELECT WorkingHourID, DayOfWeek, IsWorkingDay, StartTime, EndTime, SortOrder
    FROM dbo.BusinessCalendarWorkingHours
    WHERE CalendarID = @CalId
    ORDER BY DayOfWeek, SortOrder;

    -- استثناها (در بازه، در صورت داده‌شدن)
    SELECT ExceptionID, ExceptionDate, ExceptionType, Title, OverrideStartTime, OverrideEndTime
    FROM dbo.BusinessCalendarExceptions
    WHERE CalendarID = @CalId
      AND (@FromDate IS NULL OR ExceptionDate >= @FromDate)
      AND (@ToDate   IS NULL OR ExceptionDate <= @ToDate)
    ORDER BY ExceptionDate;
END
GO

/* ==================== پایان پچ 015 ==================== */
