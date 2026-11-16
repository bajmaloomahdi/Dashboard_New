CREATE TABLE dbo.CalendarOccurrenceEdits (
 EventID bigint NOT NULL REFERENCES dbo.CalendarEvents(EventID),
 OriginalStart datetime2(0) NOT NULL,
 StartDateTime datetime2(0) NOT NULL,
 EndDateTime datetime2(0) NOT NULL,
 IsAllDay bit NOT NULL,
 Color nvarchar(20) NULL,
 Title nvarchar(250) NULL,
 Description nvarchar(max) NULL,
 CONSTRAINT PK_CalendarOccurrenceEdits PRIMARY KEY(EventID,OriginalStart),
 CONSTRAINT CK_CalendarOccurrenceEdits_Dates CHECK(EndDateTime >= StartDateTime)
);
GO
CREATE OR ALTER PROCEDURE dbo.sp_Calendar_SaveOccurrenceEdit
 @EventID bigint,@OriginalStart datetime2(0),@StartDateTime datetime2(0),@EndDateTime datetime2(0),
 @IsAllDay bit,@Color nvarchar(20),@Title nvarchar(250),@Description nvarchar(max),@UserID int
AS
BEGIN
 SET NOCOUNT ON;
 SET XACT_ABORT ON;
 BEGIN TRAN;
 IF NOT EXISTS (SELECT 1 FROM dbo.CalendarEvents e WITH (UPDLOCK,HOLDLOCK) WHERE e.EventID=@EventID AND e.IsActive=1
     AND e.RecurrenceType<>N'NONE' AND (e.CreatedByUserID=@UserID OR e.OwnerUserID=@UserID OR
     (EXISTS(SELECT 1 FROM dbo.fn_UserActiveUnits(@UserID) u WHERE u.UnitID=e.OrganizationalUnitID)
      AND EXISTS(SELECT 1 FROM dbo.fn_UserHasPermission(@UserID,N'CALENDAR_UNIT_MANAGE')))))
 BEGIN
   ROLLBACK;
   SELECT 0 AS Success;
   RETURN;
 END;
 UPDATE dbo.CalendarOccurrenceEdits
 SET StartDateTime=@StartDateTime,EndDateTime=@EndDateTime,IsAllDay=@IsAllDay,Color=@Color,
     Title=COALESCE(@Title,Title),Description=CASE WHEN @Title IS NULL THEN Description ELSE @Description END
 WHERE EventID=@EventID AND OriginalStart=@OriginalStart;
 IF @@ROWCOUNT=0
   INSERT dbo.CalendarOccurrenceEdits(EventID,OriginalStart,StartDateTime,EndDateTime,IsAllDay,Color,Title,Description)
   VALUES(@EventID,@OriginalStart,@StartDateTime,@EndDateTime,@IsAllDay,@Color,@Title,@Description);
 COMMIT;
 SELECT 1 AS Success;
END;
GO
CREATE OR ALTER PROCEDURE dbo.sp_Calendar_GetOccurrenceEdits @EventIDs nvarchar(max)
AS
BEGIN
 SET NOCOUNT ON;
 SELECT e.* FROM dbo.CalendarOccurrenceEdits e
 JOIN OPENJSON(@EventIDs) WITH (EventID bigint '$') ids ON ids.EventID=e.EventID;
END;
GO
