/* ==========================================================================
   پچ دستی شماره: 035 | نام: condition_task_demo_workflow
   این پچ فقط DML است (WorkflowDefinitions/ConditionFields/Versions/Steps/...)
   — DDL-Trigger چیزی برایِ آن ثبت نمی‌کند، بنابراین دستی نوشته شده.

   هدف: یک فرایندِ واقعی و کاملِ نمونه («تأییدِ مبلغ با شرط» / Code=COND_TASK_DEMO)
   برایِ آزمونِ End-to-Endِ زنجیرهٔ FORM Parameter → Context → Condition → Transition:
     START → TASK_1 (USER_TASK، DIRECT_MANAGER) → COND → END_HIGH / END_LOW
   Ruleِ COND یک شرطِ AND رویِ دو فیلدِ START_CONTEXT است: AMOUNT (DECIMAL) > 1000
   و REQUEST_DATE (DATE) >= 2026-01-01؛ در غیرِ این صورت مسیرِ پیش‌فرض END_LOW.

   دقیقاً همان زنجیرهٔ استانداردِ Process Designer را از طریقِ خودِ SPهایِ رسمی طی
   می‌کند (sp_Wf_SaveDefinition → sp_Wf_SaveConditionField → sp_Wf_CreateDraftVersion
   → sp_Wf_SaveVersionGraph → sp_Wf_PublishVersion)؛ هیچ INSERT/UPDATEِ خامی رویِ
   جداولِ Workflow انجام نمی‌شود.

   Idempotent: اگر Definitionای با Code=COND_TASK_DEMO از قبل وجود داشته باشد
   (یعنی این پچ قبلاً اجرا شده)، هیچ کاری انجام نمی‌دهد.
   ========================================================================== */

IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowDefinitions WHERE Code = N'COND_TASK_DEMO')
BEGIN
    DECLARE @DefResult TABLE (Success BIT, Message NVARCHAR(500), DefinitionID INT);
    INSERT INTO @DefResult (Success, Message, DefinitionID)
    EXEC dbo.sp_Wf_SaveDefinition
        @DefinitionID = NULL, @Code = N'COND_TASK_DEMO', @Name = N'تأییدِ مبلغ با شرط',
        @Description = NULL, @EntityType = N'MESSAGE', @IsActive = 1, @CategoryID = NULL, @UserID = 2;

    DECLARE @DefinitionID INT = (SELECT TOP 1 DefinitionID FROM @DefResult WHERE Success = 1);

    IF @DefinitionID IS NOT NULL
    BEGIN
        EXEC dbo.sp_Wf_SaveConditionField
            @FieldID = NULL, @DefinitionID = @DefinitionID, @Code = N'AMOUNT', @DisplayName = N'مبلغ',
            @DataType = N'DECIMAL', @SourceType = N'START_CONTEXT', @SourceKey = N'amount',
            @AllowedValuesJson = NULL, @SortOrder = 0, @UserID = 2;

        EXEC dbo.sp_Wf_SaveConditionField
            @FieldID = NULL, @DefinitionID = @DefinitionID, @Code = N'REQUEST_DATE', @DisplayName = N'تاریخِ درخواست',
            @DataType = N'DATE', @SourceType = N'START_CONTEXT', @SourceKey = N'requestDate',
            @AllowedValuesJson = NULL, @SortOrder = 1, @UserID = 2;

        DECLARE @VerResult TABLE (Success BIT, Message NVARCHAR(500), VersionID INT, VersionNo INT);
        INSERT INTO @VerResult (Success, Message, VersionID, VersionNo)
        EXEC dbo.sp_Wf_CreateDraftVersion @DefinitionID = @DefinitionID, @UserID = 2;

        DECLARE @VersionID INT = (SELECT TOP 1 VersionID FROM @VerResult WHERE Success = 1);

        IF @VersionID IS NOT NULL
        BEGIN
            EXEC dbo.sp_Wf_SaveVersionGraph
                @VersionID = @VersionID,
                @StepsJson = N'[
                    {"code":"START","name":"شروع","stepType":"START","assignPolicy":"ANY","sortOrder":0},
                    {"code":"TASK_1","name":"بررسیِ درخواست","stepType":"USER_TASK","assignPolicy":"ANY","sortOrder":1},
                    {"code":"COND","name":"ارزیابیِ شرط","stepType":"CONDITION","sortOrder":2},
                    {"code":"END_HIGH","name":"پایان — مسیرِ مبلغِ بالا","stepType":"END","sortOrder":3},
                    {"code":"END_LOW","name":"پایان — مسیرِ عادی","stepType":"END","sortOrder":4}
                ]',
                @ActionsJson = N'[
                    {"stepCode":"TASK_1","code":"COMPLETE","kind":"COMPLETE","label":"تکمیل","sortOrder":0}
                ]',
                @AssignmentsJson = N'[
                    {"stepCode":"TASK_1","assigneeType":"DIRECT_MANAGER","sortOrder":0}
                ]',
                @TransitionsJson = N'[
                    {"code":"T1","fromStepCode":"START","toStepCode":"TASK_1","isDefault":true},
                    {"code":"T2","fromStepCode":"TASK_1","toStepCode":"COND","triggerActionCode":"COMPLETE"},
                    {"code":"T_HIGH","fromStepCode":"COND","toStepCode":"END_HIGH","priority":10,"ruleJson":{"version":1,"type":"GROUP","logic":"AND","children":[{"type":"CONDITION","field":"AMOUNT","operator":"GT","value":{"kind":"CONSTANT","data":"1000"}},{"type":"CONDITION","field":"REQUEST_DATE","operator":"GTE","value":{"kind":"CONSTANT","data":"2026-01-01"}}]}},
                    {"code":"T_LOW","fromStepCode":"COND","toStepCode":"END_LOW","priority":999,"isDefault":true}
                ]',
                @UserID = 2;

            EXEC dbo.sp_Wf_PublishVersion @VersionID = @VersionID, @UserID = 2;
        END
    END
END
GO
