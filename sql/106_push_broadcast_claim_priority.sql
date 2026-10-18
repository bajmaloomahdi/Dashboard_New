/* ==========================================================================
   پچ خودکار شماره: 106 | نام: push_broadcast_claim_priority
   تاریخ: 2026-10-18 12:29:38 | شامل 1 دستور SQL
   ========================================================================== */

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Push_ClaimBatch
/* Claimِ اتمیک: UPDLOCK + READPAST (اعلانِ عمومی در اولویتِ آخر) — دو پردازشگر هرگز یک رکورد را با هم برنمی‌دارند؛ رکوردِ SENDING با Leaseِ منقضی دوباره برداشته می‌شود. */
ALTER PROCEDURE dbo.sp_Push_ClaimBatch
    @Worker       NVARCHAR(100),
    @BatchSize    INT = 50,
    @LeaseSeconds INT = 300
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @Now DATETIME2(0) = SYSDATETIME();
    ;WITH C AS (
        SELECT TOP (@BatchSize) *
        FROM dbo.PushOutbox WITH (UPDLOCK, READPAST, ROWLOCK)
        WHERE (Status = 'PENDING' AND NextAttemptAt <= @Now)
           OR (Status = 'SENDING' AND LockedUntil < @Now)
        ORDER BY CASE WHEN SourceType = 'BROADCAST' THEN 1 ELSE 0 END, NextAttemptAt, PushOutboxID /* اعلانِ عمومی بعد از اعلان‌هایِ کاری */
    )
    UPDATE C
    SET Status = 'SENDING', LockedBy = @Worker, LockedUntil = DATEADD(SECOND, @LeaseSeconds, @Now),
        Attempts = Attempts + 1, Date_LastUpdate = @Now
    OUTPUT inserted.PushOutboxID, inserted.SourceType, inserted.SourceID, inserted.SourceVersion, inserted.UserID,
           inserted.EventAt, inserted.Kind, inserted.TargetUrl, inserted.Tag, inserted.Attempts;
END
GO

