/* ==========================================================================
   پچ خودکار شماره: 069 | نام: workflow_letter_condition_deferred
   تاریخ: 2026-09-29 16:28:03 | شامل 1 دستور SQL
   ========================================================================== */

-- [ALTER_PROCEDURE] روی PROCEDURE: sp_Wf_CreateTaskMessage
/* =====================================================================
   بخش ۳ — SP جدید: ساختِ «وظیفهٔ فرایند» به‌صورتِ Message + MessageDetails
   ===================================================================== */

/* چرا نمی‌توان sp_InsertMessage / sp_InsertProjectTask را reuse کرد:
   - sp_InsertMessage: «گیرندهٔ وظیفه باید مدیرِ واحد باشد» + دقیقاً ۱ گیرنده +
     قوانینِ org فرستنده/گیرنده — با انتساب‌های role/position/unit/initiator و
     چند assignee هم‌زمانِ Workflow ناسازگار است.
   - sp_InsertProjectTask: مختصِ عضویتِ پروژه.
   این SP همان جداولِ Messages / MessageDetails / UserNotifications را می‌نویسد،
   با قراردادِ Workflow (assigneeها از AssignmentResolver از قبل نهایی شده‌اند). */
CREATE OR ALTER PROCEDURE dbo.sp_Wf_CreateTaskMessage
    @Subject       NVARCHAR(500),
    @MessageText   NVARCHAR(MAX)  = NULL,
    @msgPriorityID INT            = NULL,
    @DueDate       DATE           = NULL,
    @SenderUserID  INT,
    @AssigneesJson NVARCHAR(MAX),          -- [{ "userId": 5 }, ...]
    @CreateUser    INT,
    @RequireAssignees BIT = 1        -- 0 = مسیرِ Deferred/CONDITION: بدونِ Assignee مجاز است (بدونِ MessageDetails/Notification)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @TaskTypeID INT = (SELECT MessageTypeID FROM dbo.MessageTypes WHERE MessageTypeName = N'وظیفه' AND IsActive = 1);
    IF @TaskTypeID IS NULL
    BEGIN SELECT 0 AS Success, N'نوعِ پیامِ «وظیفه» یافت نشد.' AS Message, CAST(NULL AS INT) AS MessageID, N'' AS MessageNumber; RETURN; END

    IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE UserID = @SenderUserID)
    BEGIN SELECT 0 AS Success, N'فرستندهٔ وظیفه نامعتبر است.' AS Message, CAST(NULL AS INT) AS MessageID, N'' AS MessageNumber; RETURN; END

    IF NULLIF(LTRIM(RTRIM(@Subject)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'عنوانِ وظیفه الزامی است.' AS Message, CAST(NULL AS INT) AS MessageID, N'' AS MessageNumber; RETURN; END

    -- اولویت: در نبود/نامعتبری → پایین‌ترین اولویتِ فعال
    IF @msgPriorityID IS NULL OR NOT EXISTS (SELECT 1 FROM dbo.msgPriorities WHERE msgPriorityID = @msgPriorityID AND IsActive = 1)
        SELECT TOP 1 @msgPriorityID = msgPriorityID FROM dbo.msgPriorities WHERE IsActive = 1 ORDER BY SortOrder ASC;

    DECLARE @DefaultStatusID INT = (SELECT MessageStatusID FROM dbo.MessageStatuses WHERE MessageStatusName = N'ارسال شده' AND IsActive = 1);
    IF @DefaultStatusID IS NULL SET @DefaultStatusID = (SELECT MIN(MessageStatusID) FROM dbo.MessageStatuses);

    DECLARE @Assignees TABLE (UserID INT PRIMARY KEY);
    IF ISJSON(@AssigneesJson) = 1
        INSERT INTO @Assignees (UserID)
        SELECT DISTINCT j.userId
        FROM OPENJSON(@AssigneesJson) WITH (userId INT N'$.userId') j
        WHERE j.userId IS NOT NULL
          AND EXISTS (SELECT 1 FROM dbo.Users u WHERE u.UserID = j.userId AND u.IsActive = 1);

    IF @RequireAssignees = 1 AND NOT EXISTS (SELECT 1 FROM @Assignees)
    BEGIN SELECT 0 AS Success, N'هیچ انجام‌دهندهٔ معتبری برای این مرحله نیست.' AS Message, CAST(NULL AS INT) AS MessageID, N'' AS MessageNumber; RETURN; END

    DECLARE @Year SMALLINT = dbo.fn_JalaliYear(GETDATE());
    DECLARE @Format NVARCHAR(100) = (SELECT NumberFormat FROM dbo.MessageTypes WHERE MessageTypeID = @TaskTypeID);
    IF NULLIF(@Format, N'') IS NULL SET @Format = N'MSG-{YEAR}-{SEQ:6}';

    DECLARE @MessageID INT, @MessageNumber NVARCHAR(100) = N'';

    BEGIN TRY
        BEGIN TRAN;

        INSERT INTO dbo.Messages (RowGuid, MessageTypeID, msgPriorityID, SenderUserID, Subject, MessageText, CreateDate, CreateUser, DueDate)
        VALUES (NEWID(), @TaskTypeID, @msgPriorityID, @SenderUserID, @Subject, @MessageText, GETDATE(), @CreateUser, @DueDate);
        SET @MessageID = SCOPE_IDENTITY();

        -- شماره‌گذاری — همان شمارندهٔ MessageNumberCounters و همان fn_FormatMessageNumber موجود
        IF NOT EXISTS (SELECT 1 FROM dbo.MessageNumberCounters WHERE MessageTypeID = @TaskTypeID AND Year = @Year)
            INSERT INTO dbo.MessageNumberCounters (MessageTypeID, Year, LastNumber) VALUES (@TaskTypeID, @Year, 0);

        MERGE dbo.MessageNumberCounters WITH (HOLDLOCK) AS T
        USING (VALUES (@TaskTypeID, @Year)) AS S (MessageTypeID, Year)
            ON T.MessageTypeID = S.MessageTypeID AND T.Year = S.Year
        WHEN MATCHED THEN UPDATE SET LastNumber = T.LastNumber + 1
        WHEN NOT MATCHED THEN INSERT (MessageTypeID, Year, LastNumber) VALUES (@TaskTypeID, @Year, 1);

        DECLARE @Serial INT = (SELECT LastNumber FROM dbo.MessageNumberCounters WHERE MessageTypeID = @TaskTypeID AND Year = @Year);
        SET @MessageNumber = dbo.fn_FormatMessageNumber(@Format, @Year, @Serial);
        UPDATE dbo.Messages SET MessageNumber = @MessageNumber WHERE MessageID = @MessageID;

        -- یک ردیفِ MessageDetails به‌ازای هر assignee (← همین باعثِ دیده‌شدن در کارتابلِ موجود می‌شود).
        -- در مسیرِ Deferred (@RequireAssignees=0 و @Assignees خالی)، عمداً هیچ ردیفی درج نمی‌شود —
        -- هنوز کسی نباید این Message را در کارتابلِ خودش ببیند؛ adoptStepTask() بعداً این کار را می‌کند.
        INSERT INTO dbo.MessageDetails (RowGuid, MessageID, FromUserID, ToUserID, MessageStatusID, CreateDate, CreateUser)
        SELECT NEWID(), @MessageID, @SenderUserID, a.UserID, @DefaultStatusID, GETDATE(), @CreateUser
        FROM @Assignees a;

        INSERT INTO dbo.UserNotifications (UserID, MessageID, CreateDate, IsRead)
        SELECT a.UserID, @MessageID, GETDATE(), 0 FROM @Assignees a;

        COMMIT;
        SELECT 1 AS Success, N'وظیفهٔ فرایند ایجاد شد. شماره: ' + @MessageNumber AS Message, @MessageID AS MessageID, @MessageNumber AS MessageNumber;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK;
        SELECT 0 AS Success, N'خطا در ایجاد وظیفهٔ فرایند: ' + ERROR_MESSAGE() AS Message, CAST(NULL AS INT) AS MessageID, N'' AS MessageNumber;
    END CATCH
END
GO

