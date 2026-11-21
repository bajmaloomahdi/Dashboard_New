CREATE OR ALTER PROCEDURE dbo.sp_GetRelatedAttachmentSources
    @SourceType varchar(10), @EntityID bigint, @UserID int
AS
BEGIN
    SET NOCOUNT ON;

    -- Source access and the relationship are independent checks.
    DECLARE @IsMessageParticipant bit = 0;
    IF @SourceType = 'MESSAGE' AND (
        EXISTS (SELECT 1 FROM dbo.Messages WHERE MessageID=@EntityID AND SenderUserID=@UserID)
        OR EXISTS (SELECT 1 FROM dbo.MessageDetails WHERE MessageID=@EntityID AND ToUserID=@UserID)
        OR EXISTS (SELECT 1 FROM dbo.MessageCopies WHERE MessageID=@EntityID AND UserID=@UserID)
    ) SET @IsMessageParticipant = 1;

    SELECT DISTINCT
        CASE WHEN @SourceType='EVENT' THEN l.LinkType ELSE 'EVENT' END AS SourceType,
        CASE WHEN @SourceType='EVENT' THEN l.EntityID ELSE l.EventID END AS EntityID
    FROM dbo.CalendarEventLinks l
    INNER JOIN dbo.CalendarEvents e ON e.EventID=l.EventID AND e.IsActive=1
    WHERE l.IsActive=1 AND (
        (@SourceType='EVENT' AND l.EventID=@EntityID AND (
            (l.LinkType='PROJECT' AND EXISTS (
                SELECT 1 FROM dbo.ProjectMembers m WHERE m.ProjectID=l.EntityID AND m.UserID=@UserID AND m.IsActive=1
            ))
            OR (l.LinkType='MESSAGE' AND (
                EXISTS (SELECT 1 FROM dbo.Messages WHERE MessageID=l.EntityID AND SenderUserID=@UserID)
                OR EXISTS (SELECT 1 FROM dbo.MessageDetails WHERE MessageID=l.EntityID AND ToUserID=@UserID)
                OR EXISTS (SELECT 1 FROM dbo.MessageCopies WHERE MessageID=l.EntityID AND UserID=@UserID)
            ))
        ))
        OR (l.LinkType=@SourceType AND l.EntityID=@EntityID AND (
            (@SourceType='MESSAGE' AND @IsMessageParticipant=1)
            OR (@SourceType='PROJECT' AND EXISTS (
                SELECT 1 FROM dbo.ProjectMembers m WHERE m.ProjectID=@EntityID AND m.UserID=@UserID AND m.IsActive=1
            ))
        ))
    );
END
GO

CREATE OR ALTER PROCEDURE dbo.sp_Calendar_GetSharedAttachmentMetadata @EventID bigint
AS
BEGIN
    SET NOCOUNT ON;
    SELECT CalendarEventAttachmentID, FileName, Description,
           CONVERT(varchar(19), OriginalStart, 120) AS OriginalStart
    FROM dbo.CalendarEventAttachments
    WHERE EventID=@EventID AND IsActive=1
    ORDER BY CalendarEventAttachmentID DESC;
END
GO
