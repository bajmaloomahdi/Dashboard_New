/* ==========================================================================
   Raga 360 - Global Search: messages & tasks (engine B, base tables)
   Version : 1.1 (2026-10-15) - 1.1: search text kept out of plan cache and Query Store - applied ONLY to (localdb)\GsTest / Dashboard_GsTest
   Object  : dbo.sp_Gs_SearchMessages   (uses dbo.fn_Gs_Normalize from gs_search_crm.sql)
   Spec    : GlobalSearch_Spec_AccessMatrix.md sections 6-7 (number + subject only)

   ACCESS RULE (identical to the message page - MessageAccessChecker / MessageController::show):
     a user may see a message if they are
       - the sender                    (Messages.SenderUserID), or
       - any recipient in the chain    (MessageDetails.ToUserID - current OR earlier forward), or
       - a copy recipient              (MessageCopies.UserID).
     No role or permission (not even an admin role) adds visibility.
     The user must also be active (Users.IsActive = 1); otherwise the result is empty.
   - Visibility is applied BEFORE matching and ranking, so titles, counts and the
     extra row used for hasMore can only come from messages the user may see.
   - Searches MessageNumber and Subject only (never MessageText).
   - Fully parameterized; LIKE wildcards are escaped by the caller. Read-only.
   - Plain ASCII on purpose; the task type name is built from NCHAR code points.
   ========================================================================== */
SET NOCOUNT ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;
GO

IF CAST(SERVERPROPERTY('IsLocalDB') AS INT) <> 1 OR DB_NAME() <> N'Dashboard_GsTest'
    THROW 53000, N'SAFETY: this script may only run on (localdb)\GsTest / Dashboard_GsTest.', 1;
IF OBJECT_ID(N'dbo.fn_Gs_Normalize', N'FN') IS NULL
    THROW 53001, N'dbo.fn_Gs_Normalize is missing - apply gs_search_crm.sql first.', 1;
GO

CREATE OR ALTER PROCEDURE dbo.sp_Gs_SearchMessages
    @UserID    INT,
    @Query     NVARCHAR(100),        -- normalized by PersianNormalizer (not escaped)
    @QueryLike NVARCHAR(400),        -- normalized + LIKE-escaped
    @T1 NVARCHAR(400) = NULL, @T2 NVARCHAR(400) = NULL, @T3 NVARCHAR(400) = NULL,
    @T4 NVARCHAR(400) = NULL, @T5 NVARCHAR(400) = NULL, @T6 NVARCHAR(400) = NULL,   -- LIKE-escaped tokens (AND)
    @Top       INT = 5
AS
BEGIN
    SET NOCOUNT ON;
    SET LOCK_TIMEOUT 2000;

    -- PRIVACY (audit finding 2): the search text must not be kept as a sniffed ParameterCompiledValue
    -- in the plan cache or Query Store. Parameters are copied once into local variables (plain scalar
    -- assignments), only the locals are used below, and the search statement is compiled with
    -- OPTIMIZE FOR UNKNOWN so that not even a statement-level recompile sniffs their values.
    DECLARE @vUserID INT = @UserID,
            @vQuery NVARCHAR(100) = @Query, @vQueryLike NVARCHAR(400) = @QueryLike,
            @vT1 NVARCHAR(400) = @T1, @vT2 NVARCHAR(400) = @T2, @vT3 NVARCHAR(400) = @T3,
            @vT4 NVARCHAR(400) = @T4, @vT5 NVARCHAR(400) = @T5, @vT6 NVARCHAR(400) = @T6,
            @vTop INT = @Top;
    DECLARE @vValid BIT = CASE WHEN @vQuery IS NOT NULL AND LEN(@vQuery) >= 1 AND @vQueryLike IS NOT NULL THEN 1 ELSE 0 END;

    IF @vTop IS NULL OR @vTop < 1 SET @vTop = 1;
    IF @vTop > 50 SET @vTop = 50;

    DECLARE @allowed BIT = 0;
    IF @vValid = 1
       AND EXISTS (SELECT 1 FROM dbo.Users u WHERE u.UserID = @vUserID AND u.IsActive = 1)
        SET @allowed = 1;

    -- Name of the task message type used by the application (Persian word for task), built from code points
    DECLARE @TaskTypeName NVARCHAR(20) = NCHAR(0x0648) + NCHAR(0x0638) + NCHAR(0x06CC) + NCHAR(0x0641) + NCHAR(0x0647);

    ;WITH visible AS (
        SELECT m.MessageID FROM dbo.Messages m WHERE @allowed = 1 AND m.SenderUserID = @vUserID
        UNION
        SELECT d.MessageID FROM dbo.MessageDetails d WHERE @allowed = 1 AND d.ToUserID = @vUserID
        UNION
        SELECT c.MessageID FROM dbo.MessageCopies c WHERE @allowed = 1 AND c.UserID = @vUserID
    ),
    cand AS (
        SELECT m.MessageID, m.Subject, m.MessageNumber, m.CreateDate, mt.MessageTypeName,
               dbo.fn_Gs_Normalize(m.Subject) AS N1,
               dbo.fn_Gs_Normalize(m.MessageNumber) AS C1
        FROM visible v
        JOIN dbo.Messages m      ON m.MessageID = v.MessageID
        JOIN dbo.MessageTypes mt ON mt.MessageTypeID = m.MessageTypeID
    ),
    hay AS (
        SELECT cand.*, ISNULL(N1, N'') + N' ' + ISNULL(C1, N'') AS H
        FROM cand
    ),
    matched AS (
        SELECT hay.*,
               CASE
                   WHEN C1 = @vQuery                         THEN 1000
                   WHEN N1 = @vQuery                         THEN 500
                   WHEN N1 LIKE @vQueryLike + N'%'           THEN 400
                   WHEN N1 LIKE N'% ' + @vQueryLike + N'%'   THEN 300
                   WHEN N1 LIKE N'%' + @vQueryLike + N'%'    THEN 200
                   ELSE 100
               END AS Score
        FROM hay
        WHERE (@vT1 IS NULL OR H LIKE N'%' + @vT1 + N'%')
          AND (@vT2 IS NULL OR H LIKE N'%' + @vT2 + N'%')
          AND (@vT3 IS NULL OR H LIKE N'%' + @vT3 + N'%')
          AND (@vT4 IS NULL OR H LIKE N'%' + @vT4 + N'%')
          AND (@vT5 IS NULL OR H LIKE N'%' + @vT5 + N'%')
          AND (@vT6 IS NULL OR H LIKE N'%' + @vT6 + N'%')
          AND (@vT1 IS NOT NULL OR H LIKE N'%' + @vQueryLike + N'%')
    )
    SELECT TOP (@vTop + 1)
           CASE WHEN x.MessageTypeName = @TaskTypeName THEN N'task' ELSE N'message' END AS ItemType,
           x.MessageID AS ItemID,
           x.Subject AS Title,
           x.MessageNumber,
           x.MessageTypeName AS TypeName,
           CONVERT(NVARCHAR(10), x.CreateDate, 120) AS CreatedOn,
           (SELECT TOP 1 ms.MessageStatusName
              FROM dbo.MessageDetails d2
              JOIN dbo.MessageStatuses ms ON ms.MessageStatusID = d2.MessageStatusID
             WHERE d2.MessageID = x.MessageID
             ORDER BY d2.CreateDate DESC, d2.MessageDetailID DESC) AS LastStatusName,
           x.Score
    FROM matched x
    ORDER BY x.Score DESC, x.CreateDate DESC, x.MessageID DESC
    OPTION (OPTIMIZE FOR UNKNOWN);
END
GO
