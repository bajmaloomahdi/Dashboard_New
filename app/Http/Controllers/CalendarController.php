<?php

namespace App\Http\Controllers;

use App\Services\Calendar\CalendarReminderRoller;
use App\Services\Calendar\JalaliRecurrence;
use App\Services\Calendar\ReminderContent;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CalendarController extends Controller
{
    private const ENTITY_TYPE = 'CALENDAR_EVENT';
    private const REMINDER_OFFSETS = [0, 5, 10, 15, 30, 60, 1440];
    private const UNRELATED_REMINDER_MESSAGE = 'یادآوری فقط برایِ مالک و شرکت‌کنندگانِ همین رویداد قابلِ تنظیم است.';
    private const UNIT_FORBIDDEN_MESSAGE = 'اجازهٔ ثبتِ رویداد در تقویمِ این واحد را ندارید.';
    private const RECURRENCE_END_MESSAGE = 'پایانِ تکرار نمی‌تواند پیش از تاریخِ شروعِ رویداد باشد.';
    private const UNIT_PRIVATE_MESSAGE = 'رویدادِ تقویمِ واحد نمی‌تواند خصوصی باشد.';

    /**
     * کش‌هایِ درخواستی بر اساسِ UserID (نه یک مقدارِ واحد): Laravel ممکن است همین نمونهٔ Controller را برایِ
     * درخواست‌هایِ بعدی هم به کار ببرد (مثلاً در آزمون‌ها)؛ کشِ بی‌کلید دسترسیِ کاربرِ قبلی را برمی‌گرداند.
     *
     * @var array<int, array>
     */
    private array $unitsCache = [];

    /** @var array<int, string[]> کدهایِ دسترسیِ هر کاربر */
    private array $permissionCache = [];

    // ================= صفحات و لیست‌ها =================

    /**
     * صفحه‌ی اصلی تقویم (Inertia). با ?userId=... تقویمِ کاربرِ دیگر به‌صورتِ فقط‌خواندنی دیده می‌شود:
     * با CALENDAR_VIEW_OTHERS یا اشتراکِ READ (جزئیات) / FREEBUSY (فقط مشغول) — سطح از sp_Calendar_GetAccess.
     */
    public function index(Request $request)
    {
        $this->authorizeCalendar('CALENDAR_VIEW');

        $selfId = Auth::id();
        $targetUserId = (int) ($request->input('userId') ?: $selfId);
        $isOther = $targetUserId !== $selfId;
        $access = $isOther ? $this->calendarAccess($targetUserId) : 'OWNER';

        if ($access === 'NONE') {
            abort(403, 'شما اجازه‌ی مشاهده‌ی تقویم این کاربر را ندارید.');
        }

        $from = Carbon::now()->startOfMonth()->subDays(7);
        $to = Carbon::now()->endOfMonth()->addDays(7);

        $events = $this->forViewer($this->fetchOccurrences($targetUserId, $from, $to), $access);
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
            'viewingAccess'   => $access,
            'isReadOnly'      => $isOther,
            'viewableUsers'   => $canViewOthers ? $users : [],
            'currentUserId'   => $selfId,
        ]);
    }

    /**
     * لیست رویدادهای یک بازه (JSON). تقویمِ دیگری فقط با سطحِ دسترسیِ مجاز؛
     * FREEBUSY = پنهان‌سازیِ کاملِ جزئیات در همین سرور (هیچ عنوان/توضیح/نام/شناسه‌ای به مرورگر نمی‌رسد).
     */
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
        $access = $targetUserId === $selfId ? 'OWNER' : $this->calendarAccess($targetUserId);
        if ($access === 'NONE') {
            return response()->json(['message' => 'دسترسی مجاز نیست.'], 403);
        }

        $from = Carbon::parse($validated['from'])->startOfDay();
        $to = Carbon::parse($validated['to'])->endOfDay();
        $events = $this->fetchOccurrences($targetUserId, $from, $to);

        return response()->json([
            'events' => $this->forViewer($events, $access),
            'access' => $access,
        ]);
    }

    /** رویدادهایِ تقویمِ یک واحد (فقط عضوِ مستقیمِ فعال یا دارندهٔ CALENDAR_VIEW_OTHERS؛ SP هم دوباره بررسی می‌کند) */
    public function unitEvents(Request $request, int $unitId)
    {
        $this->authorizeCalendar('CALENDAR_VIEW');
        $validated = $request->validate(['from' => 'required|date', 'to' => 'required|date']);

        $isMember = collect($this->myUnits())->contains(fn ($u) => (int) $u->UnitID === $unitId);
        if (!$isMember && !$this->userCan('CALENDAR_VIEW_OTHERS')) {
            return response()->json(['message' => 'دسترسی مجاز نیست.'], 403);
        }

        $from = Carbon::parse($validated['from'])->startOfDay();
        $to = Carbon::parse($validated['to'])->endOfDay();
        try {
            $rows = DB::select(
                'EXEC sp_GetUnitCalendarEvents @UnitID = ?, @UserID = ?, @FromDate = ?, @ToDate = ?',
                [$unitId, Auth::id(), $from->toDateTimeString(), $to->toDateTimeString()]
            );
        } catch (\Illuminate\Database\QueryException $e) {
            // SP در صورتِ نبودِ دسترسی (لایهٔ دوم) هیچ مجموعهٔ نتیجه‌ای برنمی‌گرداند
            if (!str_contains($e->getMessage(), 'contains no fields')) {
                throw $e;
            }
            return response()->json(['message' => 'دسترسی مجاز نیست.'], 403);
        }

        return response()->json(['events' => $this->expandRows($rows, $from, $to)]);
    }

    /** منابعِ تقویم برایِ پنلِ «تقویم‌ها»: اشتراک‌گذاشته‌شده با من + واحدهایِ من (عضویتِ مستقیم) */
    public function sources()
    {
        $this->authorizeCalendar('CALENDAR_VIEW');

        return response()->json([
            'shared'   => DB::select('EXEC sp_Calendar_GetSharedWithMe @UserID = ?', [Auth::id()]),
            'units'    => $this->myUnits(),
            'canShare' => $this->userCan('CALENDAR_SHARE'),
        ]);
    }

    // ================= اشتراکِ تقویمِ شخصی =================

    public function shares()
    {
        $this->authorizeCalendar('CALENDAR_VIEW');

        return response()->json(['shares' => DB::select('EXEC sp_Calendar_GetShares @OwnerUserID = ?', [Auth::id()])]);
    }

    public function storeShare(Request $request)
    {
        $this->authorizeCalendar('CALENDAR_SHARE');
        $validated = $request->validate([
            'SharedWithUserID' => 'required|integer',
            'AccessLevel'      => 'required|in:FREEBUSY,READ', // ویرایشِ به نمایندگی عمداً نیست
        ]);

        $r = DB::selectOne(
            'EXEC sp_Calendar_SaveShare @OwnerUserID = ?, @SharedWithUserID = ?, @AccessLevel = ?, @ByUserID = ?',
            [Auth::id(), (int) $validated['SharedWithUserID'], $validated['AccessLevel'], Auth::id()]
        );
        $ok = (int) ($r->Success ?? 0) === 1;

        return response()->json([
            'success' => $ok,
            'message' => (string) ($r->Message ?? ''),
            'shareId' => isset($r->CalendarShareID) ? (int) $r->CalendarShareID : null,
        ], $ok ? 200 : 422);
    }

    public function destroyShare(int $id)
    {
        $this->authorizeCalendar('CALENDAR_VIEW');
        $r = DB::selectOne('EXEC sp_Calendar_RemoveShare @OwnerUserID = ?, @CalendarShareID = ?, @ByUserID = ?', [Auth::id(), $id, Auth::id()]);
        $ok = (int) ($r->Success ?? 0) === 1;

        return response()->json(['success' => $ok, 'message' => (string) ($r->Message ?? '')], $ok ? 200 : 404);
    }

    /** جزئیات یک رویداد (JSON) */
    public function show(int $id)
    {
        $this->authorizeCalendar('CALENDAR_VIEW');

        $rows = DB::select('EXEC sp_GetCalendarEvent @EventID = ?, @UserID = ?', [$id, Auth::id()]);
        $event = $rows[0] ?? null;

        // رویدادِ خصوصی فقط برایِ سازنده/مالک/شرکت‌کنندهٔ فعال (SP هم برایِ بقیه ردیفی برنمی‌گرداند)
        if (!$event || ((int) ($event->IsPrivate ?? 0) === 1 && !in_array($event->MyRelation ?? 'NONE', ['CREATOR', 'OWNER', 'ATTENDEE'], true))) {
            return response()->json(['message' => 'رویداد یافت نشد.'], 404);
        }

        $attendees = DB::select('EXEC sp_GetCalendarEventAttendees @EventID = ?', [$id]);

        // فقط مرتبط‌ها (سازنده/مالک/شرکت‌کننده/عضوِ واحد)، CALENDAR_VIEW_OTHERS، یا اشتراکِ READ از یکی از آن‌ها.
        // اشتراکِ FREEBUSY جزئیات نمی‌دهد.
        if (($event->MyRelation ?? 'NONE') === 'NONE' && !$this->userCan('CALENDAR_VIEW_OTHERS')
            && !$this->hasReadShareFor($event, $attendees)) {
            return response()->json(['message' => 'دسترسی مجاز نیست.'], 403);
        }
        $reminder = $this->getMyReminder($id);

        // یادآوریِ هر شرکت‌کننده شخصی است؛ فقط مدیرِ رویداد (برایِ فرمِ ویرایش) یا خودِ آن کاربر آن را می‌بیند.
        if ((int) ($event->CanManage ?? 0) !== 1) {
            foreach ($attendees as $attendee) {
                if ((int) $attendee->UserID !== (int) Auth::id()) {
                    $attendee->ReminderOffsetMinutes = null;
                }
            }
        }

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
        if ($this->recurrenceEndsBeforeStart($validated)) {
            return response()->json(['success' => false, 'message' => self::RECURRENCE_END_MESSAGE], 422);
        }
        if ($this->hasUnrelatedReminderUsers($attendeeReminders, $attendeeIds, $ownerUserId)) {
            return response()->json(['success' => false, 'message' => self::UNRELATED_REMINDER_MESSAGE], 422);
        }

        // تقویمِ واحد: فقط عضوِ مستقیمِ همان واحد با CALENDAR_UNIT_MANAGE (SP هم دوباره بررسی می‌کند)
        $unitId = isset($validated['OrganizationalUnitID']) ? (int) $validated['OrganizationalUnitID'] : null;
        if ($unitId !== null && !$this->canManageUnit($unitId)) {
            return response()->json(['success' => false, 'message' => self::UNIT_FORBIDDEN_MESSAGE], 403);
        }
        // خصوصی: پیش‌فرض عمومی؛ رویدادِ واحد خصوصی نمی‌شود (SP و قیدِ دیتابیس هم کنترل می‌کنند)
        $isPrivate = !empty($validated['IsPrivate']);
        if ($isPrivate && $unitId !== null) {
            return response()->json(['success' => false, 'message' => self::UNIT_PRIVATE_MESSAGE], 422);
        }

        $result = DB::select(
            'EXEC sp_InsertCalendarEvent
                @Title = ?, @Description = ?, @StartDateTime = ?, @EndDateTime = ?, @IsAllDay = ?,
                @Color = ?, @Status = ?, @RecurrenceType = ?, @RecurrenceInterval = ?, @RecurrenceEndDate = ?,
                @OwnerUserID = ?, @OrganizationalUnitID = ?, @AttendeeUserIDs = ?, @CreateUser = ?, @IsPrivate = ?',
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
                $unitId,
                implode(',', $attendeeIds),
                $selfId,
                $isPrivate ? 1 : 0,
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

        $current = DB::select('EXEC sp_GetCalendarEvent @EventID = ?, @UserID = ?', [$id, $selfId])[0] ?? null;
        if (!$current) {
            return response()->json(['success' => false, 'message' => 'رویداد یافت نشد.'], 404);
        }
        $currentAttendeeIds = collect(DB::select('EXEC sp_GetCalendarEventAttendees @EventID = ?', [$id]))
            ->filter(fn ($a) => $a->RelationType !== 'ORGANIZER')->map(fn ($a) => (int) $a->UserID)->values()->all();

        // فیلدی که ارسال نشده، مقدارِ فعلیِ رویداد را نگه می‌دارد (به‌ویژه تقویمِ واحد: ویرایش از فرم آن را شخصی نکند)
        $input = $request->all();
        $ownerUserId = array_key_exists('OwnerUserID', $input) && $validated['OwnerUserID'] !== null
            ? (int) $validated['OwnerUserID'] : (int) $current->OwnerUserID;
        $attendeeIds = array_key_exists('AttendeeUserIDs', $input)
            ? collect($validated['AttendeeUserIDs'] ?? [])->map(fn ($v) => (int) $v)->all()
            : $currentAttendeeIds;
        $unitId = array_key_exists('OrganizationalUnitID', $input)
            ? (isset($validated['OrganizationalUnitID']) ? (int) $validated['OrganizationalUnitID'] : null)
            : ($current->OrganizationalUnitID !== null ? (int) $current->OrganizationalUnitID : null);
        $attendeeReminders = $validated['AttendeeReminders'] ?? [];

        // انتقال به تقویمِ واحدِ دیگر فقط برایِ مدیرِ رویدادِ همان واحد
        $currentUnit = $current->OrganizationalUnitID !== null ? (int) $current->OrganizationalUnitID : null;
        if ($unitId !== null && $unitId !== $currentUnit && !$this->canManageUnit($unitId)) {
            return response()->json(['success' => false, 'message' => self::UNIT_FORBIDDEN_MESSAGE], 403);
        }
        // خصوصی: ارسال‌نشده = مقدارِ فعلی (NULL به SP)؛ رویدادِ واحد خصوصی نمی‌شود
        $isPrivate = array_key_exists('IsPrivate', $input) && $validated['IsPrivate'] !== null ? (int) (bool) $validated['IsPrivate'] : null;
        if (($isPrivate ?? (int) ($current->IsPrivate ?? 0)) === 1 && $unitId !== null) {
            return response()->json(['success' => false, 'message' => self::UNIT_PRIVATE_MESSAGE], 422);
        }

        if ($this->isUnitManagerOnly($current, $selfId)) {
            // مدیرِ رویدادِ واحد (نه سازنده/مالک): تا وقتی مالک و شرکت‌کنندگان دست نخورده‌اند «برای دیگران» نیست
            $norm = fn (array $ids) => collect($ids)->reject(fn ($u) => $u === $selfId || $u === (int) $current->OwnerUserID)->unique()->sort()->values()->all();
            $involvesOthers = ($ownerUserId !== (int) $current->OwnerUserID && $ownerUserId !== $selfId)
                || $norm($attendeeIds) !== $norm($currentAttendeeIds)
                || !empty($attendeeReminders);
        } else {
            $involvesOthers = $ownerUserId !== $selfId
                || collect($attendeeIds)->contains(fn ($aid) => $aid !== $selfId)
                || !empty($attendeeReminders);
        }

        if ($involvesOthers && !$this->userCan('CALENDAR_CREATE_FOR_OTHERS')) {
            return response()->json([
                'success' => false,
                'message' => 'شما اجازه‌ی تخصیص رویداد به سایر کاربران را ندارید.',
            ], 403);
        }
        if ($this->recurrenceEndsBeforeStart($validated)) {
            return response()->json(['success' => false, 'message' => self::RECURRENCE_END_MESSAGE], 422);
        }
        if ($this->hasUnrelatedReminderUsers($attendeeReminders, $attendeeIds, $ownerUserId)) {
            return response()->json(['success' => false, 'message' => self::UNRELATED_REMINDER_MESSAGE], 422);
        }

        $result = DB::select(
            'EXEC sp_UpdateCalendarEvent
                @EventID = ?, @Title = ?, @Description = ?, @StartDateTime = ?, @EndDateTime = ?, @IsAllDay = ?,
                @Color = ?, @Status = ?, @RecurrenceType = ?, @RecurrenceInterval = ?, @RecurrenceEndDate = ?,
                @OwnerUserID = ?, @OrganizationalUnitID = ?, @AttendeeUserIDs = ?, @ModifyUser = ?, @IsPrivate = ?',
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
                $unitId,
                implode(',', $attendeeIds),
                $selfId,
                $isPrivate,
            ]
        );

        $response = (array) ($result[0] ?? []);
        if (empty($response['Success'])) {
            return response()->json(['success' => false, 'message' => $response['Message'] ?? 'خطا در ویرایش رویداد'], 422);
        }

        $recurrence = $this->recurrenceContext($validated);
        $this->saveReminderFor($id, $selfId, $validated['StartDateTime'], $validated['ReminderOffsetMinutes'] ?? null, $selfId, $recurrence);

        $saved = [$selfId];
        if ($this->userCan('CALENDAR_CREATE_FOR_OTHERS')) {
            foreach ($attendeeReminders as $uid => $offset) {
                $this->saveReminderFor($id, (int) $uid, $validated['StartDateTime'], $offset === null ? null : (int) $offset, $selfId, $recurrence);
                $saved[] = (int) $uid;
            }
        }

        // تغییرِ زمان/تکرار: یادآوریِ بقیهٔ کاربرانِ مرتبط (که در این درخواست فرستاده نشده) با همان «چند دقیقه قبل»
        // به زمانِ تازه منتقل می‌شود — وگرنه رویِ زمانِ قدیم می‌ماند (مثلاً ویرایشگرِ بدونِ «برای دیگران»)
        if ($this->timingChanged($current, $validated)) {
            $this->reanchorReminders($id, $validated['StartDateTime'], $recurrence, $selfId, $saved);
        }

        return $this->updatedResponse($response['Message'], $response);
    }

    /**
     * جابه‌جایی / تغییرِ مدتِ رویداد (Drag & Drop و Resize در تقویم) — فقط شروع و پایان عوض می‌شود.
     *
     * بقیهٔ اطلاعات (عنوان، توضیح، رنگ، وضعیت، مالک، واحد، تمام‌روز، شرکت‌کنندگان با نقش و پاسخشان)
     * از خودِ رویداد خوانده و دست‌نخورده به همان sp_UpdateCalendarEvent داده می‌شود؛ یادآوری‌های فعلیِ همهٔ
     * کاربرانِ مرتبط با همان «چند دقیقه قبل» به زمانِ تازه منتقل می‌شوند (حذف نمی‌شوند).
     * رویدادِ تکرارشونده در این مرحله فقط از فرمِ ویرایش تغییر می‌کند (منطقِ تکرار دست نمی‌خورد).
     * دسترسی‌ها همان ویرایشِ فرم است: CALENDAR_EDIT + سازنده/مالک (در SP هم کنترل می‌شود)
     * + CALENDAR_CREATE_FOR_OTHERS اگر رویداد به کاربرِ دیگری مربوط است.
     */
    public function move(Request $request, int $id)
    {
        $this->authorizeCalendar('CALENDAR_EDIT');
        $validated = $request->validate([
            'StartDateTime' => 'required|date',
            'EndDateTime'   => 'required|date|after_or_equal:StartDateTime',
        ]);
        $selfId = Auth::id();

        $event = DB::select('EXEC sp_GetCalendarEvent @EventID = ?, @UserID = ?', [$id, $selfId])[0] ?? null;
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'رویداد یافت نشد.'], 404);
        }
        if ((int) $event->CanManage !== 1) {
            return response()->json(['success' => false, 'message' => 'فقط ایجادکننده یا مالک رویداد می‌تواند زمان آن را تغییر دهد.'], 403);
        }
        if (($event->RecurrenceType ?? 'NONE') !== 'NONE') {
            return response()->json(['success' => false, 'message' => 'زمانِ رویدادِ تکرارشونده را از فرمِ ویرایش تغییر دهید.'], 422);
        }

        // شرکت‌کنندگانِ فعلی (به‌جز ایجادکننده/برگزارکننده که SP خودش نگه می‌دارد)؛ نقش و پاسخِ آن‌ها در SP حفظ می‌شود
        $attendees = DB::select('EXEC sp_GetCalendarEventAttendees @EventID = ?', [$id]);
        $creatorId = (int) $event->CreatedByUserID;
        $ownerId = (int) $event->OwnerUserID;
        $attendeeIds = collect($attendees)
            ->filter(fn ($a) => $a->RelationType !== 'ORGANIZER' && (int) $a->UserID !== $creatorId)
            ->map(fn ($a) => (int) $a->UserID)
            ->unique()->values()->all();

        // همان قاعدهٔ update(): مالکِ دیگر یا شرکت‌کنندهٔ دیگر = «برای دیگران»؛
        // مدیرِ رویدادِ واحد با جابه‌جایی مالک/شرکت‌کنندگان را تغییر نمی‌دهد → «برای دیگران» نیست
        $involvesOthers = !$this->isUnitManagerOnly($event, $selfId)
            && ($ownerId !== $selfId || collect($attendeeIds)->contains(fn ($uid) => $uid !== $selfId));
        if ($involvesOthers && !$this->userCan('CALENDAR_CREATE_FOR_OTHERS')) {
            return response()->json([
                'success' => false,
                'message' => 'این رویداد به کاربرانِ دیگری هم مربوط است و شما اجازهٔ تغییرِ آن را ندارید.',
            ], 403);
        }

        // یادآوری‌هایِ فعلی (پیش از به‌روزرسانی) — ایجادکننده، مالک و شرکت‌کنندگان
        $reminderUsers = collect([$creatorId, $ownerId])->merge(collect($attendees)->pluck('UserID')->map(fn ($v) => (int) $v))->unique();
        $offsets = [];
        foreach ($reminderUsers as $uid) {
            $row = DB::select('EXEC sp_GetReminder @EntityType = ?, @EntityID = ?, @UserID = ?', [self::ENTITY_TYPE, $id, $uid])[0] ?? null;
            if ($row) {
                $offsets[$uid] = (int) $row->OffsetMinutes;
            }
        }

        $start = Carbon::parse($validated['StartDateTime'])->format('Y-m-d H:i:s');
        $end = Carbon::parse($validated['EndDateTime'])->format('Y-m-d H:i:s');

        $result = DB::select(
            'EXEC sp_UpdateCalendarEvent
                @EventID = ?, @Title = ?, @Description = ?, @StartDateTime = ?, @EndDateTime = ?, @IsAllDay = ?,
                @Color = ?, @Status = ?, @RecurrenceType = ?, @RecurrenceInterval = ?, @RecurrenceEndDate = ?,
                @OwnerUserID = ?, @OrganizationalUnitID = ?, @AttendeeUserIDs = ?, @ModifyUser = ?',
            [
                $id,
                $event->Title,
                $event->Description,
                $start,
                $end,
                (int) $event->IsAllDay,
                $event->Color,
                $event->Status,
                $event->RecurrenceType,
                (int) $event->RecurrenceInterval,
                $event->RecurrenceEndDate,
                $ownerId,
                $event->OrganizationalUnitID,
                implode(',', $attendeeIds),
                $selfId,
            ]
        );
        $response = (array) ($result[0] ?? []);
        if (empty($response['Success'])) {
            return response()->json(['success' => false, 'message' => $response['Message'] ?? 'خطا در جابه‌جایی رویداد'], 422);
        }

        // انتقالِ یادآوری‌ها به زمانِ تازه با همان فاصله (OffsetMinutes بدونِ تغییر)
        foreach ($offsets as $uid => $offset) {
            DB::select(
                'EXEC sp_SaveReminder
                    @EntityType = ?, @EntityID = ?, @UserID = ?, @OffsetMinutes = ?, @EntityStartDateTime = ?, @CreatedByUserID = ?, @Title = ?',
                [self::ENTITY_TYPE, $id, $uid, $offset, $start, $selfId, null]
            );
        }

        return $this->updatedResponse('زمانِ رویداد تغییر کرد.', $response);
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
        // عضوِ واحد که شرکت‌کننده نیست فعلاً یادآوریِ شخصی ندارد (با خروج از واحد باید قطع شود)
        if ($event->MyRelation === 'UNIT') {
            return response()->json(['success' => false, 'message' => 'برایِ رویدادِ واحد فقط شرکت‌کنندگان می‌توانند یادآوری تنظیم کنند.'], 403);
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

    /**
     * دعوت‌هایِ در انتظارِ پاسخِ کاربرِ جاری (پنلِ «دعوت‌ها» و اعلانِ داخلِ برنامه) — مستقل از UserNotifications.
     * InvitedAt = نسخهٔ دعوت؛ مرورگر هر نسخه را فقط یک‌بار اعلان می‌کند. قواعدِ دسترسی/لغو در خودِ SP (پچِ ۱۰۹).
     */
    public function pendingInvitations()
    {
        $this->authorizeCalendar('CALENDAR_VIEW');

        return response()->json([
            'invitations' => DB::select('EXEC sp_GetPendingCalendarInvitations @UserID = ?', [Auth::id()]),
        ]);
    }

    public function dueReminders()
    {
        // پچِ ۱۱۳: یادآوریِ تکرارشونده‌ای که وقوعش گذشته (بی «دیدم») اول به وقوعِ بعدی می‌رود — مستقل از روشن بودنِ Push
        app(CalendarReminderRoller::class)->roll((int) Auth::id());
        $rows = DB::select('EXEC sp_GetDueCalendarReminders @UserID = ?', [Auth::id()]);

        // موضوع و متنِ یادآوری برایِ پنجرهٔ یادآوری — فقط با دسترسیِ خودِ کاربر به رویداد (همان قاعدهٔ جزئیاتِ رویداد)
        foreach ($rows as $row) {
            $content = ReminderContent::for((object) ['EntityType' => $row->EntityType, 'EntityID' => $row->EventID, 'Title' => $row->Title,
                'RemindAt' => $row->RemindAt, 'OffsetMinutes' => $row->OffsetMinutes], (int) Auth::id());
            $row->Title = $content['title'] !== '' ? $content['title'] : null; // رویدادِ غیرقابلِ‌مشاهده → بدونِ عنوان
            $row->Description = $content['description'];
        }

        return response()->json(['reminders' => $rows]);
    }

    /** پچِ ۱۱۳: اطلاع‌هایِ لغو/حذفِ جلسه‌هایی که کاربر در آن‌ها بوده (۷ روزِ اخیر) — برایِ اعلانِ داخلِ برنامه */
    public function notices()
    {
        $this->authorizeCalendar('CALENDAR_VIEW');

        return response()->json([
            'notices' => DB::select('EXEC sp_GetCalendarNotices @UserID = ?', [Auth::id()]),
        ]);
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
            'EXEC sp_GetCalendarEvents @UserID = ?, @FromDate = ?, @ToDate = ?, @ViewerID = ?',
            [$userId, $from->toDateTimeString(), $to->toDateTimeString(), Auth::id()]
        );

        return $this->expandRows($rows, $from, $to);
    }

    /** بسطِ تکرارِ ردیف‌هایِ رویداد (همان منطقِ فعلی) و مرتب‌سازی */
    private function expandRows(array $rows, Carbon $from, Carbon $to): array
    {
        $occurrences = [];
        foreach ($rows as $row) {
            foreach ($this->expandRecurrence($row, $from, $to) as $occ) {
                $occurrences[] = $occ;
            }
        }
        usort($occurrences, fn ($a, $b) => strcmp($a->StartDateTime, $b->StartDateTime));

        return $occurrences;
    }

    /** وقوع‌هایِ رویداد در بازه — تکرارِ ماهانه/سالانه بر اساسِ تقویمِ شمسی (JalaliRecurrence) */
    private function expandRecurrence(object $row, Carbon $from, Carbon $to): array
    {
        $start = Carbon::parse($row->StartDateTime);
        $end = Carbon::parse($row->EndDateTime);

        $makeOccurrence = function (int $occurrenceIndex, Carbon $occStart, Carbon $occEnd) use ($row) {
            $clone = clone $row;
            $clone->OccurrenceKey = $row->EventID . ($occurrenceIndex > 0 ? ('_' . $occurrenceIndex) : '');
            $clone->IsRecurringInstance = $occurrenceIndex > 0;
            $clone->StartDateTime = $occStart->toDateTimeString();
            $clone->EndDateTime = $occEnd->toDateTimeString();

            return $clone;
        };

        if ($row->RecurrenceType === 'NONE' || !in_array($row->RecurrenceType, JalaliRecurrence::TYPES, true)) {
            return $start->lte($to) && $end->gte($from) ? [$makeOccurrence(0, $start, $end)] : [];
        }

        $seriesEnd = $row->RecurrenceEndDate ? Carbon::parse($row->RecurrenceEndDate)->endOfDay() : null;

        return array_map(
            fn (array $o) => $makeOccurrence($o[0], $o[1], $o[2]),
            JalaliRecurrence::between($start, $end, $row->RecurrenceType, (int) $row->RecurrenceInterval, $seriesEnd, $from, $to)
        );
    }

    /**
     * یادآوریِ «برای دیگران» فقط برایِ مالک و شرکت‌کنندگانِ همین رویداد مجاز است — وگرنه کاربرِ بی‌ارتباط
     * عنوانِ رویداد را در پنجرهٔ یادآوری می‌دید (فرم هم فقط شرکت‌کنندگان را می‌فرستد؛ این کنترلِ سمتِ سرور است).
     */
    private function hasUnrelatedReminderUsers(array $attendeeReminders, array $attendeeIds, int $ownerUserId): bool
    {
        $allowed = array_merge($attendeeIds, [$ownerUserId]);
        foreach (array_keys($attendeeReminders) as $uid) {
            if (!in_array((int) $uid, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    // ---------- تقویمِ مشترک (پچِ ۱۱۰) ----------

    /** سطحِ دسترسیِ کاربرِ جاری به تقویمِ شخصیِ کاربرِ دیگر: OWNER | ADMIN | READ | FREEBUSY | NONE */
    private function calendarAccess(int $ownerUserId): string
    {
        return (string) (DB::selectOne('EXEC sp_Calendar_GetAccess @ViewerID = ?, @OwnerUserID = ?', [Auth::id(), $ownerUserId])->AccessLevel ?? 'NONE');
    }

    /**
     * «آزاد/مشغول»: فقط بازه‌هایِ زمانی. عنوان، توضیح، نامِ افراد، شناسه و هر جزئیاتِ دیگر همین‌جا (سمتِ سرور) حذف می‌شود.
     * رویدادِ لغوشده و دعوتی که صاحبِ تقویم رد کرده «مشغول» نیست.
     */
    private function maskBusyOnly(array $occurrences): array
    {
        $busy = [];
        foreach ($occurrences as $ev) {
            if (($ev->Status ?? '') === 'CANCELLED' || (($ev->MyRelation ?? '') === 'ATTENDEE' && ($ev->MyResponseStatus ?? '') === 'REJECTED')) {
                continue;
            }
            $busy[] = (object) [
                'OccurrenceKey'           => 'busy-' . count($busy),
                'EventID'                 => 0,
                'IsBusyOnly'              => true,
                'IsRecurringInstance'     => false,
                'Title'                   => 'مشغول',
                'Description'             => null,
                'StartDateTime'           => $ev->StartDateTime,
                'EndDateTime'             => $ev->EndDateTime,
                'IsAllDay'                => (int) $ev->IsAllDay,
                'Color'                   => '#9CA3AF',
                'Status'                  => ($ev->Status ?? '') === 'TENTATIVE' ? 'TENTATIVE' : 'CONFIRMED',
                'RecurrenceType'          => 'NONE',
                'RecurrenceInterval'      => 1,
                'RecurrenceEndDate'       => null,
                'CreatedByUserID'         => 0,
                'CreatorName'             => null,
                'OwnerUserID'             => 0,
                'OwnerName'               => null,
                'OrganizationalUnitID'    => null,
                'CanManage'               => 0,
                'MyRelation'              => 'NONE',
                'MyResponseStatus'        => null,
                'MyReminderOffsetMinutes' => null,
            ];
        }

        return $busy;
    }

    /**
     * رویدادهایِ تقویمِ شخصِ دیگر برایِ بیننده:
     *  - بیننده خودش سازنده/مالک/شرکت‌کنندهٔ فعال است (ViewerIsParticipant) → همان رویداد با شناسهٔ واقعی؛ چون نسخهٔ خودش
     *    در تقویمِ خودش هست، UI آن را تکراری حذف می‌کند (در FREEBUSY هم، برایِ همهٔ رویدادها) — چیزی تازه لو نمی‌رود.
     *  - FREEBUSY → بقیه فقط «مشغول» بدونِ شناسه و جزئیات.
     *  - READ/ADMIN → جزئیاتِ فقط‌خواندنی، به‌جز رویدادِ خصوصی که «مشغول» می‌شود (CALENDAR_VIEW_OTHERS هم).
     * فیلدهایِ «من» (CanManage، MyRelation، پاسخ، یادآوری) نسبت به صاحبِ تقویم محاسبه شده‌اند و خنثی می‌شوند.
     */
    private function forViewer(array $events, string $access): array
    {
        if ($access === 'OWNER') {
            return $events;
        }

        $visible = [];
        $busy = [];
        foreach ($events as $ev) {
            $participant = (int) ($ev->ViewerIsParticipant ?? 0) === 1;
            if (!$participant && ($access === 'FREEBUSY' || (int) ($ev->IsPrivate ?? 0) === 1)) {
                $busy[] = $ev;
                continue;
            }
            $ev = clone $ev;
            $ev->CanManage = 0;
            $ev->MyRelation = 'NONE';
            $ev->MyResponseStatus = null;
            $ev->MyReminderOffsetMinutes = null;
            unset($ev->ViewerIsParticipant);
            $visible[] = $ev;
        }

        $all = array_merge($visible, $this->maskBusyOnly($busy));
        usort($all, fn ($a, $b) => strcmp($a->StartDateTime, $b->StartDateTime));

        return $all;
    }

    /** واحدهایِ کاربرِ جاری (عضویتِ مستقیمِ فعال) با CanManageEvents */
    private function myUnits(): array
    {
        return $this->unitsCache[(int) Auth::id()] ??= DB::select('EXEC sp_Calendar_GetUserUnits @UserID = ?', [Auth::id()]);
    }

    /** عضوِ مستقیمِ این واحد + CALENDAR_UNIT_MANAGE */
    private function canManageUnit(int $unitId): bool
    {
        return collect($this->myUnits())->contains(fn ($u) => (int) $u->UnitID === $unitId && (int) $u->CanManageEvents === 1);
    }

    /** کاربر فقط به‌عنوانِ مدیرِ رویدادِ واحد (نه سازنده/مالک) اجازهٔ مدیریت دارد */
    private function isUnitManagerOnly(object $event, int $userId): bool
    {
        return $event->OrganizationalUnitID !== null
            && (int) $event->CreatedByUserID !== $userId
            && (int) $event->OwnerUserID !== $userId
            && (int) ($event->CanManage ?? 0) === 1;
    }

    /** آیا سازنده/مالک/یکی از شرکت‌کنندگانِ رویداد تقویمش را با سطحِ READ با کاربرِ جاری به اشتراک گذاشته؟ */
    private function hasReadShareFor(object $event, array $attendees): bool
    {
        $readOwners = collect(DB::select('EXEC sp_Calendar_GetSharedWithMe @UserID = ?', [Auth::id()]))
            ->filter(fn ($s) => $s->AccessLevel === 'READ')->map(fn ($s) => (int) $s->OwnerUserID)->all();
        if (!$readOwners) {
            return false;
        }
        $related = collect($attendees)->map(fn ($a) => (int) $a->UserID)
            ->push((int) $event->OwnerUserID, (int) $event->CreatedByUserID)->unique()->all();

        return (bool) array_intersect($readOwners, $related);
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
        return JalaliRecurrence::next($masterStart, $recType, $interval, $after, $seriesEnd, $orAfter);
    }

    /**
     * پاسخِ ویرایش/جابه‌جایی: تغییرِ زمان/مدت/تمام‌روز/تکرار پاسخِ شرکت‌کنندگانِ قبلی را بازنشانی می‌کند (پچِ ۱۱۲؛ در SP)
     * و به آن‌ها یک‌بار اعلانِ «تغییرِ جلسه» می‌رسد — تعدادشان برایِ نمایش به ویرایشگر برمی‌گردد.
     */
    private function updatedResponse(string $message, array $spResult)
    {
        $reset = (int) ($spResult['ResponsesReset'] ?? 0);
        if ($reset > 0) {
            $message .= " پاسخِ {$reset} شرکت‌کننده به‌خاطرِ تغییرِ زمان بازنشانی شد و به آن‌ها اطلاع داده می‌شود.";
        }

        return response()->json(['success' => true, 'message' => $message, 'responsesReset' => $reset]);
    }

    /** همان قاعدهٔ sp_UpdateCalendarEvent (پچِ ۱۱۲): شروع، پایان، تمام‌روز یا قاعدهٔ تکرار عوض شده؟ */
    private function timingChanged(object $current, array $validated): bool
    {
        $norm = fn (?string $type, $interval, $endDate) => ($type ?? 'NONE') === 'NONE'
            ? ['NONE', null, null]
            : [$type, (int) ($interval ?: 1), $endDate ? Carbon::parse($endDate)->toDateString() : null];

        return Carbon::parse($current->StartDateTime)->toDateTimeString() !== Carbon::parse($validated['StartDateTime'])->toDateTimeString()
            || Carbon::parse($current->EndDateTime)->toDateTimeString() !== Carbon::parse($validated['EndDateTime'])->toDateTimeString()
            || (bool) (int) $current->IsAllDay !== (bool) ($validated['IsAllDay'] ?? false)
            || $norm($current->RecurrenceType, $current->RecurrenceInterval, $current->RecurrenceEndDate)
                !== $norm($validated['RecurrenceType'] ?? 'NONE', $validated['RecurrenceInterval'] ?? 1, $validated['RecurrenceEndDate'] ?? null);
    }

    /** یادآوری‌هایِ فعالِ کاربرانِ مرتبط با رویداد (به‌جز $skip) با همان فاصله به شروعِ تازه منتقل می‌شوند */
    private function reanchorReminders(int $eventId, string $start, array $recurrence, int $byUserId, array $skip): void
    {
        $event = DB::select('EXEC sp_GetCalendarEvent @EventID = ?, @UserID = ?', [$eventId, $byUserId])[0] ?? null;
        if (!$event) {
            return;
        }
        $users = collect(DB::select('EXEC sp_GetCalendarEventAttendees @EventID = ?', [$eventId]))->pluck('UserID')
            ->push($event->CreatedByUserID, $event->OwnerUserID)
            ->map(fn ($v) => (int) $v)->unique()->reject(fn ($u) => in_array($u, $skip, true));

        foreach ($users as $uid) {
            $row = DB::select('EXEC sp_GetReminder @EntityType = ?, @EntityID = ?, @UserID = ?', [self::ENTITY_TYPE, $eventId, $uid])[0] ?? null;
            if ($row) {
                $this->saveReminderFor($eventId, $uid, $start, (int) $row->OffsetMinutes, $byUserId, $recurrence);
            }
        }
    }

    /** پایانِ تکرار (شاملِ همان روز) پیش از روزِ شروعِ رویداد → سریِ خالی/نامعتبر */
    private function recurrenceEndsBeforeStart(array $validated): bool
    {
        if (($validated['RecurrenceType'] ?? 'NONE') === 'NONE' || empty($validated['RecurrenceEndDate'])) {
            return false;
        }

        return Carbon::parse($validated['RecurrenceEndDate'])->toDateString() < Carbon::parse($validated['StartDateTime'])->toDateString();
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
            'IsPrivate'             => 'nullable|boolean',
        ]);
    }

    // ---------- دسترسی ----------

    private function loadPermissions(): array
    {
        $userId = (int) Auth::id();
        if (!isset($this->permissionCache[$userId])) {
            $rows = DB::select('EXEC sp_GetUserPermissions @UserID = ?', [$userId]);
            $this->permissionCache[$userId] = collect($rows)->pluck('PermissionCode')->all();
        }

        return $this->permissionCache[$userId];
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
