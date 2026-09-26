/* ==========================================================================
   پچ شماره: 027 | نام: condition_engine_reconcile
   این پچ برخلافِ بقیهٔ پچ‌های این پوشه به‌صورتِ دستی نوشته شده، نه با
   `php artisan db:make-patch` — به‌طورِ آگاهانه و طبقِ دستورِ صریحِ کاربر، پس از
   یک فورنزیکِ کاملِ Read-Only روی ۱۴ ردیفِ باقی‌ماندهٔ DevChangeLog (LogID
   3409-3422، همگی تاریخِ 2026-09-14، بعد از تولیدِ patch 026).

   نتیجهٔ فورنزیک: تمامِ ۱۴ ردیف یا (الف) دقیقاً با محتوایِ همینِ حاضر در
   sql/026_workflow_condition_engine.sql یکسان بودند، یا (ب) نسخه‌هایِ میانیِ
   قبلاً‌جایگزین‌شدهٔ sp_Wf_SaveConditionField در طولِ همان جلسهٔ اصلاح بودند
   (که با آخرین ALTER به همان بدنهٔ نهاییِ ۰۲۶ رسیدند)، یا (ج) یک Objectِ
   کاملاً Scratch (`sp_QIVerifyTemp`) که در همان جلسه Create→Alter→Drop شد و
   اکنون اصلاً وجود ندارد. هیچ Driftِ واقعی/ناشناخته‌ای پیدا نشد.

   تنها اختلافِ واقعیِ باقی‌مانده بینِ DBِ زندهٔ آن لحظه و ۰۲۶: متنِ یک Commentِ
   توضیحی در sp_Wf_SaveConditionField که کلماتش کمی متفاوت بازنویسی شده بود
   (صفر تفاوتِ منطقی/رفتاری) و فرمت‌بندیِ whitespace در fn_Wf_RuleJsonFieldCodes
   (باز هم صفر تفاوتِ منطقی). هدفِ این پچ فقط همگام‌سازیِ بدنهٔ این دو Object با
   متنِ Canonicalِ همان ۰۲۶ است — نه هیچ تغییرِ رفتاری، نه بخشی از Phase A
   (WorkflowEntityTypes) یا هر کارِ دیگر.

   `sp_QIVerifyTemp` عمداً در این پچ نیامده — یک Objectِ Scratch بود که در
   همان جلسه ساخته و بلافاصله حذف شد؛ نیازی به بازسازی‌اش نیست و نباید بازسازی
   شود.
   ========================================================================== */

SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;
GO

-- [ALTER_FUNCTION] روی FUNCTION: fn_Wf_RuleJsonFieldCodes — بازگرداندنِ Whitespace/فرمت‌بندیِ Canonical (بدونِ تغییرِ منطق)
ALTER FUNCTION dbo.fn_Wf_RuleJsonFieldCodes (@RuleJson NVARCHAR(MAX))
RETURNS TABLE
AS
RETURN (
    WITH Nodes AS (
        SELECT JSON_VALUE(@RuleJson, '$.type') AS NodeType,
               JSON_VALUE(@RuleJson, '$.field') AS FieldCode,
               JSON_QUERY(@RuleJson, '$.children') AS ChildrenJson,
               1 AS Depth
        UNION ALL
        SELECT JSON_VALUE(c.[value], '$.type'),
               JSON_VALUE(c.[value], '$.field'),
               JSON_QUERY(c.[value], '$.children'),
               n.Depth + 1
        FROM Nodes n
        CROSS APPLY OPENJSON(n.ChildrenJson) c
        WHERE n.ChildrenJson IS NOT NULL AND n.Depth < 6
    )
    SELECT FieldCode FROM Nodes WHERE NodeType = N'CONDITION' AND FieldCode IS NOT NULL
)
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_SaveConditionField — بازگرداندنِ متنِ Commentِ Canonical (بدونِ تغییرِ منطق)
ALTER PROCEDURE dbo.sp_Wf_SaveConditionField
    @FieldID            INT = NULL,
    @DefinitionID        INT,
    @Code                NVARCHAR(50),
    @DisplayName         NVARCHAR(200),
    @DataType            NVARCHAR(20),
    @SourceType          NVARCHAR(20),
    @SourceKey           NVARCHAR(100),
    @AllowedValuesJson   NVARCHAR(MAX) = NULL,
    @SortOrder           INT = 0,
    @UserID              INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@Code)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'کد فیلد الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نام نمایشی الزامی است.' AS Message; RETURN; END
    IF NOT EXISTS (SELECT 1 FROM dbo.WorkflowDefinitions WHERE DefinitionID = @DefinitionID)
    BEGIN SELECT 0 AS Success, N'فرایند یافت نشد.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.WorkflowConditionFields
               WHERE DefinitionID = @DefinitionID AND Code = @Code AND (@FieldID IS NULL OR FieldID <> @FieldID))
    BEGIN SELECT 0 AS Success, N'این کد در همین فرایند قبلاً استفاده شده است.' AS Message; RETURN; END

    IF @FieldID IS NULL
    BEGIN
        INSERT INTO dbo.WorkflowConditionFields
            (DefinitionID, Code, DisplayName, DataType, SourceType, SourceKey, AllowedValuesJson, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES
            (@DefinitionID, @Code, @DisplayName, @DataType, @SourceType, @SourceKey, @AllowedValuesJson, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'فیلد ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS FieldID;
    END
    ELSE
    BEGIN
        DECLARE @OldCode NVARCHAR(50);
        SELECT @OldCode = Code FROM dbo.WorkflowConditionFields WHERE FieldID = @FieldID AND DefinitionID = @DefinitionID;

        IF @OldCode IS NULL
        BEGIN SELECT 0 AS Success, N'فیلد یافت نشد.' AS Message; RETURN; END

        -- Guardِ Immutabilityِ Code در سطحِ DB — JSON-aware (نه Textِ خام): با
        -- dbo.fn_Wf_RuleJsonFieldCodes واقعاً درختِ RuleJson پیمایش می‌شود و فقط
        -- Propertyِ «field» در گره‌هایِ CONDITION استخراج می‌شود — مستقل از Whitespace/
        -- Formatting، بدونِ برخورد با مقادیرِ دیگر، و شاملِ همهٔ نسخه‌ها (DRAFT/ACTIVE/
        -- ARCHIVED) بدونِ فیلترِ Status.
        IF @OldCode <> @Code
        BEGIN
            IF EXISTS (
                SELECT 1
                FROM dbo.WorkflowTransitions t
                JOIN dbo.WorkflowSteps s ON s.StepID = t.FromStepID
                JOIN dbo.WorkflowVersions v ON v.VersionID = s.VersionID
                CROSS APPLY dbo.fn_Wf_RuleJsonFieldCodes(t.RuleJson) f
                WHERE v.DefinitionID = @DefinitionID
                  AND t.RuleJson IS NOT NULL
                  AND f.FieldCode = @OldCode
            )
            BEGIN
                SELECT 0 AS Success, N'این فیلد در یک یا چند Rule استفاده شده است؛ Code آن قابل تغییر نیست.' AS Message;
                RETURN;
            END
        END

        UPDATE dbo.WorkflowConditionFields
        SET Code = @Code, DisplayName = @DisplayName, DataType = @DataType, SourceType = @SourceType,
            SourceKey = @SourceKey, AllowedValuesJson = @AllowedValuesJson, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE FieldID = @FieldID;

        SELECT 1 AS Success, N'فیلد ویرایش شد.' AS Message, @FieldID AS FieldID;
    END
END
GO
