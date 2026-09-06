/* ==========================================================================
   پچ خودکار شماره: 009 | نام: reminder_ownership_and_recurrence
   تاریخ: 2026-09-05 18:50:25 | شامل 6 دستور SQL
   ========================================================================== */

-- [DROP_PROCEDURE] روی PROCEDURE: sp_MarkReminderSent
DROP PROCEDURE dbo.sp_MarkReminderSent
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_MarkReminderSent
CREATE PROCEDURE [dbo].[sp_MarkReminderSent]
    @ReminderID BIGINT,
    @UserID     INT
AS
BEGIN
    SET NOCOUNT ON;

    UPDATE dbo.Reminders
    SET IsSent = 1, SentDate = SYSDATETIME(), Date_LastUpdate = SYSDATETIME(), UserID_LastUpdate = @UserID
    WHERE ReminderID = @ReminderID
      AND UserID = @UserID;          -- فقط صاحبِ یادآوری می‌تواند آن را «دیده‌شده» کند

    SELECT CAST(CASE WHEN @@ROWCOUNT > 0 THEN 1 ELSE 0 END AS BIT) AS Success;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetReminderById
-- ---------- دریافت یک یادآوری با شناسه + اطلاعات تکرارِ رویدادِ مرتبط ----------
CREATE PROCEDURE [dbo].[sp_GetReminderById]
    @ReminderID BIGINT,
    @UserID     INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        r.ReminderID,
        r.EntityType,
        r.EntityID,
        r.UserID,
        r.OffsetMinutes,
        r.RemindAt,
        r.IsSent,
        r.CreatedByUserID,
        ISNULL(r.Title, e.Title)  AS Title,
        e.StartDateTime           AS EventStartDateTime,
        e.EndDateTime             AS EventEndDateTime,
        e.IsAllDay                AS EventIsAllDay,
        e.RecurrenceType          AS RecurrenceType,
        e.RecurrenceInterval      AS RecurrenceInterval,
        e.RecurrenceEndDate       AS RecurrenceEndDate,
        e.IsActive                AS EventIsActive
    FROM dbo.Reminders r
    LEFT JOIN dbo.CalendarEvents e
        ON r.EntityType = N'CALENDAR_EVENT' AND e.EventID = r.EntityID
    WHERE r.ReminderID = @ReminderID
      AND r.UserID = @UserID
      AND r.IsActive = 1;
END
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_RescheduleRecurringReminder
-- ---------- جابه‌جایی زمان یادآوریِ رویدادِ تکرارشونده به رخدادِ بعدی ----------
-- (به‌جای IsSent=1، RemindAt به جلو منتقل می‌شود تا برای رخداد بعدی دوباره فعال شود)
CREATE PROCEDURE [dbo].[sp_RescheduleRecurringReminder]
    @ReminderID  BIGINT,
    @UserID      INT,
    @NewRemindAt DATETIME2(0)
AS
BEGIN
    SET NOCOUNT ON;

    UPDATE dbo.Reminders
    SET RemindAt          = @NewRemindAt,
        IsSent            = 0,
        SentDate          = SYSDATETIME(),   -- زمانِ آخرین نمایش، برای سابقه
        Date_LastUpdate   = SYSDATETIME(),
        UserID_LastUpdate = @UserID
    WHERE ReminderID = @ReminderID
      AND UserID = @UserID;

    SELECT CAST(CASE WHEN @@ROWCOUNT > 0 THEN 1 ELSE 0 END AS BIT) AS Success;
END
GO

-- [DROP_PROCEDURE] روی PROCEDURE: sp_GetDueCalendarReminders
DROP PROCEDURE dbo.sp_GetDueCalendarReminders
GO

-- [CREATE_PROCEDURE] روی PROCEDURE: sp_GetDueCalendarReminders
CREATE PROCEDURE [dbo].[sp_GetDueCalendarReminders]
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        r.ReminderID,
        r.EntityType,
        r.EntityID AS EventID,
        ISNULL(r.Title, e.Title) AS Title,
        e.StartDateTime,
        e.EndDateTime,
        e.IsAllDay,
        e.RecurrenceType,
        r.OffsetMinutes,
        r.RemindAt
    FROM dbo.Reminders r
    LEFT JOIN dbo.CalendarEvents e
        ON r.EntityType = N'CALENDAR_EVENT' AND e.EventID = r.EntityID AND e.IsActive = 1
    WHERE r.UserID = @UserID
      AND r.IsActive = 1
      AND r.IsSent = 0
      AND r.RemindAt <= SYSDATETIME()
      AND (r.EntityType <> N'CALENDAR_EVENT' OR e.EventID IS NOT NULL);
END
GO

