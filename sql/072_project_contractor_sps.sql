/* ==========================================================================
   پچ خودکار شماره: 072 | نام: project_contractor_sps
   تاریخ: 2026-10-01 07:26:59 | شامل 4 دستور SQL
   ========================================================================== */

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetProjectContractors
CREATE OR ALTER PROCEDURE dbo.sp_GetProjectContractors
    @ProjectID BIGINT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        pc.ProjectContractorID,
        pc.ProjectID,
        pc.PartyID,
        p.OfficialName,
        p.TradeName,
        p.IdentifierNumber,
        p.PartyNature,
        p.IsActive AS PartyIsActive,
        pc.IsActive,
        pc.Date_InsertFirst
    FROM dbo.ProjectContractors pc
    LEFT JOIN dbo.CrmParties p ON p.PartyID = pc.PartyID
    WHERE pc.ProjectID = @ProjectID
    ORDER BY pc.IsActive DESC, p.OfficialName;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_SearchCrmPartiesForContractor
CREATE OR ALTER PROCEDURE dbo.sp_SearchCrmPartiesForContractor
    @SearchText NVARCHAR(200) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    -- عمداً فقط همین چند ستونِ لازم برایِ Autocomplete — نه کلِ خروجیِ sp_Crm_GetParties
    -- (بدونِ Countِ برند/آدرس/تماس و…)، چون این مسیر مخصوصِ کاربرانی است که دسترسیِ
    -- کاملِ CRM را ندارند و فقط حقِ انتخابِ طرف‌حساب برایِ پیمانکارِ پروژه را دارند.
    SELECT TOP 50
        p.PartyID,
        p.OfficialName,
        p.TradeName,
        p.IdentifierNumber,
        p.PartyNature,
        p.IsActive
    FROM dbo.CrmParties p
    WHERE p.IsActive = 1
      AND (@SearchText IS NULL OR @SearchText = N''
           OR p.OfficialName LIKE N'%' + @SearchText + N'%'
           OR p.TradeName LIKE N'%' + @SearchText + N'%'
           OR p.IdentifierNumber LIKE N'%' + @SearchText + N'%')
    ORDER BY p.OfficialName;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_AddProjectContractor
CREATE OR ALTER PROCEDURE dbo.sp_AddProjectContractor
    @ProjectID  BIGINT,
    @PartyID    INT,
    @CreateUser INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF NOT EXISTS (SELECT 1 FROM dbo.Projects WHERE ProjectID = @ProjectID)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'پروژه یافت نشد.' AS Message;
            RETURN;
        END

        IF NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'طرف‌حساب یافت نشد.' AS Message;
            RETURN;
        END

        /* اگر قبلاً حذف (IsActive=0) شده بود، دوباره فعالش می‌کنیم — همان ردیف، نه ردیفِ تازه */
        IF EXISTS (SELECT 1 FROM dbo.ProjectContractors WHERE ProjectID = @ProjectID AND PartyID = @PartyID)
        BEGIN
            UPDATE dbo.ProjectContractors
            SET IsActive          = 1,
                Date_LastUpdate   = SYSDATETIME(),
                UserID_LastUpdate = @CreateUser
            WHERE ProjectID = @ProjectID AND PartyID = @PartyID;

            SELECT CAST(1 AS BIT) AS Success, N'پیمانکار دوباره به پروژه اضافه شد.' AS Message;
            RETURN;
        END

        INSERT INTO dbo.ProjectContractors
            (ProjectID, PartyID, Date_InsertFirst, UserID_InsertFirst)
        VALUES
            (@ProjectID, @PartyID, SYSDATETIME(), @CreateUser);

        SELECT CAST(1 AS BIT) AS Success, N'پیمانکار با موفقیت اضافه شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در افزودن پیمانکار: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_RemoveProjectContractor
CREATE OR ALTER PROCEDURE dbo.sp_RemoveProjectContractor
    @ProjectID  BIGINT,
    @PartyID    INT,
    @ModifyUser INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        UPDATE dbo.ProjectContractors
        SET IsActive          = 0,
            Date_LastUpdate   = SYSDATETIME(),
            UserID_LastUpdate = @ModifyUser
        WHERE ProjectID = @ProjectID AND PartyID = @PartyID;

        SELECT CAST(1 AS BIT) AS Success, N'پیمانکار از پروژه حذف شد.' AS Message;
    END TRY
    BEGIN CATCH
        SELECT CAST(0 AS BIT) AS Success, N'خطا در حذف پیمانکار: ' + ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

