/* ==========================================================================
   پچ خودکار شماره: 050 | نام: crm_party_toggle_quoted_identifier_fix
   تاریخ: 2026-09-24 16:59:24 | شامل 1 دستور SQL

   این پچ sp_Crm_TogglePartyActive را با QUOTED_IDENTIFIER=ON بازسازی می‌کند.
   بدونِ این تنظیم، هر UPDATE روی CrmParties به‌خاطرِ ایندکسِ یکتایِ فیلترشدهٔ
   UQ_CrmParties_Identifier (پچِ 049) با خطایِ SQL Server ردّ می‌شود.
   ========================================================================== */

SET QUOTED_IDENTIFIER ON
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Crm_TogglePartyActive
ALTER PROCEDURE dbo.sp_Crm_TogglePartyActive
    @PartyID INT,
    @UserID  INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Current BIT;
    SELECT @Current = IsActive FROM dbo.CrmParties WHERE PartyID = @PartyID;

    IF @Current IS NULL
    BEGIN SELECT 0 AS Success, N'طرف‌حساب یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmParties
    SET IsActive = CASE WHEN IsActive = 1 THEN 0 ELSE 1 END,
        Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE PartyID = @PartyID;

    SELECT 1 AS Success,
           CASE WHEN @Current = 1 THEN N'طرف‌حساب غیرفعال شد.' ELSE N'طرف‌حساب فعال شد.' END AS Message;
END
GO

