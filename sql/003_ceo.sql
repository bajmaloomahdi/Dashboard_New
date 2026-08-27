/* ==========================================================================
   پچ خودکار شماره: 003 | نام: ceo
   تاریخ: 2026-08-27 16:22:55 | شامل 2 دستور SQL
   ========================================================================== */

-- [ALTER_TABLE] روی TABLE: TestCompanySync
ALTER TABLE dbo.TestCompanySync ADD
	CEO nvarchar(50) NULL
GO

-- [ALTER_TABLE] روی TABLE: TestCompanySync
ALTER TABLE dbo.TestCompanySync SET (LOCK_ESCALATION = TABLE)
GO

