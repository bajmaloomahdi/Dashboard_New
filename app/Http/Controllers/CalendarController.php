<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CalendarController extends Controller
{
    private const ENTITY_TYPE = 'CALENDAR_EVENT';
    private const REMINDER_OFFSETS = [0, 5, 10, 15, 30, 60, 1440];

    /** کدهای دسترسی کاربر جاری — یک‌بار در هر درخواست بارگذاری می‌شود */
    private ?array $permissionCache = null;

    // ================= صفحات و لیست‌ها =================

    /**
     * صفحه‌ی اصلی تقویم (Inertia). با ?userId=... می‌توان تقویم کاربر دیگری را
     * (در صورت داشتن دسترسی CALENDAR_VIEW_OTHERS) به‌صورت فقط‌خواندنی دید.
     */
    public function index(Request $request)
    {
        $this->authorizeCalendar('CALENDAR_VIEW');

        $selfId = Auth::id();
        $targetUserId = (int) ($request->input('userId') ?: $selfId);
        $isOther = $targetUserId !== $selfId;

        if ($isOther && !$this->userCan('CALENDAR_VIEW_OTHERS')) {
            abort(403, 'شما اجازه‌ی مشاهده‌ی تقویم سایر کاربران را ندارید.');
        }

        $from = Carbon::now()->startOfMonth()->subDays(7);
        $to = Carbon::now()->endOfMonth()->addDays(7);

        $events = $this->fetchOccurrences($targetUserId, $from, $to);
        $users = DB::select('EXEC sp_GetUsers @SearchText = NULL, @IsActive = 1');

        $canViewOthers = $this->userCan('CALENDAR_VIEW_OTHERS');
        $targetUser = collect($users)->firstWhere('UserID', $targetUserId);

        return Inertia::render('Calendar/Index', [
            'initialEvents'   => $events,
            'initialFrom'     => $from->toDateString(),
            'initialTo'       => $to->toDateString(),
            'users'           => $users,
            'reminderOptions' => $this->reminderOptionsList(),
            'permissions'     => $this->calendarPermissions(),
            'viewingUserId'   => $targetUserId,
            'viewingUserName' => $targetUser->FullName ?? null,
            'isReadOnly'      => $isOther,
            'viewableUsers'   => $canViewOthers ? $users : [],
            'currentUserId'   => $selfId,
        ]);
    }

    /** لیست رویدادهای یک بازه (JSON) */
    public function events(Request $request)
    {
        $this->authorizeCalendar('CALENDAR_VIEW');

        $validated = $request->validate([
            'from'   => 'required|date',
            'to'     => 'required|date',
            'userId' => 'nullable|integer',
        ]);

        $selfId = Auth::id();
        $targetUserId = (int) ($validated['userId'] ?? $selfId);
        if ($targetUserId !== $selfId && !$this->userCan('CALENDAR_VIEW_OTHERS')) {
            return response()->json(['message' => 'دسترسی مجاز نیست.'], 403);
        }

        $from = Carbon::parse($validated['from'])->startOfDay();
        $to = Carbon::parse($validated['to'])->endOfDay();

        return response()->json([
            'events' => $this->fetchOccurrences($targetUserId, $from, $to),
        ]);
    }

    /** جزئیات یک رویداد (JSON) */
    public function show(int $id)
    {
        $this->authorizeCalendar('CALENDAR_VIEW');

        $rows = DB::select('EXEC sp_GetCalendarEvent @EventID = ?, @UserID = ?', [$id, Auth::id()]);
        $event = $rows[0] ?? null;

        if (!$event) {
            return response()->json(['message' => 'رویداد یافت نشد.'], 404);
        }

        // فقط کسانی که با رویداد ارتباط دارند اجازه‌ی دیدن جزئیات را دارند
        if (($event->MyRelation ?? 'NONE') === 'NONE' && !$this->userCan('CALENDAR_VIEW_OTHERS')) {
            return response()->json(['message' => 'دسترسی مجاز نیست.'], 403);
        }

        $attendees = DB::select('EXEC sp_GetCalendarEventAttendees @EventID = ?', [$id]);
        $reminder = $this->getMyReminder($id);

        return response()->json([
            'event'                 => $event,
            'attendees'             => $attendees,
            'reminderOffsetMinutes' => $reminder,
        ]);
    }

    // ================= CRUD رویداد =================

    public function store(Request $request)
    {
        $this->authorizeCalendar('CALENDAR_CREATE');
        $validated = $this->validateEvent($request);
        $selfId = Auth::id();

        $ownerUserId = (int) ($validated['OwnerUserID'] ?? $selfId);
        $attendeeIds = collect($validated['AttendeeUserIDs'] ?? [])->map(fn ($v) => (int) $v)->all();
        $attendeeReminders = $validated['AttendeeReminders'] ?? [];

        $involvesOthers = $ownerUserId !== $selfId
            || collect($attendeeIds)->contains(fn ($id) => $id !== $selfId)
            || !empty($attendeeReminders);

        if ($involvesOthers && !$this->userCan('CALENDAR_CREATE_FOR_OTHERS')) {
            return response()->json([
                'success' => false,
                'message' => 'شما اجازه‌ی ایجاد رویداد برای سایر کاربران را ندارید.',
            ], 403);
        }

        $result = DB::select(
            'EXEC sp_InsertCalendarEvent
                @Title = ?, @Description = ?, @StartDateTime = ?, @EndDateTime = ?, @IsAllDay = ?,
                @Color = ?, @Status = ?, @RecurrenceType = ?, @RecurrenceInterval = ?, @RecurrenceEndDate = ?,
                @OwnerUserID = ?, @OrganizationalUnitID = ?, @AttendeeUserIDs = ?, @CreateUser = ?',
            [
                $validated['Title'],
                $validated['Description'] ?? null,
                $validated['StartDateTime'],
                $validated['EndDateTime'],
                $validated['IsAllDay'] ?? false,
                $validated['Color'] ?? '#1677ff',
                $validated['Status'] ?? 'CONFIRMED',
                $validated['RecurrenceType'] ?? 'NONE',
                $validated['RecurrenceInterval'] ?? 1,
                $validated['RecurrenceEndDate'] ?? null,
                $ownerUserId,
                $validated['OrganizationalUnitID'] ?? null,
                implode(',', $attendeeIds),
                $selfId,
            ]
        );

        $response = (array) ($result[0] ?? []);
        if (empty($response['Success'])) {
            return response()->json(['success' => false, 'message' => $response['Message'] ?? 'خطا در ایجاد رویداد'], 422);
        }

        $eventId = (int) ($response['NewEventID'] ?? 0);
        $recurrence = $this->recurrenceContext($validated);

        // یادآوری شخصی سازنده
        $this->saveReminderFor($eventId, $selfId, $validated['StartDateTime'], $validated['ReminderOffsetMinutes'] ?? null, $selfId, $recurrence);

        // یادآوری اختصاصی هر کاربر مرتبط (فقط با دسترسی «برای دیگران»)
        if ($this->userCan('CALENDAR_CREATE_FOR_OTHERS')) {
            foreach ($attendeeReminders as $uid => $offset) {
                $this->saveReminderFor($eventId, (int) $uid, $validated['StartDateTime'], $offset === null ? null : (int) $offset, $selfId, $recurrence);
            }
        }

        return response()->json(['success' => true, 'message' => $response['Message'], 'eventId' => $eventId]);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeCalendar('CALENDAR_EDIT');
        $validated = $this->validateEvent($request);
        $selfId = Auth::id();

        $ownerUserId = (int) ($validated['OwnerUserID'] ?? $selfId);
        $attendeeIds = collect($validated['AttendeeUserIDs'] ?? [])->map(fn ($v) => (int) $v)->all();
        $attendeeReminders = $validated['AttendeeReminders'] ?? [];

        $involvesOthers = $ownerUserId !== $selfId
            || collect($attendeeIds)->contains(fn ($aid) => $aid !== $selfId)
            || !empty($attendeeReminders);

        if ($involvesOthers && !$this->userCan('CALENDAR_CREATE_FOR_OTHERS')) {
            return response()->json([
                'success' => false,
                'message' => 'شما اجازه‌ی تخصیص رویداد به سایر کاربران را ندارید.',
            ], 403);
        }

        $result = DB::select(
            'EXEC sp_UpdateCalendarEvent
                @EventID = ?, @Title = ?, @Description = ?, @StartDateTime = ?, @EndDateTime = ?, @IsAllDay = ?,
                @Color = ?, @Status = ?, @RecurrenceType = ?, @RecurrenceInterval = ?, @RecurrenceEndDate = ?,
                @OwnerUserID = ?, @OrganizationalUnitID = ?, @AttendeeUserIDs = ?, @ModifyUser = ?',
            [
                $id,
                $validated['Title'],
                $validated['Description'] ?? null,
                $validated['StartDateTime'],
                $validated['EndDateTime'],
                $validated['IsAllDay'] ?? false,
                $validated['Color'] ?? '#1677ff',
                $validated['Status'] ?? 'CONFIRMED',
                $validated['RecurrenceType'] ?? 'NONE',
                $validated['RecurrenceInterval'] ?? 1,
                $validated['RecurrenceEndDate'] ?? null,
                $ownerUserId,
                $validated['OrganizationalUnitID'] ?? null,
                implode(',', $attendeeIds),
                $selfId,
            ]
        );

        $response = (array) ($result[0] ?? []);
        if (empty($response['Success'])) {
            return response()->json(['success' => false, 'message' => $response['Message'] ?? 'خطا در ویرایش رویداد'], 422);
        }

        $recurrence = $this->recurrenceContext($validated);
        $this->saveReminderFor($id, $selfId, $validated['StartDateTime'], $validated['ReminderOffsetMinutes'] ?? null, $selfId, $recurrence);

        if ($this->userCan('CALENDAR_CREATE_FOR_OTHERS')) {
            foreach ($attendeeReminders as $uid => $offset) {
                $this->saveReminderFor($id, (int) $uid, $validated['StartDateTime'], $offset === null ? null : (int) $offset, $selfId, $recurrence);
            }
        }

        return response()->json(['success' => true, 'message' => $response['Message']]);
    }

    public function destroy(int $id)
    {
        $this->authorizeCalendar('CALENDAR_DELETE');

        $result = DB::select('EXEC sp_DeleteCalendarEvent @EventID = ?, @ModifyUser = ?', [$id, Auth::id()]);
        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return response()->json(['success' => false, 'message' => $response['Message'] ?? 'خطا در حذف رویداد'], 422);
        }

        return response()->json(['success' => true, 'message' => $response['Message']]);
    }

    // ================= پاسخ به دعوت / یادآوری شخصی =================

    /** یک کاربر مرتبط، پاسخ خود به رویداد را ثبت می‌کند (پذیرش/رد/شاید) */
    public function respond(Request $request, int $id)
    {
        $this->authorizeCalendar('CALENDAR_VIEW');

        $validated = $request->validate([
            'ResponseStatus' => 'required|in:PENDING,ACCEPTED,REJECTED,TENTATIVE',
        ]);

        $result = DB::select(
            'EXEC sp_SetCalendarEventResponse @EventID = ?, @UserID = ?, @ResponseStatus = ?',
            [$id, Auth::id(), $validated['ResponseStatus']]
        );
        $response = (array) ($result[0] ?? []);

        return response()->json([
            'success' => !empty($response['Success']),
            'message' => $response['Message'] ?? '',
        ], empty($response['Success']) ? 422 : 200);
    }

    /** کاربر مرتبط، یادآوری شخصی خودش را برای یک رویداد تنظیم می‌کند */
    public function saveMyEventReminder(Request $request, int $id)
    {
        $this->authorizeCalendar('CALENDAR_VIEW');

        $validated = $request->validate([
            'ReminderOffsetMinutes' => 'nullable|integer|in:0,5,10,15,30,60,1440',
        ]);

        $rows = DB::select('EXEC sp_GetCalendarEvent @EventID = ?, @UserID = ?', [$id, Auth::id()]);
        $event = $rows[0] ?? null;
        if (!$event || ($event->MyRelation ?? 'NONE') === 'NONE') {
            return response()->json(['success' => false, 'message' => 'شما با این رویداد ارتباطی ندارید.'], 403);
        }

        $this->saveReminderFor(
            $id,
            Auth::id(),
            $event->StartDateTime,
            $validated['ReminderOffsetMinutes'] ?? null,
            Auth::id(),
            [
                'type'     => $event->RecurrenceType ?? 'NONE',
                'interval' => (int) ($event->RecurrenceInterval ?? 1),
                'endDate'  => $event->RecurrenceEndDate ?? null,
            ]
        );

        return response()->json(['success' => true, 'message' => 'یادآوری شما ذخیره شد.']);
    }

    // ================= یادآوری‌ها =================

    /** همه‌ی یادآوری‌های فعال کاربر جاری (رویدادی + مستقل) */
    public function myReminders()
    {
        $this->authorizeCalendar('CALENDAR_VIEW');

        return response()->json([
            'reminders' => DB::select('EXEC sp_GetUserReminders @UserID = ?', [Auth::id()]),
        ]);
    }

    /** ایجاد یادآوری مستقل (بدون رویداد تقویم) — برای خود یا دیگری */
    public function storeStandaloneReminder(Request $request)
    {
        $this->authorizeCalendar('CALENDAR_CREATE');

        $validated = $request->validate([
            'Title'     => 'required|string|max:250',
            'RemindAt'  => 'required|date',
            'ForUserID' => 'nullable|integer',
        ]);

        $selfId = Auth::id();
        $forUserId = (int) ($validated['ForUserID'] ?? $selfId);

        if ($forUserId !== $selfId && !$this->userCan('CALENDAR_CREATE_FOR_OTHERS')) {
            return response()->json(['success' => false, 'message' => 'اجازه‌ی ایجاد یادآوری برای دیگران را ندارید.'], 403);
        }

        $result = DB::select(
            'EXEC sp_InsertStandaloneReminder @Title = ?, @RemindAt = ?, @ForUserID = ?, @CreatedByUserID = ?',
            [$validated['Title'], Carbon::parse($validated['RemindAt'])->toDateTimeString(), $forUserId, $selfId]
        );
        $response = (array) ($result[0] ?? []);

        return response()->json([
            'success' => !empty($response['Success']),
            'message' => $response['Message'] ?? '',
        ], empty($response['Success']) ? 422 : 200);
    }

    public function updateStandaloneReminder(Request $request, int $id)
    {
        $this->authorizeCalendar('CALENDAR_VIEW');

        $validated = $request->validate([
            'Title'    => 'required|string|max:250',
            'RemindAt' => 'required|date',
        ]);

        $result = DB::select(
            'EXEC sp_UpdateStandaloneReminder @ReminderID = ?, @Title = ?, @RemindAt = ?, @ModifyUser = ?',
            [$id, $validated['Title'], Carbon::parse($validated['RemindAt'])->toDateTimeString(), Auth::id()]
        );
        $response = (array) ($result[0] ?? []);

        return response()->json([
            'success' => !empty($response['Success']),
            'message' => $response['Message'] ?? '',
        ], empty($response['Success']) ? 422 : 200);
    }

    public function destroyReminder(int $id)
    {
        $this->authorizeCalendar('CALENDAR_VIEW');

        $result = DB::select('EXEC sp_DeleteReminder @ReminderID = ?, @ModifyUser = ?', [$id, Auth::id()]);
        $response = (array) ($result[0] ?? []);

        return response()->json([
            'success' => !empty($response['Success']),
            'message' => $response['Message'] ?? '',
        ], empty($response['Success']) ? 422 : 200);
    }

    public function dueReminders()
    {
        $rows = DB::select('EXEC sp_GetDueCalendarReminders @UserID = ?', [Auth::id()]);

        return response()->json(['reminders' => $rows]);
    }

    /**
     * تأییدِ «متوجه شدم» یک یادآوری.
     * - یادآوری معمولی → IsSent = 1 (دیگر نمایش داده نمی‌شود)
     * - یادآوری رویدادِ تکرارشونده → RemindAt به رخدادِ بعدی منتقل می‌شود
     * امنیت: هر دو SP مالکیت (UserID) را کنترل می‌کنند؛ کاربر فقط یادآوریِ خودش را می‌تواند تأیید کند.
     */
    public function markReminderSeen(int $reminderId)
    {
        $userId = Auth::id();

        $rows = DB::select('EXEC sp_GetReminderById @ReminderID = ?, @UserID = ?', [$reminderId, $userId]);
        $reminder = $rows[0] ?? null;

        if (!$reminder) {
            return response()->json(['success' => false, 'message' => 'یادآوری یافت نشد یا متعلق به شما نیست.'], 404);
        }

        $recType = $reminder->RecurrenceType ?? 'NONE';
        $isRecurringEvent = $reminder->EntityType === self::ENTITY_TYPE
            && $recType !== null
            && $recType !== 'NONE'
            && (int) ($reminder->EventIsActive ?? 0) === 1;

        if ($isRecurringEvent) {
            $offset = (int) $reminder->OffsetMinutes;
            $next = $this->nextOccurrenceStart(
                Carbon::parse($reminder->EventStartDateTime),
                $recType,
                (int) ($reminder->RecurrenceInterval ?: 1),
                $this->sqlNow(),
                $reminder->RecurrenceEndDate ? Carbon::parse($reminder->RecurrenceEndDate)->endOfDay() : null
            );

            if ($next !== null) {
                $newRemindAt = $next->copy()->subMinutes($offset)->format('Y-m-d H:i:s');
                DB::select(
                    'EXEC sp_RescheduleRecurringReminder @ReminderID = ?, @UserID = ?, @NewRemindAt = ?',
                    [$reminderId, $userId, $newRemindAt]
                );

                return response()->json(['success' => true, 'rescheduled' => true, 'nextRemindAt' => $newRemindAt]);
            }
        }

        DB::select('EXEC sp_MarkReminderSent @ReminderID = ?, @UserID = ?', [$reminderId, $userId]);

        return response()->json(['success' => true, 'rescheduled' => false]);
    }

    // ================= داخلی =================

    /** بسط تکرارها و واکشی رویدادهای بازه */
    private function fetchOccurrences(int $userId, Carbon $from, Carbon $to): array
    {
        $rows = DB::select(
            'EXEC sp_GetCalendarEvents @UserID = ?, @FromDate = ?, @ToDate = ?',
            [$userId, $from->toDateTimeString(), $to->toDateTimeString()]
        );

        $occurrences = [];
        foreach ($rows as $row) {
            foreach ($this->expandRecurrence($row, $from, $to) as $occ) {
                $occurrences[] = $occ;
            }
        }
        usort($occurrences, fn ($a, $b) => strcmp($a->StartDateTime, $b->StartDateTime));

        return $occurrences;
    }

    private function expandRecurrence(object $row, Carbon $from, Carbon $to): array
    {
        $start = Carbon::parse($row->StartDateTime);
        $end = Carbon::parse($row->EndDateTime);
        // Carbon 3 diff is signed؛ مدت رویداد همیشه باید مثبت باشد.
        $durationSeconds = abs($end->diffInSeconds($start));

        $makeOccurrence = function (Carbon $occStart, int $occurrenceIndex) use ($row, $durationSeconds) {
            $occEnd = (clone $occStart)->addSeconds($durationSeconds);
            $clone = clone $row;
            $clone->OccurrenceKey = $row->EventID . ($occurrenceIndex > 0 ? ('_' . $occurrenceIndex) : '');
            $clone->IsRecurringInstance = $occurrenceIndex > 0;
            $clone->StartDateTime = $occStart->toDateTimeString();
            $clone->EndDateTime = $occEnd->toDateTimeString();

            return $clone;
        };

        if ($row->RecurrenceType === 'NONE') {
            if ($start->lte($to) && $end->gte($from)) {
                return [$makeOccurrence($start, 0)];
            }

            return [];
        }

        $interval = max(1, (int) $row->RecurrenceInterval);
        $seriesEnd = $row->RecurrenceEndDate ? Carbon::parse($row->RecurrenceEndDate)->endOfDay() : null;
        $limit = $seriesEnd && $seriesEnd->lt($to) ? $seriesEnd : $to;

        $occurrences = [];
        $cursor = $start->copy();
        $index = 0;
        $safety = 0;

        while ($cursor->lte($limit) && $safety < 1000) {
            $safety++;
            $occEnd = (clone $cursor)->addSeconds($durationSeconds);
            if ($cursor->lte($to) && $occEnd->gte($from)) {
                $occurrences[] = $makeOccurrence($cursor, $index);
            }
            $index++;
            $cursor = match ($row->RecurrenceType) {
                'DAILY'   => $start->copy()->addDays($interval * $index),
                'WEEKLY'  => $start->copy()->addWeeks($interval * $index),
                'MONTHLY' => $start->copy()->addMonthsNoOverflow($interval * $index),
                'YEARLY'  => $start->copy()->addYears($interval * $index),
                default   => $limit->copy()->addDay(),
            };
        }

        return $occurrences;
    }

    private function reminderOptionsList(): array
    {
        return [
            ['value' => null, 'label' => 'بدون یادآوری'],
            ['value' => 0, 'label' => 'هنگام شروع رویداد'],
            ['value' => 5, 'label' => '5 دقیقه قبل'],
            ['value' => 10, 'label' => '10 دقیقه قبل'],
            ['value' => 15, 'label' => '15 دقیقه قبل'],
            ['value' => 30, 'label' => '30 دقیقه قبل'],
            ['value' => 60, 'label' => '1 ساعت قبل'],
            ['value' => 1440, 'label' => '1 روز قبل'],
        ];
    }

    private function getMyReminder(int $eventId): ?int
    {
        $rows = DB::select(
            'EXEC sp_GetReminder @EntityType = ?, @EntityID = ?, @UserID = ?',
            [self::ENTITY_TYPE, $eventId, Auth::id()]
        );

        return isset($rows[0]) ? (int) $rows[0]->OffsetMinutes : null;
    }

    private function saveReminderFor(
        int $eventId,
        int $forUserId,
        string $startDateTime,
        ?int $offsetMinutes,
        int $byUserId,
        array $recurrence = []
    ): void {
        if ($offsetMinutes !== null && !in_array($offsetMinutes, self::REMINDER_OFFSETS, true)) {
            $offsetMinutes = null;
        }

        // برای رویدادهای تکرارشونده، لنگرِ یادآوری = شروعِ نخستین رخدادِ آینده
        // (نه شروعِ رخدادِ اصلی که ممکن است گذشته باشد).
        $anchor = $this->reminderAnchorStart(
            $startDateTime,
            $recurrence['type'] ?? 'NONE',
            (int) ($recurrence['interval'] ?? 1),
            $recurrence['endDate'] ?? null
        );

        DB::select(
            'EXEC sp_SaveReminder
                @EntityType = ?, @EntityID = ?, @UserID = ?, @OffsetMinutes = ?, @EntityStartDateTime = ?, @CreatedByUserID = ?, @Title = ?',
            [self::ENTITY_TYPE, $eventId, $forUserId, $offsetMinutes, $anchor, $byUserId, null]
        );
    }

    /**
     * «اکنون» بر اساس ساعتِ SQL Server.
     * داده‌های تقویم به‌صورت «ساعتِ دیواریِ SQL Server» ذخیره می‌شوند (SPها با SYSDATETIME کار می‌کنند
     * و فرانت‌اند هم همان ساعت محلی را می‌فرستد). چون Laravel روی UTC است، برای محاسبه‌ی رخدادها
     * باید از همین مرجعِ زمانی استفاده شود، نه Carbon::now().
     */
    private function sqlNow(): Carbon
    {
        $row = DB::selectOne('SELECT CONVERT(varchar(19), SYSDATETIME(), 120) AS n');

        return Carbon::parse($row->n);
    }

    /**
     * لنگرِ زمانیِ یادآوری برای یک رویداد.
     * NONE → همان شروعِ رویداد. تکرارشونده → شروعِ نخستین رخدادی که در آینده است
     * (اگر همه‌ی رخدادها گذشته باشند، همان شروعِ اصلی برگردانده می‌شود تا یادآوری یک‌بار نمایش داده و بسته شود).
     */
    private function reminderAnchorStart(string $masterStart, ?string $recType, int $interval, ?string $recEndDate): string
    {
        if ($recType === null || $recType === 'NONE') {
            return $masterStart;
        }

        $next = $this->nextOccurrenceStart(
            Carbon::parse($masterStart),
            $recType,
            max(1, $interval),
            $this->sqlNow(),
            $recEndDate ? Carbon::parse($recEndDate)->endOfDay() : null,
            true // شاملِ خودِ اکنون (رخدادی که همین حالا در جریان است)
        );

        return ($next ?? Carbon::parse($masterStart))->format('Y-m-d H:i:s');
    }

    /**
     * شروعِ نخستین رخدادِ سری تکرار که بعد از $after است (یا اگر $orAfter=true، بر یا بعد از آن).
     * اگر سری پیش از آن پایان یافته باشد، null برمی‌گرداند.
     */
    private function nextOccurrenceStart(
        Carbon $masterStart,
        string $recType,
        int $interval,
        Carbon $after,
        ?Carbon $seriesEnd = null,
        bool $orAfter = false
    ): ?Carbon {
        $interval = max(1, $interval);
        $cursor = $masterStart->copy();
        $index = 0;
        $safety = 0;

        while ($safety < 5000) {
            $safety++;

            if ($seriesEnd !== null && $cursor->gt($seriesEnd)) {
                return null;
            }

            $isFuture = $orAfter ? $cursor->gte($after) : $cursor->gt($after);
            if ($isFuture) {
                return $cursor->copy();
            }

            $index++;
            $cursor = match ($recType) {
                'DAILY'   => $masterStart->copy()->addDays($interval * $index),
                'WEEKLY'  => $masterStart->copy()->addWeeks($interval * $index),
                'MONTHLY' => $masterStart->copy()->addMonthsNoOverflow($interval * $index),
                'YEARLY'  => $masterStart->copy()->addYears($interval * $index),
                default   => throw new \InvalidArgumentException('نوع تکرار نامعتبر: ' . $recType),
            };
        }

        return null;
    }

    /** استخراج زمینه‌ی تکرار از داده‌ی اعتبارسنجی‌شده‌ی رویداد */
    private function recurrenceContext(array $validated): array
    {
        return [
            'type'     => $validated['RecurrenceType'] ?? 'NONE',
            'interval' => (int) ($validated['RecurrenceInterval'] ?? 1),
            'endDate'  => ($validated['RecurrenceType'] ?? 'NONE') !== 'NONE'
                ? ($validated['RecurrenceEndDate'] ?? null)
                : null,
        ];
    }

    private function validateEvent(Request $request): array
    {
        return $request->validate([
            'Title'                 => 'required|string|max:250',
            'Description'           => 'nullable|string',
            'StartDateTime'         => 'required|date',
            'EndDateTime'           => 'required|date|after_or_equal:StartDateTime',
            'IsAllDay'              => 'boolean',
            'Color'                 => 'nullable|string|max:20',
            'Status'                => 'nullable|in:CONFIRMED,TENTATIVE,CANCELLED',
            'RecurrenceType'        => 'nullable|in:NONE,DAILY,WEEKLY,MONTHLY,YEARLY',
            'RecurrenceInterval'    => 'nullable|integer|min:1|max:365',
            'RecurrenceEndDate'     => 'nullable|date',
            'OwnerUserID'           => 'nullable|integer',
            'OrganizationalUnitID'  => 'nullable|integer',
            'AttendeeUserIDs'       => 'nullable|array',
            'AttendeeUserIDs.*'     => 'integer',
            'AttendeeReminders'     => 'nullable|array',
            'ReminderOffsetMinutes' => 'nullable|integer|in:0,5,10,15,30,60,1440',
        ]);
    }

    // ---------- دسترسی ----------

    private function loadPermissions(): array
    {
        if ($this->permissionCache === null) {
            $rows = DB::select('EXEC sp_GetUserPermissions @UserID = ?', [Auth::id()]);
            $this->permissionCache = collect($rows)->pluck('PermissionCode')->all();
        }

        return $this->permissionCache;
    }

    private function userCan(string $code): bool
    {
        return in_array($code, $this->loadPermissions(), true);
    }

    private function authorizeCalendar(string $code): void
    {
        if (!$this->userCan($code)) {
            abort(403, 'شما دسترسی لازم برای این عملیات را ندارید.');
        }
    }

    /** فقط کدهای گروه Calendar — برای ارسال به فرانت‌اند */
    private function calendarPermissions(): array
    {
        return array_values(array_filter($this->loadPermissions(), fn ($c) => str_starts_with($c, 'CALENDAR_')));
    }
}
