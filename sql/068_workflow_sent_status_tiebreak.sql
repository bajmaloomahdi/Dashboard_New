/* ==========================================================================
   پچ خودکار شماره: 068 | نام: workflow_sent_status_tiebreak
   تاریخ: 2026-09-28 19:24:59 | شامل 1 دستور SQL
   ========================================================================== */

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
CREATE OR ALTER PROCEDURE [dbo].[sp_GetMessages]
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
            CreateDate, DueDate, MessageStatusID, MessageStatusName, IsCopy, IsRead,
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
            0 AS IsCopy, 1 AS IsRead
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

