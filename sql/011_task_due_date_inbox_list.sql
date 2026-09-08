/* ==========================================================================
   پچ خودکار شماره: 011 | نام: task_due_date_inbox_list
   تاریخ: 2026-09-07 18:44:56 | شامل 1 دستور SQL
   ========================================================================== */

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_GetMessages
ALTER PROCEDURE [dbo].[sp_GetMessages]
    @UserID           INT,
    @Mode             TINYINT,              -- 1=دریافتی  2=ارسالی
    @IsArchive        BIT           = 0,    -- 1=آرشیو  0=کارتابل
    @SearchText       NVARCHAR(200) = NULL,
    @MessageTypeID    INT           = NULL,
    @MessageStatusID  INT           = NULL,
    @FromDate         DATE          = NULL,
    @ToDate           DATE          = NULL,
    @msgPriorityID    INT           = NULL  -- << جدید: فیلتر اولویت
AS
BEGIN
    SET NOCOUNT ON;

    IF @Mode = 1
    BEGIN
        -- ================= دریافتی =================
        ;WITH Base AS
        (
            -- الف) گیرنده اصلی هستم
            SELECT
                M.MessageID,
                M.MessageNumber,
                M.Subject,
                M.MessageText,
                M.MessageTypeID,
                MT.MessageTypeName,
                M.msgPriorityID,
                MP.Name      AS PriorityName,
                MP.SortOrder AS PrioritySortOrder,
                M.SenderUserID,
                LTRIM(CONCAT(SU.FirstName, N' ', SU.LastName)) AS SenderName,
                M.CreateDate,
                M.DueDate,
                MS.MessageStatusID,
                MS.MessageStatusName,
                0 AS IsCopy,
                CASE WHEN EXISTS (
                    SELECT 1 FROM dbo.UserNotifications UN
                    WHERE UN.UserID = @UserID AND UN.MessageID = M.MessageID AND UN.IsRead = 1
                ) THEN 1 ELSE 0 END AS IsRead,
                -- آیا این پیام وظیفه است؟
                CASE WHEN MT.MessageTypeName = N'وظیفه' THEN 1 ELSE 0 END AS IsTask,
                -- آخرین گیرندهٔ فعلی وظیفه (از آخرین ردیف MessageDetails)
                LastD.ToUserID AS LastToUserID,
                LastD.StatusName AS LastStatusName
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

            -- ب) رونوشت گرفته‌ام (که گیرنده اصلی نباشم)
            SELECT
                M.MessageID,
                M.MessageNumber,
                M.Subject,
                M.MessageText,
                M.MessageTypeID,
                MT.MessageTypeName,
                M.msgPriorityID,
                MP.Name      AS PriorityName,
                MP.SortOrder AS PrioritySortOrder,
                M.SenderUserID,
                LTRIM(CONCAT(SU.FirstName, N' ', SU.LastName)) AS SenderName,
                M.CreateDate,
                M.DueDate,
                ISNULL(MS.MessageStatusID, 1) AS MessageStatusID,
                ISNULL(MS.MessageStatusName, N'ارسال شده') AS MessageStatusName,
                1 AS IsCopy,
                CASE WHEN EXISTS (
                    SELECT 1 FROM dbo.UserNotifications UN
                    WHERE UN.UserID = @UserID AND UN.MessageID = M.MessageID AND UN.IsRead = 1
                ) THEN 1 ELSE 0 END AS IsRead,
                CASE WHEN MT.MessageTypeName = N'وظیفه' THEN 1 ELSE 0 END AS IsTask,
                LastD.ToUserID AS LastToUserID,
                LastD.StatusName AS LastStatusName
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
            WHERE NOT EXISTS (
                SELECT 1 FROM dbo.MessageDetails MD2
                WHERE MD2.MessageID = M.MessageID AND MD2.ToUserID = @UserID
            )
        )
        SELECT
            MessageID, MessageNumber, Subject, MessageText,
            MessageTypeID, MessageTypeName,
            msgPriorityID, PriorityName, PrioritySortOrder,
            SenderUserID, SenderName,
            CreateDate, DueDate, MessageStatusID, MessageStatusName,
            IsCopy, IsRead,
            -- شاخص «باید در کارتابل باشد»:
            CASE
                WHEN IsTask = 1 AND LastToUserID = @UserID
                     AND ISNULL(LastStatusName, N'') NOT IN (N'انجام شده', N'انجام نخواهد شد') THEN 1
                WHEN IsTask = 0 AND IsRead = 0 THEN 1
                ELSE 0
            END AS InInbox
        FROM Base
        WHERE
            -- کارتابل: فقط InInbox=1 / آرشیو: فقط InInbox=0
            ((@IsArchive = 0 AND (CASE
                WHEN IsTask = 1 AND LastToUserID = @UserID
                     AND ISNULL(LastStatusName, N'') NOT IN (N'انجام شده', N'انجام نخواهد شد') THEN 1
                WHEN IsTask = 0 AND IsRead = 0 THEN 1
                ELSE 0 END) = 1)
             OR
             (@IsArchive = 1 AND (CASE
                WHEN IsTask = 1 AND LastToUserID = @UserID
                     AND ISNULL(LastStatusName, N'') NOT IN (N'انجام شده', N'انجام نخواهد شد') THEN 1
                WHEN IsTask = 0 AND IsRead = 0 THEN 1
                ELSE 0 END) = 0))
          AND (@SearchText IS NULL OR Subject LIKE N'%' + @SearchText + N'%'
               OR MessageNumber LIKE N'%' + @SearchText + N'%')
          AND (@MessageTypeID   IS NULL OR MessageTypeID   = @MessageTypeID)
          AND (@MessageStatusID IS NULL OR MessageStatusID = @MessageStatusID)
          AND (@msgPriorityID   IS NULL OR msgPriorityID   = @msgPriorityID)
          AND (@FromDate IS NULL OR CAST(CreateDate AS DATE) >= @FromDate)
          AND (@ToDate   IS NULL OR CAST(CreateDate AS DATE) <= @ToDate)
        ORDER BY
            CASE WHEN PrioritySortOrder IS NULL THEN 1 ELSE 0 END,
            PrioritySortOrder DESC,      -- اولویت بالاتر، بالاتر
            CreateDate DESC;
    END
    ELSE
    BEGIN
        -- ================= ارسالی =================
        SELECT
            M.MessageID,
            M.MessageNumber,
            M.Subject,
            M.MessageText,
            M.MessageTypeID,
            MT.MessageTypeName,
            M.msgPriorityID,
            MP.Name      AS PriorityName,
            MP.SortOrder AS PrioritySortOrder,
            M.SenderUserID,
            LTRIM(CONCAT(SU.FirstName, N' ', SU.LastName)) AS SenderName,
            M.CreateDate,
            M.DueDate,
            ISNULL(LastStatus.MessageStatusID, 1) AS MessageStatusID,
            ISNULL(LastStatus.MessageStatusName, N'ارسال شده') AS MessageStatusName,
            0 AS IsCopy,
            1 AS IsRead
        FROM dbo.Messages M
        JOIN dbo.MessageTypes MT  ON MT.MessageTypeID = M.MessageTypeID
        LEFT JOIN dbo.msgPriorities MP ON MP.msgPriorityID = M.msgPriorityID
        LEFT JOIN dbo.Users SU    ON SU.UserID = M.SenderUserID
        OUTER APPLY (
            SELECT TOP 1 MS2.MessageStatusID, MS2.MessageStatusName
            FROM dbo.MessageDetails MD
            JOIN dbo.MessageStatuses MS2 ON MS2.MessageStatusID = MD.MessageStatusID
            WHERE MD.MessageID = M.MessageID
            ORDER BY MD.CreateDate DESC, MD.MessageDetailID DESC
        ) LastStatus
        WHERE M.SenderUserID = @UserID
          AND (@SearchText IS NULL OR M.Subject LIKE N'%' + @SearchText + N'%'
               OR M.MessageNumber LIKE N'%' + @SearchText + N'%')
          AND (@MessageTypeID   IS NULL OR M.MessageTypeID   = @MessageTypeID)
          AND (@MessageStatusID IS NULL OR LastStatus.MessageStatusID = @MessageStatusID)
          AND (@msgPriorityID   IS NULL OR M.msgPriorityID   = @msgPriorityID)
          AND (@FromDate IS NULL OR CAST(M.CreateDate AS DATE) >= @FromDate)
          AND (@ToDate   IS NULL OR CAST(M.CreateDate AS DATE) <= @ToDate)
        ORDER BY
            CASE WHEN MP.SortOrder IS NULL THEN 1 ELSE 0 END,
            MP.SortOrder DESC,
            M.CreateDate DESC;
    END
END
GO

