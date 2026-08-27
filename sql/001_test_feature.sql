-- ۱. ساخت جدول تستی در صورت عدم وجود
IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.TABLES 
    WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'TestCompanySync'
)
BEGIN
    CREATE TABLE [dbo].[TestCompanySync] (
        [ID] INT IDENTITY(1,1) PRIMARY KEY,
        [CompanyName] NVARCHAR(100) NOT NULL,
        [CreatedAt] DATETIME NOT NULL DEFAULT GETDATE()
    );
END
GO

-- ۲. ساخت یا ویرایش Stored Procedure
CREATE OR ALTER PROCEDURE [dbo].[sp_InsertTestCompany]
    @CompanyName NVARCHAR(100)
AS
BEGIN
    SET NOCOUNT ON;

    INSERT INTO [dbo].[TestCompanySync] ([CompanyName])
    VALUES (@CompanyName);

    SELECT SCOPE_IDENTITY() AS NewID, @CompanyName AS CompanyName, GETDATE() AS CreatedAt;
END
GO