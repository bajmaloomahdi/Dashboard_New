-- Project deadline event registration and participant sync
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'dbo.ProjectDeadlineEvents', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.ProjectDeadlineEvents (
         ProjectID bigint NOT NULL PRIMARY KEY REFERENCES dbo.Projects(ProjectID),
         EventID bigint NOT NULL UNIQUE REFERENCES dbo.CalendarEvents(EventID)
        );
    END;

    EXEC sys.sp_executesql N'CREATE OR ALTER PROCEDURE dbo.sp_SyncProjectDeadlineEvent
 @ProjectID bigint, @ActorUserID int, @EventID bigint = NULL
AS
BEGIN
 SET NOCOUNT ON;
 IF @EventID IS NOT NULL
 BEGIN
   IF NOT EXISTS (SELECT 1 FROM dbo.Projects p JOIN dbo.CalendarEventLinks l ON l.EntityID=p.ProjectID AND l.LinkType=''PROJECT'' AND l.IsActive=1 JOIN dbo.CalendarEvents e ON e.EventID=l.EventID AND e.IsActive=1
       WHERE p.ProjectID=@ProjectID AND e.EventID=@EventID AND p.UserID_InsertFirst=@ActorUserID AND e.CreatedByUserID=@ActorUserID)
       THROW 51000,''Invalid deadline event registration'',1;
   INSERT dbo.ProjectDeadlineEvents(ProjectID,EventID) VALUES (@ProjectID,@EventID);
   RETURN;
 END;
 IF NOT EXISTS (SELECT 1 FROM dbo.ProjectMembers WHERE ProjectID=@ProjectID AND UserID=@ActorUserID AND IsActive=1 AND IsResponsible=1)
   THROW 51000,''Only the active project manager can sync the deadline event'',1;

 SELECT @EventID=d.EventID FROM dbo.ProjectDeadlineEvents d WITH (UPDLOCK,HOLDLOCK)
 JOIN dbo.CalendarEvents e ON e.EventID=d.EventID AND e.IsActive=1 WHERE d.ProjectID=@ProjectID;
 IF @EventID IS NULL RETURN;

 UPDATE a SET IsActive=1, ResponseStatus=N''PENDING'', InvitedAt=SYSDATETIME()
 FROM dbo.CalendarEventAttendees a JOIN dbo.ProjectMembers m ON m.UserID=a.UserID AND m.ProjectID=@ProjectID AND m.IsActive=1
 WHERE a.EventID=@EventID AND a.IsActive=0;

 INSERT dbo.CalendarEventAttendees (EventID,UserID,RelationType,ResponseStatus,InvitedAt,UserID_InsertFirst)
 SELECT @EventID,m.UserID,N''PARTICIPANT'',N''PENDING'',SYSDATETIME(),@ActorUserID
 FROM (SELECT DISTINCT UserID FROM dbo.ProjectMembers WHERE ProjectID=@ProjectID AND IsActive=1) m
 WHERE NOT EXISTS (SELECT 1 FROM dbo.CalendarEventAttendees a WHERE a.EventID=@EventID AND a.UserID=m.UserID);

 UPDATE l SET IsActive=1, Date_LastUpdate=SYSDATETIME(),UserID_LastUpdate=@ActorUserID
 FROM dbo.CalendarEventLinks l JOIN dbo.ProjectContractors c ON c.PartyID=l.EntityID AND c.ProjectID=@ProjectID AND c.IsActive=1
 WHERE l.EventID=@EventID AND l.LinkType=''PARTY'' AND l.IsActive=0;

 INSERT dbo.CalendarEventLinks (EventID,LinkType,EntityID,UserID_InsertFirst)
 SELECT @EventID,''PARTY'',c.PartyID,@ActorUserID
 FROM (SELECT DISTINCT PartyID FROM dbo.ProjectContractors WHERE ProjectID=@ProjectID AND IsActive=1) c
 WHERE NOT EXISTS (SELECT 1 FROM dbo.CalendarEventLinks l WHERE l.EventID=@EventID AND l.LinkType=''PARTY'' AND l.EntityID=c.PartyID);
END;';

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
