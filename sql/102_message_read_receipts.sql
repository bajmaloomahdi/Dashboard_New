/* ==========================================================================
   پچ خودکار شماره: 102 | نام: message_read_receipts
   تاریخ: 2026-10-18 10:31:05 | شامل 8 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: MessageViews
CREATE TABLE dbo.MessageViews (
    MessageViewID INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_MessageViews PRIMARY KEY,
    MessageID     INT NOT NULL CONSTRAINT FK_MessageViews_Message REFERENCES dbo.Messages (MessageID),
    UserID        INT NOT NULL CONSTRAINT FK_MessageViews_User REFERENCES dbo.Users (UserID),
    FirstViewDate DATETIME2(0) NOT NULL CONSTRAINT DF_MessageViews_FirstViewDate DEFAULT (SYSDATETIME()),
    LastViewDate  DATETIME2(0) NOT NULL CONSTRAINT DF_MessageViews_LastViewDate DEFAULT (SYSDATETIME()),
    CONSTRAINT UQ_MessageViews_Message_User UNIQUE (MessageID, UserID)
)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_NotifyMessageParticipants
-- اعلانِ «تغییرِ تازه» برایِ همهٔ طرف‌هایِ یک پیام (فرستنده، گیرندگان/ارجاع‌دهندگان، رونوشت‌ها) به‌جز انجام‌دهنده؛
-- اگر کاربر همین حالا اعلانِ خوانده‌نشده‌ای برایِ این پیام دارد، اعلانِ دوم ساخته نمی‌شود.
CREATE PROCEDURE dbo.sp_NotifyMessageParticipants
    @MessageID    INT,
    @ExceptUserID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    INSERT INTO dbo.UserNotifications (UserID, MessageID, CreateDate, IsRead)
    SELECT P.UserID, @MessageID, GETDATE(), 0
    FROM (
        SELECT SenderUserID AS UserID FROM dbo.Messages WHERE MessageID = @MessageID
        UNION SELECT FromUserID FROM dbo.MessageDetails WHERE MessageID = @MessageID
        UNION SELECT ToUserID FROM dbo.MessageDetails WHERE MessageID = @MessageID
        UNION SELECT UserID FROM dbo.MessageCopies WHERE MessageID = @MessageID
    ) P
    WHERE P.UserID IS NOT NULL
      AND P.UserID <> ISNULL(@ExceptUserID, 0)
      AND NOT EXISTS (SELECT 1 FROM dbo.UserNotifications UN WHERE UN.UserID = P.UserID AND UN.MessageID = @MessageID AND UN.IsRead = 0);
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_MarkNotificationRead
ALTER PROCEDURE dbo.sp_MarkNotificationRead
    @UserID INT, @MessageID BIGINT
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE dbo.UserNotifications
    SET IsRead = 1
    WHERE UserID = @UserID AND MessageID = @MessageID AND IsRead = 0;

    -- ثبتِ مشاهده (رسیدِ «دیده شد»): اولین بار درج، دفعاتِ بعد فقط زمانِ آخرین مشاهده
    BEGIN TRY
        IF EXISTS (SELECT 1 FROM dbo.MessageViews WHERE MessageID = @MessageID AND UserID = @UserID)
            UPDATE dbo.MessageViews SET LastViewDate = SYSDATETIME() WHERE MessageID = @MessageID AND UserID = @UserID;
        ELSE IF EXISTS (SELECT 1 FROM dbo.Messages WHERE MessageID = @MessageID)
            INSERT INTO dbo.MessageViews (MessageID, UserID) VALUES (CAST(@MessageID AS INT), @UserID);
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() NOT IN (2601, 2627) THROW; -- بازشدنِ هم‌زمانِ دوباره: ردیف از قبل ثبت شده
    END CATCH
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_InsertMessageComment
ALTER PROCEDURE dbo.sp_InsertMessageComment
    @MessageID INT,
    @UserID    INT,
    @Comment   NVARCHAR(MAX) = NULL   -- اختیاری شد (برای حالت فقط-فایل)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Success      BIT           = 0;
    DECLARE @Message      NVARCHAR(500) = N'';
    DECLARE @NewCommentID INT           = NULL;

    BEGIN TRY
        IF NOT EXISTS (SELECT 1 FROM dbo.Messages WHERE MessageID = @MessageID)
        BEGIN
            SELECT 0 AS Success, N'پیام مورد نظر یافت نشد.' AS Message, NULL AS NewCommentID;
            RETURN;
        END

        -- مجوز
        DECLARE @CanComment BIT = 0;

        IF EXISTS (
            SELECT 1 FROM dbo.Messages M WHERE M.MessageID = @MessageID AND M.SenderUserID = @UserID
        ) OR EXISTS (
            SELECT 1 FROM dbo.MessageDetails MD WHERE MD.MessageID = @MessageID AND MD.ToUserID = @UserID
        ) OR EXISTS (
            SELECT 1 FROM dbo.MessageCopies MC WHERE MC.MessageID = @MessageID AND MC.UserID = @UserID
        )
        BEGIN
            DECLARE @IsTask2 BIT = 0;
            DECLARE @LastStatusName2 NVARCHAR(100) = N'';

            SELECT @IsTask2 = CASE WHEN MT.MessageTypeName = N'وظیفه' THEN 1 ELSE 0 END
            FROM dbo.Messages M
            JOIN dbo.MessageTypes MT ON MT.MessageTypeID = M.MessageTypeID
            WHERE M.MessageID = @MessageID;

            IF @IsTask2 = 1
            BEGIN
                SELECT TOP 1 @LastStatusName2 = MS.MessageStatusName
                FROM dbo.MessageDetails MD
                JOIN dbo.MessageStatuses MS ON MS.MessageStatusID = MD.MessageStatusID
                WHERE MD.MessageID = @MessageID
                ORDER BY MD.CreateDate DESC, MD.MessageDetailID DESC;

                IF @LastStatusName2 NOT IN (N'انجام شده', N'انجام نخواهد شد')
                    SET @CanComment = 1;
            END
            ELSE
            BEGIN
                SET @CanComment = 1;
            END
        END

        IF @CanComment = 0
        BEGIN
            SELECT 0 AS Success, N'شما اجازه افزودن توضیح به این پیام را ندارید.' AS Message, NULL AS NewCommentID;
            RETURN;
        END

        INSERT INTO dbo.MessageComments
        (
            RowGuid, MessageID, UserID, Comment, CreateDate
        )
        VALUES
        (
            NEWID(), @MessageID, @UserID, @Comment, GETDATE()
        );

        SET @NewCommentID = SCOPE_IDENTITY();

        -- توضیح/ضمیمهٔ تازه → پیام برایِ بقیهٔ طرف‌ها «تغییرِ خوانده‌نشده» می‌شود (و شمارندهٔ بالایِ صفحه زیاد می‌شود)
        EXEC dbo.sp_NotifyMessageParticipants @MessageID = @MessageID, @ExceptUserID = @UserID;
        SET @Success = 1;
        SET @Message = N'ثبت شد.';
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK;
        SET @Message = N'خطا در ثبت: ' + ERROR_MESSAGE();
    END CATCH

    SELECT @Success AS Success, @Message AS Message, @NewCommentID AS NewCommentID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_GetMessages
/* =====================================================================
   بخش ۸ — sp_GetMessages : تغییرِ افزایشیِ scope‌شده (تنها SPِ غیرِ Workflow)

   افزوده‌ها:
     • ستونِ IsWfTask در CTE (branch A و B)
     • یک WHEN جدید در سه CASEِ «InInbox» فقط برای وظیفه‌های Workflow
   رفتارِ همهٔ ۲۲ پیامِ موجود (که هیچ‌کدام IsWfTask=1 نیستند) دست‌نخورده می‌ماند.
   وظیفهٔ Workflow برای هر assignee تا وقتی وضعیتِ *شخصیِ* او (MessageDetails خودش)
   پایانی نشده در کارتابل می‌ماند — چون مدلِ «تک‌مالکیِ آخرین‌گیرنده» برای چند
   assignee هم‌زمانِ ANY/ALL/N_OF_M کار نمی‌کند.
   ===================================================================== */
ALTER PROCEDURE [dbo].[sp_GetMessages]
    @UserID           INT,
    @Mode             TINYINT,              -- 1=دریافتی  2=ارسالی
    @IsArchive        BIT           = 0,
    @SearchText       NVARCHAR(200) = NULL,
    @MessageTypeID    INT           = NULL,
    @MessageStatusID  INT           = NULL,
    @FromDate         DATE          = NULL,
    @ToDate           DATE          = NULL,
    @msgPriorityID    INT           = NULL
AS
BEGIN
    SET NOCOUNT ON;

    IF @Mode = 1
    BEGIN
        ;WITH Base AS
        (
            -- الف) گیرنده اصلی
            SELECT
                M.MessageID, M.MessageNumber, M.Subject, M.MessageText, M.MessageTypeID, MT.MessageTypeName,
                M.msgPriorityID, MP.Name AS PriorityName, MP.SortOrder AS PrioritySortOrder,
                M.SenderUserID, LTRIM(CONCAT(SU.FirstName, N' ', SU.LastName)) AS SenderName,
                M.CreateDate, M.DueDate, MS.MessageStatusID, MS.MessageStatusName,
                0 AS IsCopy,
                CASE WHEN EXISTS (SELECT 1 FROM dbo.UserNotifications UN WHERE UN.UserID = @UserID AND UN.MessageID = M.MessageID AND UN.IsRead = 1) THEN 1 ELSE 0 END AS IsRead,
                -- تغییرِ خوانده‌نشده (پیامِ تازه، توضیح/ضمیمهٔ تازه، ارجاع، تغییرِ وضعیت، رونوشت) — فقط برایِ نمایش؛ جایِ پیام (کارتابل/بایگانی) را عوض نمی‌کند
                CASE WHEN EXISTS (SELECT 1 FROM dbo.UserNotifications UN WHERE UN.UserID = @UserID AND UN.MessageID = M.MessageID AND UN.IsRead = 0) THEN 1 ELSE 0 END AS HasUnread,
                CASE WHEN MT.MessageTypeName = N'وظیفه' THEN 1 ELSE 0 END AS IsTask,
                CASE WHEN EXISTS (SELECT 1 FROM dbo.WorkflowStepInstances WSI WHERE WSI.MessageID = M.MessageID) THEN 1 ELSE 0 END AS IsWfTask,
                LastD.ToUserID AS LastToUserID, LastD.StatusName AS LastStatusName
            FROM dbo.Messages M
            JOIN dbo.MessageTypes MT    ON MT.MessageTypeID = M.MessageTypeID
            LEFT JOIN dbo.msgPriorities MP ON MP.msgPriorityID = M.msgPriorityID
            LEFT JOIN dbo.Users SU      ON SU.UserID = M.SenderUserID
            JOIN dbo.MessageDetails MD  ON MD.MessageID = M.MessageID AND MD.ToUserID = @UserID
            JOIN dbo.MessageStatuses MS ON MS.MessageStatusID = MD.MessageStatusID
            OUTER APPLY (
                SELECT TOP 1 MD2.ToUserID, MS2.MessageStatusName AS StatusName
                FROM dbo.MessageDetails MD2
                JOIN dbo.MessageStatuses MS2 ON MS2.MessageStatusID = MD2.MessageStatusID
                WHERE MD2.MessageID = M.MessageID
                ORDER BY MD2.CreateDate DESC, MD2.MessageDetailID DESC
            ) LastD

            UNION ALL

            -- ب) رونوشت‌گیرنده (بدونِ ردیفِ گیرندگیِ اصلی)
            SELECT
                M.MessageID, M.MessageNumber, M.Subject, M.MessageText, M.MessageTypeID, MT.MessageTypeName,
                M.msgPriorityID, MP.Name AS PriorityName, MP.SortOrder AS PrioritySortOrder,
                M.SenderUserID, LTRIM(CONCAT(SU.FirstName, N' ', SU.LastName)) AS SenderName,
                M.CreateDate, M.DueDate, ISNULL(MS.MessageStatusID, 1) AS MessageStatusID,
                ISNULL(MS.MessageStatusName, N'ارسال شده') AS MessageStatusName,
                1 AS IsCopy,
                CASE WHEN EXISTS (SELECT 1 FROM dbo.UserNotifications UN WHERE UN.UserID = @UserID AND UN.MessageID = M.MessageID AND UN.IsRead = 1) THEN 1 ELSE 0 END AS IsRead,
                -- تغییرِ خوانده‌نشده (پیامِ تازه، توضیح/ضمیمهٔ تازه، ارجاع، تغییرِ وضعیت، رونوشت) — فقط برایِ نمایش؛ جایِ پیام (کارتابل/بایگانی) را عوض نمی‌کند
                CASE WHEN EXISTS (SELECT 1 FROM dbo.UserNotifications UN WHERE UN.UserID = @UserID AND UN.MessageID = M.MessageID AND UN.IsRead = 0) THEN 1 ELSE 0 END AS HasUnread,
                CASE WHEN MT.MessageTypeName = N'وظیفه' THEN 1 ELSE 0 END AS IsTask,
                CASE WHEN EXISTS (SELECT 1 FROM dbo.WorkflowStepInstances WSI WHERE WSI.MessageID = M.MessageID) THEN 1 ELSE 0 END AS IsWfTask,
                LastD.ToUserID AS LastToUserID, LastD.StatusName AS LastStatusName
            FROM dbo.Messages M
            JOIN dbo.MessageTypes MT  ON MT.MessageTypeID = M.MessageTypeID
            LEFT JOIN dbo.msgPriorities MP ON MP.msgPriorityID = M.msgPriorityID
            LEFT JOIN dbo.Users SU    ON SU.UserID = M.SenderUserID
            JOIN dbo.MessageCopies MC ON MC.MessageID = M.MessageID AND MC.UserID = @UserID
            LEFT JOIN dbo.MessageStatuses MS ON MS.MessageStatusID = 1
            OUTER APPLY (
                SELECT TOP 1 MD2.ToUserID, MS2.MessageStatusName AS StatusName
                FROM dbo.MessageDetails MD2
                JOIN dbo.MessageStatuses MS2 ON MS2.MessageStatusID = MD2.MessageStatusID
                WHERE MD2.MessageID = M.MessageID
                ORDER BY MD2.CreateDate DESC, MD2.MessageDetailID DESC
            ) LastD
            WHERE NOT EXISTS (SELECT 1 FROM dbo.MessageDetails MD2 WHERE MD2.MessageID = M.MessageID AND MD2.ToUserID = @UserID)
        )
        SELECT
            MessageID, MessageNumber, Subject, MessageText, MessageTypeID, MessageTypeName,
            msgPriorityID, PriorityName, PrioritySortOrder, SenderUserID, SenderName,
            CreateDate, DueDate, MessageStatusID, MessageStatusName, IsCopy, IsRead, HasUnread,
            CASE
                WHEN IsTask = 1 AND IsWfTask = 1 AND ISNULL(MessageStatusName, N'') NOT IN (N'انجام شده', N'انجام نخواهد شد') THEN 1
                WHEN IsTask = 1 AND IsWfTask = 0 AND LastToUserID = @UserID AND ISNULL(LastStatusName, N'') NOT IN (N'انجام شده', N'انجام نخواهد شد') THEN 1
                WHEN IsTask = 0 AND IsRead = 0 THEN 1
                ELSE 0
            END AS InInbox
        FROM Base
        WHERE
            ((@IsArchive = 0 AND (CASE
                WHEN IsTask = 1 AND IsWfTask = 1 AND ISNULL(MessageStatusName, N'') NOT IN (N'انجام شده', N'انجام نخواهد شد') THEN 1
                WHEN IsTask = 1 AND IsWfTask = 0 AND LastToUserID = @UserID AND ISNULL(LastStatusName, N'') NOT IN (N'انجام شده', N'انجام نخواهد شد') THEN 1
                WHEN IsTask = 0 AND IsRead = 0 THEN 1
                ELSE 0 END) = 1)
             OR
             (@IsArchive = 1 AND (CASE
                WHEN IsTask = 1 AND IsWfTask = 1 AND ISNULL(MessageStatusName, N'') NOT IN (N'انجام شده', N'انجام نخواهد شد') THEN 1
                WHEN IsTask = 1 AND IsWfTask = 0 AND LastToUserID = @UserID AND ISNULL(LastStatusName, N'') NOT IN (N'انجام شده', N'انجام نخواهد شد') THEN 1
                WHEN IsTask = 0 AND IsRead = 0 THEN 1
                ELSE 0 END) = 0))
          AND (@SearchText IS NULL OR Subject LIKE N'%' + @SearchText + N'%' OR MessageNumber LIKE N'%' + @SearchText + N'%')
          AND (@MessageTypeID   IS NULL OR MessageTypeID   = @MessageTypeID)
          AND (@MessageStatusID IS NULL OR MessageStatusID = @MessageStatusID)
          AND (@msgPriorityID   IS NULL OR msgPriorityID   = @msgPriorityID)
          AND (@FromDate IS NULL OR CAST(CreateDate AS DATE) >= @FromDate)
          AND (@ToDate   IS NULL OR CAST(CreateDate AS DATE) <= @ToDate)
        ORDER BY
            CASE WHEN PrioritySortOrder IS NULL THEN 1 ELSE 0 END,
            PrioritySortOrder DESC, CreateDate DESC;
    END
    ELSE
    BEGIN
        -- ارسالی — بدونِ تغییر
        SELECT
            M.MessageID, M.MessageNumber, M.Subject, M.MessageText, M.MessageTypeID, MT.MessageTypeName,
            M.msgPriorityID, MP.Name AS PriorityName, MP.SortOrder AS PrioritySortOrder,
            M.SenderUserID, LTRIM(CONCAT(SU.FirstName, N' ', SU.LastName)) AS SenderName,
            M.CreateDate, M.DueDate,
            ISNULL(LastStatus.MessageStatusID, 1) AS MessageStatusID,
            ISNULL(LastStatus.MessageStatusName, N'ارسال شده') AS MessageStatusName,
            0 AS IsCopy, 1 AS IsRead,
            CASE WHEN EXISTS (SELECT 1 FROM dbo.UserNotifications UN WHERE UN.UserID = @UserID AND UN.MessageID = M.MessageID AND UN.IsRead = 0) THEN 1 ELSE 0 END AS HasUnread,
            -- رسیدِ مشاهده برایِ فرستنده: چند نفر از گیرندگان/رونوشت‌ها پیام را دیده‌اند
            ISNULL(RS.RecipientCount, 0) AS RecipientCount, ISNULL(RS.SeenCount, 0) AS SeenCount
        FROM dbo.Messages M
        JOIN dbo.MessageTypes MT  ON MT.MessageTypeID = M.MessageTypeID
        LEFT JOIN dbo.msgPriorities MP ON MP.msgPriorityID = M.msgPriorityID
        LEFT JOIN dbo.Users SU    ON SU.UserID = M.SenderUserID
        OUTER APPLY (
            SELECT TOP 1 MS2.MessageStatusID, MS2.MessageStatusName
            FROM dbo.MessageDetails MD
            JOIN dbo.MessageStatuses MS2 ON MS2.MessageStatusID = MD.MessageStatusID
            WHERE MD.MessageID = M.MessageID
            ORDER BY MD.CreateDate DESC,
                         CASE WHEN MS2.MessageStatusName = N'انجام شده' THEN 0 ELSE 1 END,
                         MD.MessageDetailID DESC
        ) LastStatus
        OUTER APPLY (
            SELECT COUNT(*) AS RecipientCount, SUM(R2.Seen) AS SeenCount
            FROM (
                SELECT R.UserID, CASE WHEN EXISTS (SELECT 1 FROM dbo.MessageViews V WHERE V.MessageID = M.MessageID AND V.UserID = R.UserID) OR EXISTS (SELECT 1 FROM dbo.UserNotifications UN2 WHERE UN2.MessageID = M.MessageID AND UN2.UserID = R.UserID AND UN2.IsRead = 1) THEN 1 ELSE 0 END AS Seen
                FROM (
                    SELECT MD.ToUserID AS UserID FROM dbo.MessageDetails MD WHERE MD.MessageID = M.MessageID
                    UNION SELECT MC.UserID FROM dbo.MessageCopies MC WHERE MC.MessageID = M.MessageID
                ) R
                WHERE R.UserID <> M.SenderUserID
            ) R2
        ) RS
        WHERE M.SenderUserID = @UserID
          AND (@SearchText IS NULL OR M.Subject LIKE N'%' + @SearchText + N'%' OR M.MessageNumber LIKE N'%' + @SearchText + N'%')
          AND (@MessageTypeID   IS NULL OR M.MessageTypeID   = @MessageTypeID)
          AND (@MessageStatusID IS NULL OR LastStatus.MessageStatusID = @MessageStatusID)
          AND (@msgPriorityID   IS NULL OR M.msgPriorityID   = @msgPriorityID)
          AND (@FromDate IS NULL OR CAST(M.CreateDate AS DATE) >= @FromDate)
          AND (@ToDate   IS NULL OR CAST(M.CreateDate AS DATE) <= @ToDate)
        ORDER BY
            CASE WHEN MP.SortOrder IS NULL THEN 1 ELSE 0 END,
            MP.SortOrder DESC, M.CreateDate DESC;
    END
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_GetMessageDetailsList
ALTER PROCEDURE dbo.sp_GetMessageDetailsList
    @MessageID INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        MD.MessageDetailID,
        MD.FromUserID,
        LTRIM(CONCAT(FU.FirstName, N' ', FU.LastName)) AS FromName,
        MD.ToUserID,
        LTRIM(CONCAT(TU.FirstName, N' ', TU.LastName)) AS ToName,
        MD.MessageStatusID,
        MS.MessageStatusName,
        MD.Description,
        MD.CreateDate,
        -- رسیدِ مشاهدهٔ گیرنده (پیام‌هایِ قدیمی‌تر: «دیده شد» بدونِ زمان)
        CASE WHEN EXISTS (SELECT 1 FROM dbo.MessageViews V WHERE V.MessageID = MD.MessageID AND V.UserID = MD.ToUserID) OR EXISTS (SELECT 1 FROM dbo.UserNotifications UN2 WHERE UN2.MessageID = MD.MessageID AND UN2.UserID = MD.ToUserID AND UN2.IsRead = 1) THEN 1 ELSE 0 END AS ToSeen,
        TV.FirstViewDate AS ToSeenDate, TV.LastViewDate AS ToLastSeenDate
    FROM dbo.MessageDetails MD
    LEFT JOIN dbo.MessageViews TV ON TV.MessageID = MD.MessageID AND TV.UserID = MD.ToUserID
    LEFT JOIN dbo.Users FU  ON FU.UserID = MD.FromUserID
    LEFT JOIN dbo.Users TU  ON TU.UserID = MD.ToUserID
    JOIN dbo.MessageStatuses MS ON MS.MessageStatusID = MD.MessageStatusID
    WHERE MD.MessageID = @MessageID
    ORDER BY MD.CreateDate, MD.MessageDetailID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_GetMessageCopiesList
ALTER PROCEDURE dbo.sp_GetMessageCopiesList
    @MessageID INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        MC.MessageCopyID,
        MC.UserID,
        LTRIM(CONCAT(U.FirstName, N' ', U.LastName)) AS FullName,
        MC.CreateDate,
        MC.CreateUser,
        LTRIM(CONCAT(CU.FirstName, N' ', CU.LastName)) AS CreateUserName,
        MC.Description,
        -- رسیدِ مشاهدهٔ رونوشت‌گیرنده (پیام‌هایِ قدیمی‌تر: «دیده شد» بدونِ زمان)
        CASE WHEN EXISTS (SELECT 1 FROM dbo.MessageViews V WHERE V.MessageID = MC.MessageID AND V.UserID = MC.UserID) OR EXISTS (SELECT 1 FROM dbo.UserNotifications UN2 WHERE UN2.MessageID = MC.MessageID AND UN2.UserID = MC.UserID AND UN2.IsRead = 1) THEN 1 ELSE 0 END AS Seen,
        CV.FirstViewDate AS SeenDate, CV.LastViewDate AS LastSeenDate
    FROM dbo.MessageCopies MC
    LEFT JOIN dbo.MessageViews CV ON CV.MessageID = MC.MessageID AND CV.UserID = MC.UserID
    LEFT JOIN dbo.Users U  ON U.UserID = MC.UserID
    LEFT JOIN dbo.Users CU ON CU.UserID = MC.CreateUser
    WHERE MC.MessageID = @MessageID
    ORDER BY MC.MessageCopyID;
END
GO

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_GetUnreadNotificationsCount
ALTER PROCEDURE dbo.sp_GetUnreadNotificationsCount
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    -- تعدادِ پیام‌هایِ دارایِ تغییرِ خوانده‌نشده (نه تعدادِ ردیف‌هایِ اعلان)
    SELECT COUNT(DISTINCT MessageID) AS UnreadCount
    FROM dbo.UserNotifications
    WHERE UserID = @UserID AND IsRead = 0;
END
GO

