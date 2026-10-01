/* ==========================================================================
   پچ خودکار شماره: 063 | نام: crm_interaction_attachments
   تاریخ: 2026-09-27 16:02:04 | شامل 5 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: CrmInteractionAttachments
CREATE TABLE dbo.CrmInteractionAttachments (
    InteractionAttachmentID INT              IDENTITY(1,1) NOT NULL,
    RowGuid                 UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmInteractionAttachments_RowGuid DEFAULT (NEWID()),
    InteractionID           INT              NOT NULL,
    FileName                NVARCHAR(255)    NOT NULL,
    FileExtension           NVARCHAR(20)     NULL,
    FileSize                BIGINT           NOT NULL,
    FilePath                NVARCHAR(1000)   NOT NULL,
    Description             NVARCHAR(1000)   NULL,
    Date_InsertFirst        DATETIME2        NOT NULL CONSTRAINT DF_CrmInteractionAttachments_Insert DEFAULT SYSDATETIME(),
    UserID_InsertFirst      INT              NULL,
    Date_LastUpdate         DATETIME2        NULL,
    UserID_LastUpdate       INT              NULL,
    CONSTRAINT PK_CrmInteractionAttachments PRIMARY KEY (InteractionAttachmentID),
    CONSTRAINT FK_CrmInteractionAttachments_Interaction FOREIGN KEY (InteractionID) REFERENCES dbo.CrmInteractions(InteractionID),
    CONSTRAINT CK_CrmInteractionAttachments_FileSize CHECK (FileSize >= 0)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmInteractionAttachments_InteractionID
CREATE INDEX IX_CrmInteractionAttachments_InteractionID ON dbo.CrmInteractionAttachments(InteractionID)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetInteractionAttachments
-- فهرستِ پیوست‌ها (با FilePath برایِ لایهٔ PHP؛ PHP آن را حذف می‌کند). فیلترها هم‌سو با sp_Crm_GetInteractions.
CREATE PROCEDURE dbo.sp_Crm_GetInteractionAttachments
    @InteractionID           INT = NULL,
    @PartyID                 INT = NULL,
    @PersonID                INT = NULL,
    @InteractionAttachmentID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT a.InteractionAttachmentID, a.InteractionID, a.FileName, a.FileExtension, a.FileSize, a.FilePath, a.Description,
           a.Date_InsertFirst, a.UserID_InsertFirst,
           (u.FirstName + N' ' + u.LastName) AS CreatedByName
    FROM dbo.CrmInteractionAttachments a
    JOIN dbo.CrmInteractions i ON i.InteractionID = a.InteractionID
    LEFT JOIN dbo.Users u ON u.UserID = a.UserID_InsertFirst
    WHERE (@InteractionID           IS NULL OR a.InteractionID = @InteractionID)
      AND (@PartyID                 IS NULL OR i.PartyID = @PartyID)
      AND (@PersonID                IS NULL OR i.PersonID = @PersonID)
      AND (@InteractionAttachmentID IS NULL OR a.InteractionAttachmentID = @InteractionAttachmentID)
    ORDER BY a.InteractionID, a.InteractionAttachmentID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_AddInteractionAttachment
CREATE PROCEDURE dbo.sp_Crm_AddInteractionAttachment
    @InteractionID INT,
    @FileName      NVARCHAR(255),
    @FileExtension NVARCHAR(20)   = NULL,
    @FileSize      BIGINT,
    @FilePath      NVARCHAR(1000),
    @Description   NVARCHAR(1000) = NULL,
    @UserID        INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmInteractions WHERE InteractionID = @InteractionID)
    BEGIN SELECT 0 AS Success, N'تعامل یافت نشد.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@FileName)), N'') IS NULL OR NULLIF(LTRIM(RTRIM(@FilePath)), N'') IS NULL OR @FileSize IS NULL OR @FileSize < 0
    BEGIN SELECT 0 AS Success, N'اطلاعاتِ فایلِ پیوست ناقص است.' AS Message; RETURN; END

    INSERT INTO dbo.CrmInteractionAttachments (InteractionID, FileName, FileExtension, FileSize, FilePath, Description,
                                               Date_InsertFirst, UserID_InsertFirst)
    VALUES (@InteractionID, @FileName, NULLIF(@FileExtension, N''), @FileSize, @FilePath, NULLIF(LTRIM(RTRIM(@Description)), N''),
            SYSDATETIME(), @UserID);

    SELECT 1 AS Success, N'پیوست ثبت شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS InteractionAttachmentID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_DeleteInteractionAttachment
-- حذفِ قطعیِ ردیف؛ FilePath برمی‌گردد تا لایهٔ PHP فایل را فقط بعد از حذفِ موفق پاک کند.
CREATE PROCEDURE dbo.sp_Crm_DeleteInteractionAttachment
    @InteractionAttachmentID INT,
    @UserID                  INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @FilePath NVARCHAR(1000), @InteractionID INT;
    SELECT @FilePath = FilePath, @InteractionID = InteractionID
    FROM dbo.CrmInteractionAttachments WHERE InteractionAttachmentID = @InteractionAttachmentID;
    IF @InteractionID IS NULL
    BEGIN SELECT 0 AS Success, N'پیوست یافت نشد.' AS Message; RETURN; END

    DELETE FROM dbo.CrmInteractionAttachments WHERE InteractionAttachmentID = @InteractionAttachmentID;

    SELECT 1 AS Success, N'پیوست حذف شد.' AS Message, @InteractionID AS InteractionID, @FilePath AS FilePath;
END
GO

