/* ==========================================================================
   پچ خودکار شماره: 075 | نام: crm_party_images
   تاریخ: 2026-10-01 13:09:53 | شامل 5 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: CrmPartyImages
CREATE TABLE dbo.CrmPartyImages
(
    PartyImageID       INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmPartyImages PRIMARY KEY,
    PartyID            INT NOT NULL,
    ImagePath          NVARCHAR(500) NOT NULL,
    ImageMimeType      NVARCHAR(50)  NOT NULL,
    SortOrder          INT NOT NULL CONSTRAINT DF_CrmPartyImages_SortOrder DEFAULT (0),
    IsActive           BIT NOT NULL CONSTRAINT DF_CrmPartyImages_IsActive DEFAULT (1),
    RowGuid            UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmPartyImages_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmPartyImages_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate    DATETIME2 NULL,
    UserID_LastUpdate  INT NULL,
    CONSTRAINT FK_CrmPartyImages_Party FOREIGN KEY (PartyID) REFERENCES dbo.CrmParties(PartyID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmPartyImages_PartyID
CREATE INDEX IX_CrmPartyImages_PartyID ON dbo.CrmPartyImages(PartyID, IsActive)
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_GetPartyImages
-- 2) sp_Crm_GetPartyImages — فهرستِ تصاویرِ فعال (با فیلترِ اختیاریِ PartyImageID برایِ Routeِ نمایشِ تکی)
CREATE PROCEDURE dbo.sp_Crm_GetPartyImages
    @PartyID      INT = NULL,
    @PartyImageID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT PartyImageID, PartyID, ImagePath, ImageMimeType, SortOrder, IsActive,
           Date_InsertFirst, UserID_InsertFirst
    FROM dbo.CrmPartyImages
    WHERE IsActive = 1
      AND (@PartyID      IS NULL OR PartyID      = @PartyID)
      AND (@PartyImageID IS NULL OR PartyImageID = @PartyImageID)
    ORDER BY SortOrder, PartyImageID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_AddPartyImage
-- 3) sp_Crm_AddPartyImage
CREATE PROCEDURE dbo.sp_Crm_AddPartyImage
    @PartyID       INT,
    @ImagePath     NVARCHAR(500),
    @ImageMimeType NVARCHAR(50),
    @SortOrder     INT = NULL,
    @UserID        INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmParties WHERE PartyID = @PartyID)
    BEGIN SELECT 0 AS Success, N'طرف‌حساب یافت نشد.' AS Message; RETURN; END
    IF NULLIF(LTRIM(RTRIM(@ImagePath)), N'') IS NULL
    BEGIN SELECT 0 AS Success, N'مسیرِ تصویر نامعتبر است.' AS Message; RETURN; END

    IF @SortOrder IS NULL
        SELECT @SortOrder = ISNULL(MAX(SortOrder), 0) + 1 FROM dbo.CrmPartyImages WHERE PartyID = @PartyID AND IsActive = 1;

    INSERT INTO dbo.CrmPartyImages (PartyID, ImagePath, ImageMimeType, SortOrder, IsActive, Date_InsertFirst, UserID_InsertFirst)
    VALUES (@PartyID, @ImagePath, @ImageMimeType, @SortOrder, 1, SYSDATETIME(), @UserID);

    SELECT 1 AS Success, N'تصویر ثبت شد.' AS Message, CAST(SCOPE_IDENTITY() AS INT) AS PartyImageID;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_Crm_DeletePartyImage
-- 4) sp_Crm_DeletePartyImage — حذفِ منطقی (IsActive=0)؛ فایلِ فیزیکی روی دیسک باقی می‌ماند
CREATE PROCEDURE dbo.sp_Crm_DeletePartyImage
    @PartyImageID INT,
    @UserID       INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.CrmPartyImages WHERE PartyImageID = @PartyImageID AND IsActive = 1)
    BEGIN SELECT 0 AS Success, N'تصویر یافت نشد.' AS Message; RETURN; END

    UPDATE dbo.CrmPartyImages
    SET IsActive = 0, Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE PartyImageID = @PartyImageID;

    SELECT 1 AS Success, N'تصویر حذف شد.' AS Message;
END
GO

