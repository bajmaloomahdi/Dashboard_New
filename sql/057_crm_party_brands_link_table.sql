/* ==========================================================================
   پچ خودکار شماره: 057 | نام: crm_party_brands_link_table
   تاریخ: 2026-09-25 15:14:48 | شامل 2 دستور SQL
   ========================================================================== */

-- [CREATE_TABLE] روی TABLE: CrmPartyBrands
CREATE TABLE dbo.CrmPartyBrands
(
    PartyBrandID  INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CrmPartyBrands PRIMARY KEY,
    PartyID       INT NOT NULL,
    BrandID       INT NOT NULL,
    IsActive      BIT NOT NULL CONSTRAINT DF_CrmPartyBrands_IsActive DEFAULT (1),
    RowGuid       UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_CrmPartyBrands_RowGuid DEFAULT (NEWID()),
    Date_InsertFirst   DATETIME2 NOT NULL CONSTRAINT DF_CrmPartyBrands_DateInsert DEFAULT (SYSDATETIME()),
    UserID_InsertFirst INT NULL,
    Date_LastUpdate     DATETIME2 NULL,
    UserID_LastUpdate   INT NULL,
    CONSTRAINT UQ_CrmPartyBrands_PartyID_BrandID UNIQUE (PartyID, BrandID),
    CONSTRAINT FK_CrmPartyBrands_Party FOREIGN KEY (PartyID) REFERENCES dbo.CrmParties(PartyID),
    CONSTRAINT FK_CrmPartyBrands_Brand FOREIGN KEY (BrandID) REFERENCES dbo.CrmBrands(BrandID)
)
GO

-- [CREATE_INDEX] روی INDEX: IX_CrmPartyBrands_BrandID
CREATE INDEX IX_CrmPartyBrands_BrandID ON dbo.CrmPartyBrands(BrandID)
GO


/* ===== [MANUAL DML] انتقالِ برندهایِ موجود به جدولِ ارتباط — DML است و توسطِ DDL-trigger ثبت نمی‌شود،
   پس دستی به انتهایِ این پچ اضافه شد (هم‌الگو با 041/045). باید قبل از پچِ بعدی (حذفِ CrmBrands.PartyID) اجرا شود.
   - هر برندِ فعلی یک ردیفِ ارتباط با طرف‌حسابِ خودش می‌گیرد؛ IsActive و Auditِ ارتباط از خودِ برند گرفته می‌شود.
   - غیرفعال‌بودنِ قبلی در سطحِ طرف‌حساب بوده، پس خودِ برند فعال می‌شود (Auditِ برند دست نمی‌خورد).
   Idempotent: فقط ارتباط‌هایِ ناموجود درج می‌شوند. ===== */
IF COL_LENGTH('dbo.CrmBrands', 'PartyID') IS NOT NULL
BEGIN
    EXEC(N'
    INSERT INTO dbo.CrmPartyBrands (PartyID, BrandID, IsActive, Date_InsertFirst, UserID_InsertFirst, Date_LastUpdate, UserID_LastUpdate)
    SELECT b.PartyID, b.BrandID, b.IsActive, b.Date_InsertFirst, b.UserID_InsertFirst, b.Date_LastUpdate, b.UserID_LastUpdate
    FROM dbo.CrmBrands b
    WHERE b.PartyID IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM dbo.CrmPartyBrands pb WHERE pb.PartyID = b.PartyID AND pb.BrandID = b.BrandID);

    UPDATE dbo.CrmBrands SET IsActive = 1 WHERE IsActive = 0;
    ');
END
GO
