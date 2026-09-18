/* ==========================================================================
   پچ دستی شماره: 033 | نام: fix_leave_templates_definition_link
   این پچ فقط DML است (dbo.LetterTemplates) — DDL-Trigger چیزی برایِ آن ثبت
   نمی‌کند، بنابراین دستی نوشته شده.

   ریشهٔ مشکل: دو قالبِ نامهٔ مرخصی (Code=M101, M102) به‌اشتباه به
   DefinitionIDِ فرایندِ «TEST_SIMPLE» (یک فرایندِ صرفاً تستی، بدونِ Stepهایِ
   مرخصی) وصل شده بودند، درحالی‌که فرایندِ واقعیِ مرخصی با Code=M1 از قبل
   وجود دارد. نتیجه: در فرمِ ارسالِ پیامِ فرایندی، با انتخابِ TEST_SIMPLE،
   قالب‌هایِ مرخصی اشتباهاً نمایش داده می‌شدند.

   این پچ IDهایِ عددی را Hardcode نمی‌کند (چون DefinitionID/LetterTemplateID
   بینِ محیط‌هایِ مختلف می‌تواند متفاوت باشد) — بلکه از رویِ Codeِ یکتایِ هر دو
   جدول (WorkflowDefinitions.Code='M1' و LetterTemplates.Code IN ('M101','M102'))
   اصلاح را انجام می‌دهد. اجرایِ مجدد بی‌خطر است (Idempotent) و فقط ردیف‌هایی
   را تغییر می‌دهد که واقعاً DefinitionIDِ نادرست دارند.
   ========================================================================== */

UPDATE lt
SET lt.DefinitionID = wd.DefinitionID,
    lt.Date_LastUpdate = SYSDATETIME()
FROM dbo.LetterTemplates lt
JOIN dbo.WorkflowDefinitions wd ON wd.Code = N'M1'
WHERE lt.Code IN (N'M101', N'M102')
  AND (lt.DefinitionID IS NULL OR lt.DefinitionID <> wd.DefinitionID);
GO
