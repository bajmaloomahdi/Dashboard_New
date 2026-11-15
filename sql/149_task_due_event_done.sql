SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO
CREATE OR ALTER PROCEDURE dbo.sp_UpdateMessageDetailStatus
    @MessageID       INT,
    @UserID          INT,                  -- آخرین گیرنده (تغییردهنده)
    @MessageStatusID INT,                  -- وضعیت جدید
    @Description     NVARCHAR(1000) = NULL, -- توضیح (اختیاری)
    @CreateUser      INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Success BIT = 0;
    DECLARE @Message NVARCHAR(500) = N'';
    DECLARE @TargetDetailID INT;
    DECLARE @SenderUserID INT;

    BEGIN TRY
        -- ---------- اعتبارسنجی ----------
        IF NOT EXISTS (SELECT 1 FROM dbo.Messages WHERE MessageID = @MessageID)
        BEGIN
            SELECT 0 AS Success, N'پیام مورد نظر یافت نشد.' AS Message;
            RETURN;
        END

        IF NOT EXISTS (SELECT 1 FROM dbo.MessageStatuses
                       WHERE MessageStatusID = @MessageStatusID AND IsActive = 1)
        BEGIN
            SELECT 0 AS Success, N'وضعیت نامعتبر است.' AS Message;
            RETURN;
        END

        -- آخرین ردیف این کاربر برای این پیام
        SELECT TOP 1 @TargetDetailID = MessageDetailID
        FROM dbo.MessageDetails
        WHERE MessageID = @MessageID AND ToUserID = @UserID
        ORDER BY CreateDate DESC, MessageDetailID DESC;

        IF @TargetDetailID IS NULL
        BEGIN
            SELECT 0 AS Success, N'این پیام برای شما ثبت نشده است.' AS Message;
            RETURN;
        END

        BEGIN TRAN;

        -- ---------- به‌روزرسانی وضعیت ردیف آخرین گیرنده ----------
        UPDATE dbo.MessageDetails
        SET MessageStatusID = @MessageStatusID,
            Description     = @Description,
            CreateDate      = GETDATE(),
            CreateUser      = @CreateUser
        WHERE MessageDetailID = @TargetDetailID;

        IF @MessageStatusID = 4
        BEGIN
            UPDATE e SET Status = N'DONE'
            FROM dbo.CalendarEvents AS e
            WHERE e.IsActive = 1 AND e.Status IN (N'CONFIRMED', N'TENTATIVE')
                AND EXISTS (
                    SELECT 1
                    FROM dbo.CalendarEventLinks AS taskLink
                    INNER JOIN dbo.ProjectMessages AS pm ON pm.MessageID = taskLink.EntityID
                    INNER JOIN dbo.CalendarEventLinks AS projectLink
                        ON projectLink.EventID = taskLink.EventID AND projectLink.LinkType = 'PROJECT'
                        AND projectLink.EntityID = pm.ProjectID AND projectLink.IsActive = 1
                    WHERE taskLink.EventID = e.EventID AND taskLink.LinkType = 'MESSAGE'
                        AND taskLink.EntityID = @MessageID AND taskLink.IsActive = 1
                );
        END

        -- ---------- اعلان برایِ همهٔ طرف‌هایِ نامه به‌جز تغییردهنده (پچِ ۱۳۰؛ قبلاً فقط فرستنده) ----------
        SELECT @SenderUserID = SenderUserID FROM dbo.Messages WHERE MessageID = @MessageID;

        EXEC dbo.sp_NotifyMessageParticipants @MessageID = @MessageID, @ExceptUserID = @UserID;

        COMMIT;

        SET @Success = 1;
        SET @Message = N'وضعیت وظیفه با موفقیت به‌روزرسانی شد.';
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK;
        SET @Message = N'خطا در تغییر وضعیت: ' + ERROR_MESSAGE();
    END CATCH

    SELECT @Success AS Success, @Message AS Message;
END
GO
