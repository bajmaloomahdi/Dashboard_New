SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO
CREATE OR ALTER PROCEDURE dbo.sp_Calendar_GetCrmEvents
    @EntityID BIGINT,
    @LinkType  varchar(10),
    @FromDate  DATETIME2(0),
    @ToDate    DATETIME2(0),
    @ViewerID  INT
AS
BEGIN
    SET NOCOUNT ON;
    IF @LinkType IS NULL OR @LinkType NOT IN ('PARTY', 'PERSON') THROW 51000, 'Invalid CRM link type.', 1;
    SELECT
        e.EventID,
        CASE WHEN vp.HideDetails = 1 THEN N'مشغول' ELSE e.Title END AS Title,
        CASE WHEN vp.HideDetails = 1 THEN NULL ELSE e.Description END AS Description,
        e.StartDateTime, e.EndDateTime, e.IsAllDay, e.Color, e.Status,
        e.RecurrenceType, e.RecurrenceInterval, e.RecurrenceWeekDays, e.RecurrenceEndDate,
        CASE WHEN vp.HideDetails = 1 THEN NULL ELSE e.CreatedByUserID END AS CreatedByUserID,
        e.OwnerUserID, e.OrganizationalUnitID, e.IsPrivate,
        vp.IsParticipant AS ViewerIsParticipant,
        CAST(vp.HideDetails AS BIT) AS IsBusyOnly,
        CASE WHEN vp.HideDetails = 1 THEN NULL ELSE dbo.fn_GetUserFullName(cu.FirstName, cu.LastName, cu.UserName) END AS CreatorName,
        dbo.fn_GetUserFullName(ou.FirstName, ou.LastName, ou.UserName) AS OwnerName,
        CAST(CASE WHEN e.CreatedByUserID = @ViewerID OR e.OwnerUserID = @ViewerID THEN 1 ELSE 0 END AS BIT) AS CanManage,
        CASE
            WHEN e.CreatedByUserID = @ViewerID THEN N'CREATOR'
            WHEN e.OwnerUserID = @ViewerID THEN N'OWNER'
            WHEN att.CalendarEventAttendeeID IS NOT NULL THEN N'ATTENDEE'
            ELSE N'NONE'
        END AS MyRelation,
        att.ResponseStatus AS MyResponseStatus,
        rem.OffsetMinutes  AS MyReminderOffsetMinutes
    FROM dbo.CalendarEvents e
    INNER JOIN dbo.CalendarEventLinks l ON l.EventID = e.EventID AND l.LinkType = @LinkType AND l.EntityID = @EntityID AND l.IsActive = 1
    LEFT JOIN dbo.Users cu ON cu.UserID = e.CreatedByUserID
    LEFT JOIN dbo.Users ou ON ou.UserID = e.OwnerUserID
    LEFT JOIN dbo.CalendarEventAttendees att ON att.EventID = e.EventID AND att.UserID = @ViewerID AND att.IsActive = 1
    LEFT JOIN dbo.Reminders rem ON rem.EntityType = N'CALENDAR_EVENT' AND rem.EntityID = e.EventID AND rem.UserID = @ViewerID AND rem.IsActive = 1
    CROSS APPLY (SELECT CAST(CASE WHEN e.CreatedByUserID = @ViewerID OR e.OwnerUserID = @ViewerID OR att.CalendarEventAttendeeID IS NOT NULL
                    THEN 1 ELSE 0 END AS BIT) AS IsParticipant) vpp
    CROSS APPLY (SELECT vpp.IsParticipant, CAST(CASE WHEN e.IsPrivate = 1 AND vpp.IsParticipant = 0 THEN 1 ELSE 0 END AS BIT) AS HideDetails) vp
    WHERE e.IsActive = 1
      AND (
            (e.RecurrenceType = N'NONE' AND e.StartDateTime <= @ToDate AND e.EndDateTime >= @FromDate)
            OR
            (e.RecurrenceType <> N'NONE' AND e.StartDateTime <= @ToDate
             AND (e.RecurrenceEndDate IS NULL OR e.RecurrenceEndDate >= CAST(@FromDate AS DATE)))
          )
    ORDER BY e.StartDateTime;
END
GO
