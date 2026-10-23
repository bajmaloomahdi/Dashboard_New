/* ==========================================================================
   پچ خودکار شماره: 120 | نام: crm_set_cooperation_ended
   تاریخ: 2026-10-23 13:05:24 | شامل 1 دستور SQL
   ========================================================================== */

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_SetCooperationEnded
-- پچِ ۱۲۰: ثبت/برداشتنِ «قطعِ همکاری» از صفحهٔ اصلیِ طرف‌حساب/مخاطب — فقط CooperationEndedAt (بقیهٔ فیلدها دست نمی‌خورند)
CREATE PROCEDURE dbo.sp_Crm_SetCooperationEnded
    @EntityType         NVARCHAR(10),          -- PARTY | PERSON
    @EntityID           INT,
    @CooperationEnded   BIT,                   -- ۱ = قطعِ همکاری در @CooperationEndedAt، ۰ = برقراریِ دوبارهٔ همکاری
    @CooperationEndedAt DATETIME2(0) = NULL,
    @UserID             INT
AS
BEGIN
    SET NOCOUNT ON;

    IF @EntityType NOT IN (N'PARTY', N'PERSON')
    BEGIN SELECT 0 AS Success, N'نوعِ رکورد نامعتبر است.' AS Message; RETURN; END
    IF @CooperationEnded IS NULL
    BEGIN SELECT 0 AS Success, N'وضعیتِ قطعِ همکاری نامعتبر است.' AS Message; RETURN; END
    IF @CooperationEnded = 1 AND @CooperationEndedAt IS NULL
    BEGIN SELECT 0 AS Success, N'تاریخ و ساعتِ قطعِ همکاری الزامی است.' AS Message; RETURN; END

    DECLARE @At DATETIME2(0) = CASE WHEN @CooperationEnded = 1 THEN @CooperationEndedAt END;

    IF @EntityType = N'PARTY'
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @EntityID)
        BEGIN SELECT 0 AS Success, N'طرف‌حساب یافت نشد.' AS Message; RETURN; END
        UPDATE dbo.CrmParties SET CooperationEndedAt = @At, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID WHERE PartyID = @EntityID;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM dbo.CrmPersons WHERE PersonID = @EntityID)
        BEGIN SELECT 0 AS Success, N'مخاطب یافت نشد.' AS Message; RETURN; END
        UPDATE dbo.CrmPersons SET CooperationEndedAt = @At, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID WHERE PersonID = @EntityID;
    END

    SELECT 1 AS Success,
           CASE WHEN @CooperationEnded = 1 THEN N'قطعِ همکاری ثبت شد.' ELSE N'قطعِ همکاری برداشته شد.' END AS Message,
           @At AS CooperationEndedAt;
END
GO

