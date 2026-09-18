/* ==========================================================================
   پچ دستی شماره: 034 | نام: fix_test_simple_missing_task_action
   این پچ فقط DML است (WorkflowVersions/Steps/Actions/Assignments/Transitions)
   — DDL-Trigger چیزی برایِ آن ثبت نمی‌کند، بنابراین دستی نوشته شده.

   ریشهٔ مشکل: نسخهٔ Activeِ فرایندِ «TEST_SIMPLE» (ساخته‌شده در دورهایِ قبلیِ
   توسعه/تست) هیچ ردیفی در WorkflowStepActions نداشت — یعنی هیچ Actionِ قابلِ‌
   کلیک برایِ تکمیلِ تسکِ «بررسی نامه» تعریف نشده بود، پس اجرایِ واقعیِ Workflow
   (Task → Approve → Transition → END → COMPLETED) اصلاً ممکن نبود.

   این پچ دقیقاً همان زنجیرهٔ استانداردِ Process Designer را از طریقِ خودِ
   SPهایِ رسمی طی می‌کند (نه UPDATE/INSERTِ خام رویِ جداول): شبیه‌سازیِ نسخهٔ
   فعلی (sp_Wf_CloneVersion) → افزودنِ Actionِ ازقلم‌افتاده و ذخیرهٔ کاملِ گراف
   (sp_Wf_SaveVersionGraph) → انتشار (sp_Wf_PublishVersion؛ نسخهٔ قبلی خودکار
   Archive می‌شود). Assignment (DIRECT_MANAGER) و Stepها/Transitionها بدونِ
   تغییرِ معنایی دوباره ذخیره می‌شوند؛ فقط یک Actionِ جدید افزوده شده است.

   Idempotent: اگر نسخهٔ Activeِ فعلی از قبل حداقل یک Action داشته باشد
   (یعنی این پچ قبلاً اجرا شده یا مشکل به‌شکلِ دیگری برطرف شده)، هیچ کاری
   انجام نمی‌دهد.
   ========================================================================== */

DECLARE @DefinitionID INT, @ActiveVersionID INT, @ActionCount INT;

SELECT @DefinitionID = DefinitionID FROM dbo.WorkflowDefinitions WHERE Code = N'TEST_SIMPLE';

IF @DefinitionID IS NOT NULL
BEGIN
    SELECT @ActiveVersionID = VersionID FROM dbo.WorkflowVersions WHERE DefinitionID = @DefinitionID AND Status = N'ACTIVE';

    IF @ActiveVersionID IS NOT NULL
    BEGIN
        SELECT @ActionCount = COUNT(*)
        FROM dbo.WorkflowStepActions a
        JOIN dbo.WorkflowSteps s ON s.StepID = a.StepID
        WHERE s.VersionID = @ActiveVersionID;

        IF @ActionCount = 0
        BEGIN
            DECLARE @CloneResult TABLE (Success BIT, Message NVARCHAR(500), VersionID INT, VersionNo INT);
            INSERT INTO @CloneResult (Success, Message, VersionID, VersionNo)
            EXEC dbo.sp_Wf_CloneVersion @SourceVersionID = @ActiveVersionID, @UserID = 2;

            DECLARE @NewVersionID INT = (SELECT TOP 1 VersionID FROM @CloneResult WHERE Success = 1);

            IF @NewVersionID IS NOT NULL
            BEGIN
                EXEC dbo.sp_Wf_SaveVersionGraph
                    @VersionID = @NewVersionID,
                    @StepsJson = N'[
                        {"code":"START","name":"شروع","stepType":"START","assignPolicy":"ANY","sortOrder":0},
                        {"code":"TASK_1","name":"بررسی نامه","stepType":"USER_TASK","assignPolicy":"ANY","sortOrder":1},
                        {"code":"END","name":"پایان","stepType":"END","assignPolicy":"ANY","sortOrder":2}
                    ]',
                    @ActionsJson = N'[
                        {"stepCode":"TASK_1","code":"COMPLETE","kind":"COMPLETE","label":"تکمیل","sortOrder":0}
                    ]',
                    @AssignmentsJson = N'[
                        {"stepCode":"TASK_1","assigneeType":"DIRECT_MANAGER","sortOrder":0}
                    ]',
                    @TransitionsJson = N'[
                        {"code":"T1","fromStepCode":"START","toStepCode":"TASK_1","isDefault":true},
                        {"code":"T2","fromStepCode":"TASK_1","toStepCode":"END","triggerActionCode":"COMPLETE"}
                    ]',
                    @UserID = 2;

                EXEC dbo.sp_Wf_PublishVersion @VersionID = @NewVersionID, @UserID = 2;
            END
        END
    END
END
GO
