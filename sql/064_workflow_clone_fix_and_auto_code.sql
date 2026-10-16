/* ==========================================================================
   پچ خودکار شماره: 064 | نام: workflow_clone_fix_and_auto_code
   تاریخ: 2026-09-27 18:44:00 | شامل 2 دستور SQL
   ========================================================================== */

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_CloneVersion
ALTER PROCEDURE dbo.sp_Wf_CloneVersion
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
        INSERT (VersionID, Code, Name, StepType, AssignPolicy, RequiredApprovals, AllowForward, ForwardMax, AllowDelegation, SortOrder,
                PositionX, PositionY, Description, DueDurationHours, Date_InsertFirst, UserID_InsertFirst)
        VALUES (@NewVersionID, S.Code, S.Name, S.StepType, S.AssignPolicy, S.RequiredApprovals, S.AllowForward, S.ForwardMax, S.AllowDelegation, S.SortOrder,
                S.PositionX, S.PositionY, S.Description, S.DueDurationHours, SYSDATETIME(), @UserID)
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
    INSERT INTO dbo.WorkflowStepAssignments (VersionID, StepID, AssigneeType, RefID, RefExpression, SortOrder, IsBackup, Date_InsertFirst, UserID_InsertFirst)
    SELECT @NewVersionID, m.NewStepID, a.AssigneeType, a.RefID, a.RefExpression, a.SortOrder, a.IsBackup, SYSDATETIME(), @UserID
    FROM dbo.WorkflowStepAssignments a
    JOIN @Map m ON m.OldStepID = a.StepID
    WHERE a.VersionID = @SourceVersionID;

    -- Transitions  (با نگاشت Step و Action)
    INSERT INTO dbo.WorkflowTransitions (VersionID, Code, FromStepID, ToStepID, TriggerActionID, Priority, IsDefault, Label, ConditionExpression, RuleJson, Date_InsertFirst, UserID_InsertFirst)
    SELECT @NewVersionID, tr.Code, fm.NewStepID, tm.NewStepID,
           am.NewActionID, tr.Priority, tr.IsDefault, tr.Label, tr.ConditionExpression, tr.RuleJson, SYSDATETIME(), @UserID
    FROM dbo.WorkflowTransitions tr
    JOIN @Map fm ON fm.OldStepID = tr.FromStepID
    JOIN @Map tm ON tm.OldStepID = tr.ToStepID
    LEFT JOIN @AMap am ON am.OldActionID = tr.TriggerActionID
    WHERE tr.VersionID = @SourceVersionID;

    SELECT 1 AS Success, N'نسخه‌ی جدید از روی نسخه‌ی قبلی ساخته شد.' AS Message, @NewVersionID AS VersionID, @No AS VersionNo;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_SaveDefinition
ALTER PROCEDURE dbo.sp_Wf_SaveDefinition
    @DefinitionID INT           = NULL,   -- NULL = ایجاد
    @Name         NVARCHAR(200),
    @Description  NVARCHAR(1000) = NULL,
    @EntityType   NVARCHAR(64),
    @IsActive     BIT           = 1,
    @CategoryID   INT           = NULL,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@Name)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نام فرایند الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@EntityType)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نوع موجودیت (EntityType) الزامی است.' AS Message; RETURN; END

    IF @CategoryID IS NOT NULL
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowCategories WHERE CategoryID = @CategoryID)
        BEGIN SELECT 0 AS Success, N'دستهٔ انتخاب‌شده یافت نشد.' AS Message; RETURN; END

        DECLARE @CatActive BIT, @PrevCategoryID INT = NULL;
        SELECT @CatActive = IsActive FROM dbo.WorkflowCategories WHERE CategoryID = @CategoryID;
        IF @DefinitionID IS NOT NULL
            SELECT @PrevCategoryID = CategoryID FROM dbo.WorkflowDefinitions WHERE DefinitionID = @DefinitionID;

        IF @CatActive = 0 AND (@PrevCategoryID IS NULL OR @PrevCategoryID <> @CategoryID)
        BEGIN SELECT 0 AS Success, N'این دسته غیرفعال است و برایِ انتخابِ جدید قابلِ استفاده نیست.' AS Message; RETURN; END
    END

    IF @DefinitionID IS NULL
    BEGIN
        BEGIN TRY
            BEGIN TRAN;

            -- تولیدِ خودکارِ Code به‌فرمِ WF{101+}، با قفلِ UPDLOCK/HOLDLOCK رویِ محاسبهٔ
            -- بیشترین شماره تا پایانِ تراکنش — تا زیرِ فشارِ همزمانی هم Duplicate ممکن نباشد.
            -- Codeهایِ قدیمیِ غیرِ WF### در این محاسبه نادیده گرفته می‌شوند و دست‌نخورده می‌مانند.
            DECLARE @NextNum INT;
            SELECT @NextNum = ISNULL(MAX(TRY_CAST(SUBSTRING(Code, 3, LEN(Code) - 2) AS INT)), 100) + 1
            FROM dbo.WorkflowDefinitions WITH (UPDLOCK, HOLDLOCK)
            WHERE Code LIKE N'WF%' AND TRY_CAST(SUBSTRING(Code, 3, LEN(Code) - 2) AS INT) IS NOT NULL;

            IF @NextNum < 101 SET @NextNum = 101;

            DECLARE @NewCode NVARCHAR(64) = N'WF' + CAST(@NextNum AS NVARCHAR(20));

            INSERT INTO dbo.WorkflowDefinitions (Code, Name, Description, EntityType, IsActive, CategoryID, Date_InsertFirst, UserID_InsertFirst)
            VALUES (@NewCode, @Name, @Description, @EntityType, @IsActive, @CategoryID, SYSDATETIME(), @UserID);

            DECLARE @NewID INT = CAST(SCOPE_IDENTITY() AS INT);

            COMMIT TRAN;

            SELECT 1 AS Success, N'فرایند ایجاد شد.' AS Message, @NewID AS DefinitionID, @NewCode AS Code;
        END TRY
        BEGIN CATCH
            IF @@TRANCOUNT > 0 ROLLBACK TRAN;
            SELECT 0 AS Success, N'خطا در ذخیره: ' + ERROR_MESSAGE() AS Message;
        END CATCH
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowDefinitions WHERE DefinitionID = @DefinitionID)
        BEGIN SELECT 0 AS Success, N'فرایند یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.WorkflowDefinitions
        SET Name = @Name, Description = @Description, EntityType = @EntityType, IsActive = @IsActive,
            CategoryID = @CategoryID, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE DefinitionID = @DefinitionID;
        -- Code عمداً دست‌نخورده می‌ماند — هرگز پس از ایجاد تغییر نمی‌کند.

        SELECT 1 AS Success, N'فرایند به‌روزرسانی شد.' AS Message, @DefinitionID AS DefinitionID,
               (SELECT Code FROM dbo.WorkflowDefinitions WHERE DefinitionID = @DefinitionID) AS Code;
    END
END
GO

