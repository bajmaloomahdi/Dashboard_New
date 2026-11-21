SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO
CREATE OR ALTER PROCEDURE dbo.sp_UserGridColumnPreferences_Save
    @UserID INT,
    @GridKey VARCHAR(MAX),
    @VisibilityJson NVARCHAR(MAX),
    @SchemaVersion SMALLINT,
    @ExpectedVersion BINARY(8)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @UserID IS NULL OR @UserID <= 0 OR @GridKey IS NULL
        OR DATALENGTH(@GridKey) NOT BETWEEN 1 AND 128
        OR @GridKey COLLATE Latin1_General_100_BIN2 LIKE '%[^a-z0-9._-]%'
        OR @GridKey COLLATE Latin1_General_100_BIN2 NOT LIKE '[a-z0-9]%'
        OR DATALENGTH(@GridKey) <> LEN(@GridKey)
        THROW 51040, 'Invalid user or grid key.', 1;

    DECLARE @Key VARCHAR(128) = @GridKey;

    IF @SchemaVersion IS NULL OR @SchemaVersion <= 0
        OR @VisibilityJson IS NULL OR DATALENGTH(@VisibilityJson) > 8000
        OR ISJSON(@VisibilityJson, OBJECT) <> 1
        THROW 51040, 'Invalid grid column preferences.', 1;

    IF EXISTS (
        SELECT 1 FROM OPENJSON(@VisibilityJson)
        WHERE ([type] <> 3 AND NOT (@SchemaVersion = 3 AND [type] = 5)) OR DATALENGTH([key]) NOT BETWEEN 2 AND 256
            OR [key] COLLATE Latin1_General_100_BIN2 LIKE N'%[^A-Za-z0-9._-]%'
            OR DATALENGTH([key]) <> 2 * LEN([key])
    ) OR EXISTS (
        SELECT 1 FROM OPENJSON(@VisibilityJson)
        GROUP BY [key] COLLATE Latin1_General_100_BIN2
        HAVING COUNT(*) > 1
    )
        THROW 51040, 'Invalid or duplicate column key, or non-boolean visibility.', 1;

    IF @SchemaVersion = 3 AND EXISTS (
        SELECT 1
        FROM OPENJSON(@VisibilityJson) AS columnSetting
        CROSS APPLY (
            SELECT COUNT(*) AS MemberCount,
                COUNT(CASE WHEN [key] = N'visible' AND [type] = 3 THEN 1 END) AS VisibilityCount,
                COUNT(CASE WHEN [key] = N'width' AND [type] = 2
                    AND TRY_CONVERT(int, [value]) BETWEEN 60 AND 800 THEN 1 END) AS WidthCount
            FROM OPENJSON(CASE WHEN columnSetting.[type] = 5 THEN columnSetting.[value] ELSE N'{}' END)
        ) AS members
        WHERE columnSetting.[type] = 5
            AND (members.MemberCount <> 2 OR members.VisibilityCount <> 1 OR members.WidthCount <> 1)
    )
        THROW 51040, 'Invalid column width settings.', 1;

    DECLARE @Saved TABLE (VisibilityJson NVARCHAR(4000), SchemaVersion SMALLINT,
                          UpdatedAtUtc DATETIME2(3), VersionToken BINARY(8));

    DECLARE @CurrentVersion BINARY(8);
    BEGIN TRY
        BEGIN TRANSACTION;

        -- Lock the key or its gap so concurrent first writes cannot race.
        SELECT @CurrentVersion = VersionToken
        FROM dbo.UserGridColumnPreferences WITH (UPDLOCK, HOLDLOCK)
        WHERE UserID = @UserID AND GridKey = @Key;

        IF (@CurrentVersion IS NULL AND @ExpectedVersion IS NOT NULL)
            OR (@CurrentVersion IS NOT NULL AND (@ExpectedVersion IS NULL OR @CurrentVersion <> @ExpectedVersion))
            THROW 51041, 'Grid column preferences version conflict.', 1;

        IF @CurrentVersion IS NULL
            INSERT dbo.UserGridColumnPreferences (UserID, GridKey, VisibilityJson, SchemaVersion)
            OUTPUT inserted.VisibilityJson, inserted.SchemaVersion, inserted.UpdatedAtUtc, inserted.VersionToken INTO @Saved
            VALUES (@UserID, @Key, @VisibilityJson, @SchemaVersion);
        ELSE
            UPDATE dbo.UserGridColumnPreferences
            SET VisibilityJson = @VisibilityJson, SchemaVersion = @SchemaVersion, UpdatedAtUtc = SYSUTCDATETIME()
            OUTPUT inserted.VisibilityJson, inserted.SchemaVersion, inserted.UpdatedAtUtc, inserted.VersionToken INTO @Saved
            WHERE UserID = @UserID AND GridKey = @Key;

        COMMIT TRANSACTION;
        SELECT VisibilityJson, SchemaVersion, UpdatedAtUtc,
               CONVERT(CHAR(16), VersionToken, 2) AS VersionToken
        FROM @Saved;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO
