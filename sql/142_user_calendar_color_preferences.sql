-- Per-user calendar color palette
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'dbo.UserCalendarColorPreferences', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.UserCalendarColorPreferences (
         UserID INT NOT NULL PRIMARY KEY REFERENCES dbo.Users(UserID),
         Palette NVARCHAR(4000) NOT NULL CHECK (ISJSON(Palette, OBJECT)=1)
        );
    END;

    EXEC sys.sp_executesql N'CREATE OR ALTER PROCEDURE dbo.sp_UserCalendarColorPreferences_Get @UserID INT AS
BEGIN
 SET NOCOUNT ON;
 SELECT Palette FROM dbo.UserCalendarColorPreferences WHERE UserID=@UserID;
END;';

    EXEC sys.sp_executesql N'CREATE OR ALTER PROCEDURE dbo.sp_UserCalendarColorPreferences_Save @UserID INT, @Palette NVARCHAR(4000) AS
BEGIN
 SET NOCOUNT ON;
 SET XACT_ABORT ON;
 BEGIN TRAN;
 UPDATE dbo.UserCalendarColorPreferences WITH (UPDLOCK, SERIALIZABLE) SET Palette=@Palette WHERE UserID=@UserID;
 IF @@ROWCOUNT=0 INSERT dbo.UserCalendarColorPreferences (UserID,Palette) VALUES (@UserID,@Palette);
 COMMIT;
END;';

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
