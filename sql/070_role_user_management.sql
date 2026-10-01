/* ==========================================================================
   پچ خودکار شماره: 070 | نام: role_user_management
   تاریخ: 2026-09-30 21:15:55 | شامل 2 دستور SQL
   ========================================================================== */

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetUsersForRole
CREATE OR ALTER PROCEDURE dbo.sp_GetUsersForRole
    @RoleID INT,
    @SearchText NVARCHAR(200) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        u.UserID,
        u.UserCode,
        u.UserName,
        u.FullName,
        u.Email,
        u.Mobile,
        u.IsActive,
        CAST(
            CASE WHEN EXISTS (
                SELECT 1 FROM dbo.UserRoles ur
                WHERE ur.UserID = u.UserID AND ur.RoleID = @RoleID AND ur.IsActive = 1
            ) THEN 1 ELSE 0 END AS BIT
        ) AS HasRole
    FROM dbo.Users u
    WHERE u.IsActive = 1
      AND (@SearchText IS NULL OR @SearchText = ''
           OR u.UserName  LIKE N'%' + @SearchText + N'%'
           OR u.FirstName LIKE N'%' + @SearchText + N'%'
           OR u.LastName  LIKE N'%' + @SearchText + N'%'
           OR u.FullName  LIKE N'%' + @SearchText + N'%'
           OR u.Email     LIKE N'%' + @SearchText + N'%'
           OR u.Mobile    LIKE N'%' + @SearchText + N'%'
           OR u.UserCode  LIKE N'%' + @SearchText + N'%')
    ORDER BY u.FullName;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_SaveRoleUsers
CREATE OR ALTER PROCEDURE dbo.sp_SaveRoleUsers
    @RoleID INT,
    @UserIDs NVARCHAR(MAX),
    @ModifyUser INT = NULL
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.Roles WHERE RoleID = @RoleID)
    BEGIN
        SELECT 0 AS Success, N'نقش مورد نظر یافت نشد' AS Message;
        RETURN;
    END

    DECLARE @Selected TABLE (UserID INT);
    IF @UserIDs IS NOT NULL AND LEN(@UserIDs) > 0
    BEGIN
        INSERT INTO @Selected (UserID)
        SELECT CAST(value AS INT) FROM STRING_SPLIT(@UserIDs, ',') WHERE ISNUMERIC(value) = 1;
    END

    BEGIN TRY
        BEGIN TRAN;

        UPDATE dbo.UserRoles
        SET IsActive = 0, ModifyDate = GETDATE(), ModifyUser = @ModifyUser
        WHERE RoleID = @RoleID AND IsActive = 1;

        UPDATE ur
        SET IsActive = 1, ModifyDate = GETDATE(), ModifyUser = @ModifyUser
        FROM dbo.UserRoles ur
        INNER JOIN @Selected s ON s.UserID = ur.UserID
        WHERE ur.RoleID = @RoleID;

        INSERT INTO dbo.UserRoles (UserID, RoleID, CreateDate, RowGuid, CreateUser, IsActive)
        SELECT s.UserID, @RoleID, GETDATE(), NEWID(), @ModifyUser, 1
        FROM @Selected s
        WHERE NOT EXISTS (
            SELECT 1 FROM dbo.UserRoles ur WHERE ur.RoleID = @RoleID AND ur.UserID = s.UserID
        );

        COMMIT TRAN;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRAN;
        DECLARE @ErrMsg NVARCHAR(4000) = ERROR_MESSAGE();
        SELECT 0 AS Success, N'خطا در ذخیره: ' + @ErrMsg AS Message;
        RETURN;
    END CATCH

    DECLARE @ActiveCount INT;
    SELECT @ActiveCount = COUNT(*) FROM dbo.UserRoles WHERE RoleID = @RoleID AND IsActive = 1;

    SELECT 1 AS Success, N'کاربرانِ این نقش با موفقیت به‌روزرسانی شد' AS Message, @ActiveCount AS ActiveUsersCount;
END
GO

