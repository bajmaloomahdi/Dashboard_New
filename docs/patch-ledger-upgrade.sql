-- Run separately after approval. Existing rows are preserved without inferred hashes.
SET XACT_ABORT ON;
BEGIN TRY
    BEGIN TRANSACTION;
    DECLARE @LockResult int;
    EXEC @LockResult = sys.sp_getapplock @Resource = N'Raga360:db:patch',
        @LockMode = 'Exclusive', @LockOwner = 'Transaction', @LockTimeout = 0;
    IF @LockResult < 0 THROW 51000, 'Could not lock the patch ledger.', 1;
    IF OBJECT_ID(N'dbo.SysPatches', N'U') IS NULL
        CREATE TABLE dbo.SysPatches (
            PatchID int IDENTITY PRIMARY KEY, PatchName nvarchar(255) NOT NULL,
            AppliedAt datetime NOT NULL DEFAULT GETDATE(), AppVersion nvarchar(50) NULL,
            ExecutionMs int NULL, Status nvarchar(20) NOT NULL, ErrorMessage nvarchar(max) NULL
        );
    IF COL_LENGTH('dbo.SysPatches', 'PatchNumber') IS NULL
        ALTER TABLE dbo.SysPatches ADD PatchNumber int NULL;
    IF COL_LENGTH('dbo.SysPatches', 'Sha256') IS NULL
        ALTER TABLE dbo.SysPatches ADD Sha256 char(64) NULL;
    IF COL_LENGTH('dbo.SysPatches', 'Evidence') IS NULL
        ALTER TABLE dbo.SysPatches ADD Evidence nvarchar(max) NULL;
    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.SysPatches') AND name = N'UX_SysPatches_PatchNumber')
        EXEC(N'CREATE UNIQUE INDEX UX_SysPatches_PatchNumber ON dbo.SysPatches(PatchNumber) WHERE PatchNumber IS NOT NULL;');
    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
