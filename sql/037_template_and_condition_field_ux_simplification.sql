/* ==========================================================================
   پچ خودکار شماره: 037 | نام: template_and_condition_field_ux_simplification
   تاریخ: 2026-09-19 10:54:48 | شامل 7 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: TemplateParameters
ALTER TABLE dbo.TemplateParameters ADD Description NVARCHAR(500) NULL
GO

-- [ALTER_TABLE] روی TABLE: WorkflowConditionFields
ALTER TABLE dbo.WorkflowConditionFields ADD Description NVARCHAR(500) NULL
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_GetTemplateParameters
CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetTemplateParameters
    @SearchText NVARCHAR(200) = NULL,
    @GroupCode  NVARCHAR(20)  = NULL,
    @EntityType NVARCHAR(64)  = NULL,
    @IsActive   BIT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TemplateParameterID, Code, Caption, GroupCode, EntityType, DataType, SourceType, SourceKey,
           AllowedValuesJson, Description, IsActive, SortOrder,
           Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate
    FROM dbo.TemplateParameters
    WHERE (@SearchText IS NULL OR @SearchText = N''
           OR Caption LIKE N'%' + @SearchText + N'%'
           OR Code LIKE N'%' + @SearchText + N'%')
      AND (@GroupCode IS NULL OR @GroupCode = N'' OR GroupCode = @GroupCode)
      AND (@EntityType IS NULL OR @EntityType = N'' OR EntityType = @EntityType)
      AND (@IsActive IS NULL OR IsActive = @IsActive)
    ORDER BY SortOrder, TemplateParameterID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_GetTemplateParameterByCode
CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetTemplateParameterByCode
    @Code NVARCHAR(64)
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TOP 1 TemplateParameterID, Code, Caption, GroupCode, EntityType, DataType, SourceType, SourceKey,
           AllowedValuesJson, Description, IsActive, SortOrder
    FROM dbo.TemplateParameters
    WHERE Code = @Code;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_SaveTemplateParameter
CREATE OR ALTER PROCEDURE dbo.sp_Wf_SaveTemplateParameter
    @TemplateParameterID INT           = NULL,   -- NULL = ایجاد
    @Code                NVARCHAR(64),
    @Caption             NVARCHAR(200),
    @GroupCode           NVARCHAR(20),
    @EntityType          NVARCHAR(64)  = NULL,
    @DataType            NVARCHAR(20),
    @SourceType          NVARCHAR(20),
    @SourceKey           NVARCHAR(100),
    @AllowedValuesJson   NVARCHAR(MAX) = NULL,
    @Description         NVARCHAR(500) = NULL,
    @SortOrder           INT           = 0,
    @UserID              INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@Code)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'کد الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@Caption)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'عنوانِ نمایشی الزامی است.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.TemplateParameters
               WHERE Code = @Code AND (@TemplateParameterID IS NULL OR TemplateParameterID <> @TemplateParameterID))
    BEGIN SELECT 0 AS Success, N'این کد قبلاً استفاده شده است.' AS Message; RETURN; END

    IF @TemplateParameterID IS NULL
    BEGIN
        INSERT INTO dbo.TemplateParameters
            (Code, Caption, GroupCode, EntityType, DataType, SourceType, SourceKey, AllowedValuesJson, Description, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES
            (@Code, @Caption, @GroupCode, @EntityType, @DataType, @SourceType, @SourceKey, @AllowedValuesJson, @Description, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'پارامتر ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS TemplateParameterID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.TemplateParameters WHERE TemplateParameterID = @TemplateParameterID)
        BEGIN SELECT 0 AS Success, N'پارامتر یافت نشد.' AS Message; RETURN; END

        UPDATE dbo.TemplateParameters
        SET Code = @Code, Caption = @Caption, GroupCode = @GroupCode, EntityType = @EntityType,
            DataType = @DataType, SourceType = @SourceType, SourceKey = @SourceKey,
            AllowedValuesJson = @AllowedValuesJson, Description = @Description, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE TemplateParameterID = @TemplateParameterID;

        SELECT 1 AS Success, N'پارامتر ویرایش شد.' AS Message, @TemplateParameterID AS TemplateParameterID;
    END
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_GetConditionFields
CREATE OR ALTER PROCEDURE dbo.sp_Wf_GetConditionFields
    @IncludeInactive  BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    SELECT FieldID, Code, DisplayName, DataType, SourceType, SourceKey,
           AllowedValuesJson, Description, SortOrder, IsActive
    FROM dbo.WorkflowConditionFields
    WHERE (@IncludeInactive = 1 OR IsActive = 1)
    ORDER BY SortOrder, Code;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_SaveConditionField
CREATE OR ALTER PROCEDURE dbo.sp_Wf_SaveConditionField
    @FieldID            INT = NULL,   -- NULL = ایجاد
    @Code                NVARCHAR(50),
    @DisplayName         NVARCHAR(200),
    @DataType            NVARCHAR(20),
    @SourceType          NVARCHAR(20),
    @SourceKey           NVARCHAR(100),
    @AllowedValuesJson   NVARCHAR(MAX) = NULL,
    @Description         NVARCHAR(500) = NULL,
    @SortOrder           INT = 0,
    @UserID              INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NULLIF(LTRIM(RTRIM(@Code)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'کد فیلد الزامی است.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@DisplayName)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'نام نمایشی الزامی است.' AS Message; RETURN; END

    IF EXISTS (SELECT 1 FROM dbo.WorkflowConditionFields
               WHERE Code = @Code AND (@FieldID IS NULL OR FieldID <> @FieldID))
    BEGIN SELECT 0 AS Success, N'این کد قبلاً استفاده شده است.' AS Message; RETURN; END

    IF @FieldID IS NULL
    BEGIN
        INSERT INTO dbo.WorkflowConditionFields
            (Code, DisplayName, DataType, SourceType, SourceKey, AllowedValuesJson, Description, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
        VALUES
            (@Code, @DisplayName, @DataType, @SourceType, @SourceKey, @AllowedValuesJson, @Description, ISNULL(@SortOrder, 0), 1, SYSDATETIME(), @UserID);

        SELECT 1 AS Success, N'فیلد ایجاد شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS FieldID;
    END
    ELSE
    BEGIN
        DECLARE @OldCode NVARCHAR(50);
        SELECT @OldCode = Code FROM dbo.WorkflowConditionFields WHERE FieldID = @FieldID;

        IF @OldCode IS NULL
        BEGIN SELECT 0 AS Success, N'فیلد یافت نشد.' AS Message; RETURN; END

        IF @OldCode <> @Code
        BEGIN
            IF EXISTS (
                SELECT 1
                FROM dbo.WorkflowTransitions t
                CROSS APPLY dbo.fn_Wf_RuleJsonFieldCodes(t.RuleJson) f
                WHERE t.RuleJson IS NOT NULL
                  AND f.FieldCode = @OldCode
            )
            BEGIN
                SELECT 0 AS Success, N'این فیلد در یک یا چند Rule استفاده شده است؛ Code آن قابل تغییر نیست.' AS Message;
                RETURN;
            END
        END

        UPDATE dbo.WorkflowConditionFields
        SET Code = @Code, DisplayName = @DisplayName, DataType = @DataType, SourceType = @SourceType,
            SourceKey = @SourceKey, AllowedValuesJson = @AllowedValuesJson, Description = @Description, SortOrder = ISNULL(@SortOrder, 0),
            Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
        WHERE FieldID = @FieldID;

        SELECT 1 AS Success, N'فیلد ویرایش شد.' AS Message, @FieldID AS FieldID;
    END
END
GO

