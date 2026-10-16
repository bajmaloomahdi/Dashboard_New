/* ==========================================================================
   پچ خودکار شماره: 065 | نام: workflow_auto_code_gap_fill
   تاریخ: 2026-09-27 19:26:44 | شامل 1 دستور SQL
   ========================================================================== */

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

            -- تولیدِ خودکارِ Code به‌فرمِ WF{101+}: اولین شمارهٔ خالی از ۱۰۱ به بعد
            -- (نه صرفاً MAX+1) — اگر مثلاً WF101..WF103 و WF105 موجود باشند و WF104 خالی
            -- باشد، Code جدید WF104 می‌شود. UPDLOCK/HOLDLOCK رویِ اسکنِ Codeهای WF###
            -- تا پایانِ تراکنش نگه داشته می‌شود تا زیرِ فشارِ همزمانی هم Duplicate ممکن نباشد.
            -- Codeهایِ قدیمیِ غیرِ WF### (مثلِ TEST_SIMPLE) در این محاسبه دخالت نمی‌کنند.
            DECLARE @NextNum INT;

            ;WITH ExistingNums AS (
                SELECT TRY_CAST(SUBSTRING(Code, 3, LEN(Code) - 2) AS INT) AS Num
                FROM dbo.WorkflowDefinitions WITH (UPDLOCK, HOLDLOCK)
                WHERE Code LIKE N'WF%' AND TRY_CAST(SUBSTRING(Code, 3, LEN(Code) - 2) AS INT) IS NOT NULL
            ),
            Tally AS (
                SELECT TOP (100000) 100 + ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) AS Num
                FROM sys.all_objects a CROSS JOIN sys.all_objects b
            )
            SELECT @NextNum = MIN(t.Num)
            FROM Tally t
            WHERE t.Num <= (SELECT ISNULL(MAX(Num), 100) FROM ExistingNums) + 1
              AND NOT EXISTS (SELECT 1 FROM ExistingNums e WHERE e.Num = t.Num);

            IF @NextNum IS NULL SET @NextNum = 101;

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

