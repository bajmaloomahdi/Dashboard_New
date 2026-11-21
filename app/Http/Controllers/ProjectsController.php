<?php

namespace App\Http\Controllers;

use App\Services\Crm\CrmPartyService;
use App\Services\Crm\Exceptions\CrmException;
use App\Services\Crm\Exceptions\CrmValidationException;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ProjectsController extends Controller
{
    public function __construct(private CrmPartyService $crmParties)
    {
    }

    /**
     * لیست پروژه‌ها + داده‌های کمکی (کاربران و وضعیت‌ها) برای فرم‌ها
     * فقط پروژه‌هایی که کاربر جاری عضو یا مسئول آن است نمایش داده می‌شود.
     */
    public function index(Request $request)
    {
        $search   = $request->input('search');
        $isActive = $request->input('is_active');
        $statusId = $request->input('project_status_id');

        $projects = DB::select(
            'EXEC sp_GetProjects @SearchText = ?, @IsActive = ?, @ProjectStatusID = ?, @UserID = ?',
            [
                $search ?: null,
                $isActive !== null && $isActive !== '' ? (int) $isActive : null,
                $statusId !== null && $statusId !== '' ? (int) $statusId : null,
                Auth::id(),
            ]
        );

        foreach ($projects as $project) {
            $project->MemberNames = collect(DB::select('EXEC sp_GetProjectMembers @ProjectID = ?', [$project->ProjectID]))
                ->filter(fn ($member) => (int) $member->IsActive === 1)
                ->pluck('FullName')->filter()->unique()->values();
        }

        $users = DB::select('EXEC sp_GetUsers @SearchText = NULL, @IsActive = 1');
        $this->attachPositionTitles($users);

        $statuses = DB::select('EXEC sp_GetProjectStatuses @IsActive = NULL');
        $priorities = DB::select('EXEC sp_GetProjectPriorities @IsActive = NULL');

        return Inertia::render('Projects/Index', [
            'projects'   => $projects,
            'departmentOptions' => DB::select('EXEC dbo.sp_Crm_GetDepartments @IsActive = 1'),
            'parentOptions' => collect(DB::select('EXEC sp_GetProjects @UserID = ?', [Auth::id()]))
                ->map(fn ($p) => ['id' => $p->ProjectID, 'title' => $p->ProjectTitle, 'parentId' => $p->ParentProjectID])->values(),
            'users'      => $users,
            'statuses'   => $statuses,
            'priorities' => $priorities,
            'canLinkCrm' => $this->canLinkProjectCrm(),
            'filters'    => [
                'search'            => $search,
                'is_active'         => $isActive,
                'project_status_id' => $statusId,
            ],
        ]);
    }

    /**
     * جزئیات یک پروژه (فقط برای اعضا/مسئول/سازنده همان پروژه)
     */
    public function show(int $id)
    {
        $projects = DB::select(
            'EXEC sp_GetProjects @SearchText = NULL, @IsActive = NULL, @ProjectStatusID = NULL, @UserID = ?',
            [Auth::id()]
        );
        $project = collect($projects)->firstWhere('ProjectID', $id);

        if (!$project) {
            return redirect()->route('projects.index')
                ->with('error', 'پروژه مورد نظر یافت نشد یا به آن دسترسی ندارید.');
        }

        $members = DB::select('EXEC sp_GetProjectMembers @ProjectID = ?', [$id]);
        $this->attachPositionTitles($members);

        $users = DB::select('EXEC sp_GetUsers @SearchText = NULL, @IsActive = 1');
        $this->attachPositionTitles($users);

        $msgPriorities = DB::select('EXEC sp_GetMsgPriorities @SearchText = NULL, @IsActive = 1');

        $contractors = DB::select('EXEC sp_GetProjectContractors @ProjectID = ?', [$id]);

        // فقط فهرستِ خواندنیِ نوعِ تعامل (Master Data CRM) — برایِ فرمِ «ثبتِ تعامل» در تبِ پیمانکاران؛
        // بدونِ نیاز به Permissionِ CRM (دسترسی از همینجا، هم‌سطحِ بقیهٔ دادهٔ این صفحه، مجوزش canManage است).
        $interactionTypes = DB::select('EXEC sp_Crm_GetInteractionTypes @SearchText = NULL, @IsActive = 1');

        return Inertia::render('Projects/Show', [
            'project'          => $project,
            'departmentOptions' => DB::select('EXEC dbo.sp_Crm_GetDepartments @IsActive = 1'),
            'parentProject'    => collect($projects)->firstWhere('ProjectID', $project->ParentProjectID),
            'childProjects'    => collect($projects)->filter(fn ($p) => (string) $p->ParentProjectID === (string) $id)
                ->map(fn ($p) => ['id' => $p->ProjectID, 'title' => $p->ProjectTitle])->values(),
            'parentOptions'    => collect($projects)
                ->map(fn ($p) => ['id' => $p->ProjectID, 'title' => $p->ProjectTitle, 'parentId' => $p->ParentProjectID])->values(),
            'editStatuses'     => DB::select('EXEC sp_GetProjectStatuses @IsActive = NULL'),
            'editPriorities'   => DB::select('EXEC sp_GetProjectPriorities @IsActive = NULL'),
            'members'          => $members,
            'projectStatuses'  => $this->canManageProjectTeam($members, (int) Auth::id())
                ? DB::select('EXEC sp_GetProjectStatuses @IsActive = 1') : [],
            'users'            => $users,
            'msgPriorities'    => $msgPriorities,
            'contractors'      => $contractors,
            'interactionTypes' => $interactionTypes,
            'owner'            => DB::selectOne('EXEC sp_GetProjectOwner @ProjectID = ?', [$id]),
            // همهٔ برندهایِ فعال — گزینه‌هایِ برند وقتی طرف‌حساب انتخاب نشده (پروژهٔ داخلی)
            'allBrands'        => $this->brandOptions(DB::select('EXEC sp_Crm_GetBrands @SearchText = NULL, @IsActive = 1')),
            // پچِ ۱۳۸ — تبِ «رویدادها»: پنجرهٔ جزئیاتِ رویداد همان مجوزهایِ تقویمِ کاربر را لازم دارد
            'calendarPermissions' => collect(DB::select('EXEC sp_GetUserPermissions @UserID = ?', [Auth::id()]))
                ->pluck('PermissionCode')->filter(fn ($c) => str_starts_with((string) $c, 'CALENDAR_'))->values()->all(),
        ]);
    }

    /**
     * GET projects/{id}/owner/party-brands?partyId= — برندهایِ همان طرف‌حساب، از همان ساختارِ موجودِ CRM
     * (ردیف‌هایِ فعالِ CrmPartyBrandCategories؛ همان تعریفی که تبِ «برندها»ی طرف‌حساب نشان می‌دهد).
     */
    public function ownerPartyBrands(Request $request, int $id)
    {
        if (!$this->canManage($id)) {
            return response()->json(['brands' => []], 403);
        }

        $partyId = (int) $request->input('partyId');
        $rows = array_filter(
            DB::select('EXEC sp_Crm_GetPartyBrandCategories @PartyID = ?, @BrandID = NULL', [$partyId]),
            fn ($r) => (bool) $r->IsActive && (bool) $r->BrandIsActive
        );

        return response()->json(['brands' => $this->brandOptions($rows, 'BrandName')]);
    }

    /** POST projects/{id}/owner — ذخیرهٔ مالکِ پروژه (طرف‌حسابِ اختیاری + برند)؛ فقط مسئولِ پروژه. */
    public function saveOwner(Request $request, int $id)
    {
        if (!$this->canManage($id)) {
            return response()->json([
                'success' => false,
                'message' => 'فقط مسئول پروژه می‌تواند مالکِ پروژه را تعیین کند.',
            ], 403);
        }

        $validated = $request->validate([
            'OwnerPartyID' => 'nullable|integer|exists:CrmParties,PartyID',
            'OwnerBrandID' => 'nullable|integer|exists:CrmBrands,BrandID',
        ]);

        $result = DB::select(
            'EXEC sp_SaveProjectOwner @ProjectID = ?, @OwnerPartyID = ?, @OwnerBrandID = ?, @UserID = ?',
            [$id, $validated['OwnerPartyID'] ?? null, $validated['OwnerBrandID'] ?? null, Auth::id()]
        );

        $response = (array) ($result[0] ?? []);

        return response()->json([
            'success' => !empty($response['Success']),
            'message' => $response['Message'] ?? '',
            'owner'   => DB::selectOne('EXEC sp_GetProjectOwner @ProjectID = ?', [$id]),
        ]);
    }

    /** فقط شناسه و نامِ برند (بدونِ مسیرِ فایلِ لوگو)؛ بدونِ تکرار. */
    private function brandOptions(array $rows, string $nameField = 'Name'): array
    {
        return collect($rows)
            ->map(fn ($r) => ['BrandID' => (int) $r->BrandID, 'Name' => $r->{$nameField}])
            ->unique('BrandID')
            ->sortBy('Name')
            ->values()
            ->all();
    }

    /**
     * لیست وظیفه‌های یک پروژه (JSON، برای بارگذاری داخل صفحه جزئیات)
     * فقط برای اعضای فعال همان پروژه.
     */
    public function tasks(int $id)
    {
        if (!$this->isActiveMember($id)) {
            return response()->json(['tasks' => []], 403);
        }

        $tasks = DB::select('EXEC sp_GetProjectTasks @ProjectID = ?, @UserID = ?', [$id, Auth::id()]);
        return response()->json(['tasks' => $tasks]);
    }

    /**
     * لیست کامنت‌ها و ضمیمه‌های یک پروژه (JSON) — فقط برای اعضای فعال
     */
    public function updateCommentOrAttachment(Request $request, int $id, string $kind, int $itemId)
    {
        abort_unless($this->isActiveMember($id), 403, 'به این پروژه دسترسی ندارید.');
        $isComment = $kind === 'comment';
        $input = $request->validate(['text' => $isComment ? 'required|string|max:3000' : 'nullable|string|max:500']);
        $sql = $isComment
            ? 'EXEC dbo.sp_UpdateProjectComment ?, ?, ?, ?'
            : 'EXEC dbo.sp_UpdateProjectAttachmentDescription ?, ?, ?, ?';
        $result = DB::selectOne($sql, [$id, $itemId, Auth::id(), $input['text'] ?? null]);
        abort_unless((int) ($result->Updated ?? 0) === 1, 403, 'مجوز ویرایش این مورد را ندارید.');

        return response()->json(['message' => 'تغییرات ذخیره شد.']);
    }

    public function comments(int $id)
    {
        if (!$this->isActiveMember($id)) {
            return response()->json(['comments' => [], 'attachments' => []], 403);
        }

        $comments = DB::select('EXEC sp_GetProjectComments @ProjectID = ?', [$id]);
        $attachments = DB::select('EXEC sp_GetProjectAttachmentsList @ProjectID = ?', [$id]);

        return response()->json(['comments' => $comments, 'attachments' => $attachments]);
    }

    /**
     * نمایش/دانلود یک ضمیمه‌ی پروژه — از طریق خود PHP سرو می‌شود (نه لینک مستقیم nginx/storage)
     * تا هم مشکل symlink بین کانتینرها دور زده شود و هم فقط اعضای پروژه دسترسی داشته باشند.
     */
    public function downloadAttachment(int $id, int $attachmentId)
    {
        if (!$this->isActiveMember($id)) {
            abort(403, 'شما به این پروژه دسترسی ندارید.');
        }

        $attachments = DB::select('EXEC sp_GetProjectAttachmentsList @ProjectID = ?', [$id]);
        $attachment = collect($attachments)->firstWhere('ProjectAttachmentID', $attachmentId);

        if (!$attachment) {
            abort(404, 'فایل مورد نظر یافت نشد.');
        }

        $disk = Storage::disk('public');
        if (!$disk->exists($attachment->FilePath)) {
            abort(404, 'فایل روی سرور یافت نشد.');
        }

        return $disk->response($attachment->FilePath, $attachment->FileName);
    }

    /**
     * ثبت کامنت و/یا ضمیمه روی پروژه — فقط عضو فعال پروژه
     */
    public function addComment(Request $request, int $id)
    {
        if (!$this->isActiveMember($id)) {
            return response()->json([
                'success' => false,
                'message' => 'فقط اعضای پروژه می‌توانند نظر ثبت کنند.',
            ], 403);
        }

        $validated = $request->validate([
            'Comment'     => 'nullable|string',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:10240',
        ]);

        $comment = $validated['Comment'] ?? null;
        $hasComment = $comment !== null && trim($comment) !== '';
        $hasFiles = $request->hasFile('attachments') && count($request->file('attachments')) > 0;

        if (!$hasComment && !$hasFiles) {
            return response()->json([
                'success' => false,
                'message' => 'نظر یا ضمیمه را وارد کنید.',
            ]);
        }

        if ($hasComment) {
            $result = DB::select(
                'EXEC sp_InsertProjectComment @ProjectID = ?, @UserID = ?, @Comment = ?',
                [$id, Auth::id(), $comment]
            );
            $response = (array) ($result[0] ?? []);
            if (empty($response['Success'])) {
                return response()->json([
                    'success' => false,
                    'message' => $response['Message'] ?? 'خطا در ثبت نظر.',
                ]);
            }
        }

        if ($hasFiles) {
            foreach ($request->file('attachments') as $file) {
                $originalName = $file->getClientOriginalName();
                $extension = $file->getClientOriginalExtension();
                $size = $file->getSize();
                $path = $file->store('projects/' . $id, 'public');

                DB::select(
                    'EXEC sp_InsertProjectAttachment
                        @ProjectID = ?, @FileName = ?, @FileExtension = ?, @FileSize = ?, @FilePath = ?, @CreateUser = ?',
                    [$id, $originalName, $extension, $size, $path, Auth::id()]
                );
            }
        }

        $message = $hasComment && $hasFiles
            ? 'نظر و ضمیمه با موفقیت ثبت شد.'
            : ($hasComment ? 'نظر با موفقیت ثبت شد.' : 'ضمیمه با موفقیت اضافه شد.');

        return response()->json(['success' => true, 'message' => $message]);
    }

    /**
     * آیا کاربر جاری عضو فعال این پروژه است؟
     */
    private function isActiveMember(int $projectId): bool
    {
        $userId = Auth::id();
        if (!$userId) {
            return false;
        }

        $exists = DB::select(
            'SELECT 1 FROM dbo.ProjectMembers WHERE ProjectID = ? AND UserID = ? AND IsActive = 1',
            [$projectId, $userId]
        );

        return !empty($exists);
    }

    /**
     * ایجاد وظیفه برای یکی از اعضای پروژه (فقط مسئول فعال پروژه)
     * موضوع وظیفه به‌صورت خودکار از عنوان و کد پروژه ساخته می‌شود.
     */
    public function createTask(Request $request, int $id)
    {
        if (!$this->canManage($id)) {
            return response()->json([
                'success' => false,
                'message' => 'فقط مسئول پروژه می‌تواند وظیفه ایجاد کند.',
            ], 403);
        }

        $validated = $request->validate([
            'ToUserID'      => 'required|integer',
            'msgPriorityID' => 'required|integer',
            'MessageText'   => 'nullable|string',
            'DueDate'       => 'nullable|date',
        ]);

        $projects = DB::select(
            'EXEC sp_GetProjects @SearchText = NULL, @IsActive = NULL, @ProjectStatusID = NULL, @UserID = ?',
            [Auth::id()]
        );
        $project = collect($projects)->firstWhere('ProjectID', $id);

        if (!$project) {
            return response()->json([
                'success' => false,
                'message' => 'پروژه یافت نشد یا به آن دسترسی ندارید.',
            ], 404);
        }

        $subject = $project->ProjectTitle . ' - ' . $project->ProjectCode;

        $result = DB::select(
            'EXEC sp_InsertProjectTask
                @ProjectID = ?, @Subject = ?, @MessageText = ?, @ToUserID = ?, @msgPriorityID = ?,
                @SenderUserID = ?, @Year = ?, @CreateUser = ?, @DueDate = ?',
            [
                $id,
                $subject,
                $validated['MessageText'] ?? null,
                $validated['ToUserID'],
                $validated['msgPriorityID'],
                Auth::id(),
                $this->getJalaliYear(now()),
                Auth::id(),
                $validated['DueDate'] ?? null,
            ]
        );

        $row = $result[0] ?? null;
        $response = (array) ($row ?? []);
        $message = $response['Message'] ?? '';

        // پچِ ۱۳۸ — وظیفهٔ دارایِ مهلت: رویدادِ همان روز ساعتِ ۱۳ در تقویمِ گیرنده (وصل به پروژه و همین وظیفه)
        if (!empty($response['Success']) && !empty($validated['DueDate']) && !empty($response['NewMessageID'])) {
            try {
                $text = trim((string) ($validated['MessageText'] ?? ''));
                $firstLine = $text !== '' ? mb_substr(trim(strtok($text, "\n")), 0, 120) : '';
                $title = 'مهلتِ وظیفهٔ ' . ($response['MessageNumber'] ?? '') . ($firstLine !== '' ? ': ' . $firstLine : ' — ' . $project->ProjectTitle);
                $due = (array) (DB::select(
                    'EXEC dbo.sp_Calendar_CreateTaskDueEvent @ProjectID = ?, @MessageID = ?, @Title = ?, @Description = ?, @DueDate = ?, @CreatorUserID = ?, @AssigneeUserID = ?',
                    [$id, (int) $response['NewMessageID'], mb_substr($title, 0, 250), trim($text . "\nپروژه: " . $subject), Carbon::parse($validated['DueDate'])->toDateString(), Auth::id(), (int) $validated['ToUserID']],
                )[0] ?? []);
                if (!empty($due['Success'])) {
                    $message .= ' رویدادِ مهلت هم در تقویم ثبت شد (ساعتِ ۱۳).';
                }
            } catch (\Throwable $e) {
                report($e); // ثبتِ وظیفه انجام شده؛ نبودِ رویداد مانعِ آن نیست
            }
        }

        return response()->json([
            'success' => !empty($response['Success']),
            'message' => $message,
        ]);
    }

    /**
     * محاسبه سال شمسی (همانند منطق MessageController)
     */
    private function getJalaliYear($date): int
    {
        $gy = (int) $date->format('Y');
        $gm = (int) $date->format('n');
        $gd = (int) $date->format('j');

        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $jy = ($gy <= 1600) ? 0 : 979;
        $gy -= ($gy <= 1600) ? 621 : 1600;
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
              + intdiv($gy2 + 399, 400) - 80 + $gd + $g_d_m[$gm - 1];
        $jy += 33 * intdiv($days, 12053);
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
        }
        return $jy;
    }

    /**
     * ایجاد پروژه (همراه با مسئول و اعضا)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'ParentProjectID'   => 'nullable|integer|min:1',
            'DepartmentID'      => 'nullable|integer|min:1|max:2147483647',
            'ProjectCode'      => 'nullable|string|max:50',
            'ProjectTitle'     => 'required|string|max:250',
            'Description'      => 'nullable|string',
            'StartDate'        => 'nullable|date',
            'PlannedEndDate'   => 'nullable|date',
            'ActualEndDate'    => 'nullable|date',
            'ProjectStatusID'  => 'required|integer',
            'ProjectPriorityID' => 'nullable|integer',
            'ProgressPercent'  => 'nullable|numeric|min:0|max:100',
            'IsActive'         => 'boolean',
            'ResponsibleUserID' => 'required|integer',
            'MemberUserIDs'    => 'nullable|array',
            'MemberUserIDs.*'  => 'integer',
            'OwnerPartyID'     => 'nullable|integer|min:1',
            'ContractorPartyIDs' => 'nullable|array|max:50',
            'ContractorPartyIDs.*' => 'integer|min:1|distinct',
        ]);

        $owner = $validated['OwnerPartyID'] ?? null;
        $contractors = $validated['ContractorPartyIDs'] ?? [];
        abort_if(($owner || $contractors) && !$this->canLinkProjectCrm(), 403);

        $response = DB::transaction(function () use ($validated, $owner, $contractors) {
            $result = DB::select(
                'EXEC sp_InsertProject
                    @ProjectCode = ?, @ProjectTitle = ?, @Description = ?, @StartDate = ?,
                    @PlannedEndDate = ?, @ActualEndDate = ?, @ProjectStatusID = ?, @ProjectPriorityID = ?,
                    @ProgressPercent = ?, @IsActive = ?, @ResponsibleUserID = ?, @MemberUserIDs = ?, @CreateUser = ?, @Year = ?, @ParentProjectID = ?, @DepartmentID = ?',
                [
                    $validated['ProjectCode'] ?? null,
                    $validated['ProjectTitle'],
                    $validated['Description'] ?? null,
                    $validated['StartDate'] ?? null,
                    $validated['PlannedEndDate'] ?? null,
                    $validated['ActualEndDate'] ?? null,
                    $validated['ProjectStatusID'],
                    $validated['ProjectPriorityID'] ?? null,
                    $validated['ProgressPercent'] ?? 0,
                    $validated['IsActive'] ?? true,
                    $validated['ResponsibleUserID'],
                    isset($validated['MemberUserIDs']) ? implode(',', $validated['MemberUserIDs']) : null,
                    Auth::id(),
                    $this->getJalaliYear(now()),
                    $validated['ParentProjectID'] ?? null,
                    $validated['DepartmentID'] ?? null,
                ]
            );

            $response = (array) ($result[0] ?? []);

            if (empty($response['Success'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    (!empty($response['DepartmentError']) ? 'DepartmentID' : (!empty($response['ParentError']) ? 'ParentProjectID' : 'ProjectTitle')) => $response['Message'] ?? 'خطا در ایجاد پروژه',
                ]);
            }

            $projectId = (int) ($response['NewProjectID'] ?? 0);
            $actor = (int) Auth::id();
            abort_unless($projectId > 0, 500);
            if ($owner) {
                $this->requireProjectCreationStep(DB::selectOne(
                    'EXEC sp_SaveProjectOwner @ProjectID = ?, @OwnerPartyID = ?, @OwnerBrandID = NULL, @UserID = ?',
                    [$projectId, $owner, $actor]
                ), 'OwnerPartyID', 'ذخیرهٔ مالک پروژه انجام نشد.');
            }
            foreach ($contractors as $partyId) {
                $this->requireProjectCreationStep(DB::selectOne(
                    'EXEC sp_AddProjectContractor @ProjectID = ?, @PartyID = ?, @CreateUser = ?',
                    [$projectId, $partyId, $actor]
                ), 'ContractorPartyIDs', 'ذخیرهٔ پیمانکار پروژه انجام نشد.');
            }
            if (!empty($validated['PlannedEndDate'])) {
                $this->createProjectDeadlineEvent($projectId, $actor, $validated, array_filter([$owner, ...$contractors]));
            }
            return $response;
        });

        return redirect()->route('projects.index')
            ->with('success', $response['Message'] ?? 'پروژه با موفقیت ایجاد شد.');
    }

    private function canLinkProjectCrm(): bool
    {
        return collect(DB::select('EXEC sp_GetUserPermissions @UserID = ?', [Auth::id()]))
            ->contains('PermissionCode', 'CRM_VIEW');
    }

    private function requireProjectCreationStep(?object $result, string $field, string $message): void
    {
        if (empty($result->Success)) {
            throw \Illuminate\Validation\ValidationException::withMessages([$field => $result->Message ?? $message]);
        }
    }

    private function createProjectDeadlineEvent(int $projectId, int $actor, array $project, array $partyIds): void
    {
        $date = \Illuminate\Support\Carbon::parse($project['PlannedEndDate'])->toDateString();
        $members = collect(DB::select('EXEC sp_GetProjectMembers @ProjectID = ?', [$projectId]))
            ->filter(fn ($member) => (int) $member->IsActive === 1)->pluck('UserID')->unique();
        $event = DB::selectOne(
            "EXEC sp_InsertCalendarEvent
                @Title = ?, @StartDateTime = ?, @EndDateTime = ?, @IsAllDay = 0,
                @Status = N'CONFIRMED', @RecurrenceType = N'NONE',
                @OwnerUserID = ?, @AttendeeUserIDs = ?, @CreateUser = ?, @IsPrivate = 0",
            [mb_substr('موعد پایان پروژه: '.$project['ProjectTitle'], 0, 250), "$date 13:00:00", "$date 13:30:00",
                $project['ResponsibleUserID'], $members->implode(','), $actor]
        );
        $this->requireProjectCreationStep($event, 'PlannedEndDate', 'ایجاد رویداد موعد پایان انجام نشد.');
        $this->requireProjectCreationStep(DB::selectOne(
            'EXEC dbo.sp_Calendar_SetEventLinks @EventID = ?, @UserID = ?, @PartyIDs = ?, @ProjectIDs = ?',
            [$event->NewEventID, $actor, implode(',', array_unique($partyIds)), (string) $projectId]
        ), 'PlannedEndDate', 'ارتباط رویداد موعد پایان با پروژه ذخیره نشد.');
        DB::statement('EXEC dbo.sp_SyncProjectDeadlineEvent @ProjectID = ?, @ActorUserID = ?, @EventID = ?',
            [$projectId, $actor, $event->NewEventID]);
    }

    /**
     * ویرایش مشخصات پروژه و تغییر اختیاری ترکیب اعضا.
     */
    public function update(Request $request, int $id)
    {
        $statusOnly = $request->routeIs('projects.status.update');
        $parentOnly = $request->routeIs('projects.parent.update');
        $validated = $request->validate($parentOnly ? [
            'ParentProjectID' => 'present|nullable|integer|min:1',
            'RelatedPageID' => 'required|integer|min:1',
        ] : ($statusOnly ? ['ProjectStatusID' => 'required|integer|min:1'] : [
            'ParentProjectID'   => 'sometimes|nullable|integer|min:1',
            'DepartmentID'      => 'sometimes|nullable|integer|min:1|max:2147483647',
            'ProjectTitle'     => 'required|string|max:250',
            'Description'      => 'nullable|string',
            'StartDate'        => 'nullable|date',
            'PlannedEndDate'   => 'nullable|date',
            'ActualEndDate'    => 'nullable|date',
            'ProjectStatusID'  => 'required|integer',
            'ProjectPriorityID' => 'nullable|integer',
            'ProgressPercent'  => 'nullable|numeric|min:0|max:100',
            'IsActive'         => 'boolean',
            'ResponsibleUserID' => 'required_with:MemberUserIDs|integer|min:1',
            'MemberUserIDs'     => 'array',
            'MemberUserIDs.*'   => 'integer|min:1|distinct',
        ]));

        $actor = (int) Auth::id();
        $current = collect(DB::select(
            'EXEC sp_GetProjects @SearchText = NULL, @IsActive = NULL, @ProjectStatusID = NULL, @UserID = ?',
            [$actor]
        ))->firstWhere('ProjectID', $id);
        abort_if($current === null, 404);
        $hasParent = (bool) ($current->HasParentProject ?? ($current->ParentProjectID !== null));
        if (isset($validated['ParentProjectID']) && $hasParent
            && (int) $validated['ParentProjectID'] !== (int) $current->ParentProjectID) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'ParentProjectID' => 'ابتدا ارتباط با پروژهٔ مادر فعلی را قطع کنید.',
            ]);
        }
        if ($parentOnly) {
            $parentId = (int) $validated['RelatedPageID'];
            $adding = $validated['ParentProjectID'] !== null;
            abort_unless($parentId === $id || ($adding
                ? (int) $validated['ParentProjectID'] === $parentId
                : (int) $current->ParentProjectID === $parentId), 409, 'ارتباط فعلی پروژه تغییر کرده است.');
            abort_unless($this->canManageProjectTeam(DB::select('EXEC sp_GetProjectMembers @ProjectID = ?', [$parentId]), $actor), 403);
        }

        $response = DB::transaction(function () use ($id, $actor, $current, $validated, $statusOnly) {
            $updateTeam = array_key_exists('ResponsibleUserID', $validated) || array_key_exists('MemberUserIDs', $validated);
            $scheduleChanged = (array_key_exists('ProjectTitle', $validated) && $validated['ProjectTitle'] !== $current->ProjectTitle)
                || (array_key_exists('PlannedEndDate', $validated)
                    && substr((string) $validated['PlannedEndDate'], 0, 10) !== substr((string) $current->PlannedEndDate, 0, 10));
            $members = [];
            $statusChanged = array_key_exists('ProjectStatusID', $validated)
                && (int) $validated['ProjectStatusID'] !== (int) $current->ProjectStatusID;
            if ($updateTeam || $statusChanged || $statusOnly || $scheduleChanged) {
                $members = DB::select('EXEC sp_GetProjectMembers @ProjectID = ?', [$id]);
                abort_unless($this->canManageProjectTeam($members, $actor), 403);
            }
            if ($statusChanged) {
                $statuses = DB::select('EXEC sp_GetProjectStatuses @IsActive = 1');
                if (!collect($statuses)->contains(fn ($status) => (int) $status->ProjectStatusID === (int) $validated['ProjectStatusID'])) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['ProjectStatusID' => 'وضعیت انتخاب‌شده فعال یا معتبر نیست.']);
                }
            } elseif ($statusOnly) {
                return ['Message' => 'وضعیت پروژه بدون تغییر است.'];
            }
            if ($updateTeam) {
                if (! isset($validated['ResponsibleUserID'], $validated['MemberUserIDs'])) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'MemberUserIDs' => 'مسئول و فهرست اعضا را با هم ارسال کنید.',
                    ]);
                }
            }
            $value = fn (string $key) => array_key_exists($key, $validated) ? $validated[$key] : $current->{$key};
            $result = DB::select(
                'EXEC sp_UpdateProject
                    @ProjectID = ?, @ProjectCode = ?, @ProjectTitle = ?, @Description = ?,
                    @StartDate = ?, @PlannedEndDate = ?, @ActualEndDate = ?, @ProjectStatusID = ?, @ProjectPriorityID = ?,
                    @ProgressPercent = ?, @IsActive = ?, @ModifyUser = ?, @ParentProjectID = ?, @SetParentProject = ?, @DepartmentID = ?, @SetDepartment = ?',
                [
                    $id,
                    $current->ProjectCode,
                    $value('ProjectTitle'),
                    $value('Description'),
                    $value('StartDate'),
                    $value('PlannedEndDate'),
                    $value('ActualEndDate'),
                    $value('ProjectStatusID'),
                    $value('ProjectPriorityID'),
                    $value('ProgressPercent') ?? $current->ProgressPercent,
                    $value('IsActive'),
                    $actor,
                    $validated['ParentProjectID'] ?? null,
                    (int) array_key_exists('ParentProjectID', $validated),
                    $validated['DepartmentID'] ?? null,
                    (int) array_key_exists('DepartmentID', $validated),
                ]
            );

            $response = (array) ($result[0] ?? []);
            if (empty($response['Success'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    (!empty($response['DepartmentError']) ? 'DepartmentID' : (!empty($response['ParentError']) ? 'ParentProjectID' : ($statusOnly ? 'ProjectStatusID' : 'ProjectTitle'))) => $response['Message'] ?? 'خطا در ویرایش پروژه',
                ]);
            }
            if ($scheduleChanged) {
                $sync = DB::selectOne('EXEC dbo.sp_SyncProjectDeadlineEvent @ProjectID = ?, @ActorUserID = ?, @SyncSchedule = 1', [$id, $actor]);
                if ($sync && (int) $sync->ScheduleSaved !== 1) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'PlannedEndDate' => 'برای پروژه‌ای که رویداد موعد پایان دارد، تاریخ پایان پیش‌بینی‌شده الزامی است.',
                    ]);
                }
            }
            if ($updateTeam) {
                $this->updateProjectTeam($id, $actor, $members, (int) $validated['ResponsibleUserID'], $validated['MemberUserIDs']);
            }
            return $response;
        });

        return (($parentOnly || $statusOnly || $request->boolean('returnToDetails')) ? redirect()->route('projects.show', $validated['RelatedPageID'] ?? $id) : redirect()->route('projects.index'))
            ->with('success', $response['Message'] ?? 'پروژه با موفقیت ویرایش شد.');
    }

    /**
     * فعال/غیرفعال کردن پروژه
     */
    public function toggleActive(int $id)
    {
        $result = DB::select('EXEC sp_ToggleProjectActive @ProjectID = ?, @ModifyUser = ?', [$id, Auth::id()]);
        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->with('error', $response['Message'] ?? 'خطا در تغییر وضعیت پروژه');
        }

        return redirect()->route('projects.index')
            ->with('success', $response['Message']);
    }

    /**
     * لیست اعضای یک پروژه (برای مودال مدیریت اعضا) — خروجی JSON
     */
    public function members(int $id)
    {
        $members = DB::select('EXEC sp_GetProjectMembers @ProjectID = ?', [$id]);
        $this->attachPositionTitles($members);

        return response()->json(['members' => $members, 'canManage' => $this->canManageProjectTeam($members, (int) Auth::id())]);
    }

    private function canManageProjectTeam(array $members, int $actor): bool
    {
        return $actor > 0 && collect($members)->contains(fn ($member) =>
            (int) $member->UserID === $actor && (int) $member->IsActive === 1 && (int) $member->IsResponsible === 1);
    }

    private function updateProjectTeam(int $projectId, int $actor, array $members, int $responsible, array $memberIds): void
    {
        $active = collect($members)->filter(fn ($row) => (int) $row->IsActive === 1);
        $desired = array_unique([$responsible, ...array_map('intval', $memberIds)]);
        $existing = $active->map(fn ($row) => (int) $row->UserID)->all();
        $currentResponsible = (int) ($active->first(fn ($row) => (int) $row->IsResponsible === 1)->UserID ?? 0);
        $added = array_diff($desired, $existing);
        if ($added !== []) {
            $eligible = array_map(fn ($row) => (int) $row->UserID, DB::select('EXEC sp_GetUsers @SearchText = NULL, @IsActive = 1'));
            if (array_diff($added, $eligible) !== []) {
                throw \Illuminate\Validation\ValidationException::withMessages(['MemberUserIDs' => 'کاربر جدید باید فعال باشد.']);
            }
        }
        foreach ($desired as $id) {
            if (! in_array($id, $existing, true)) {
                $result = DB::selectOne('EXEC sp_AddProjectMember @ProjectID = ?, @UserID = ?, @IsResponsible = ?, @StartDate = NULL, @CreateUser = ?',
                    [$projectId, $id, 0, $actor]);
                $this->assertProjectTeamSaved($result);
            }
        }
        if ($added !== []) {
            DB::statement('EXEC dbo.sp_SyncProjectDeadlineEvent @ProjectID = ?, @ActorUserID = ?', [$projectId, $actor]);
        }
        if ($responsible !== $currentResponsible) {
            $this->assertProjectTeamSaved(DB::selectOne(
                'EXEC sp_AddProjectMember @ProjectID = ?, @UserID = ?, @IsResponsible = 1, @StartDate = NULL, @CreateUser = ?',
                [$projectId, $responsible, $actor]
            ));
        }
        foreach (array_diff($existing, $desired) as $id) {
            $this->assertProjectTeamSaved(DB::selectOne('EXEC sp_RemoveProjectMember @ProjectID = ?, @UserID = ?, @ModifyUser = ?', [$projectId, $id, $actor]));
        }
    }

    private function assertProjectTeamSaved(?object $result): void
    {
        if (empty($result->Success)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'MemberUserIDs' => $result->Message ?? 'ذخیرهٔ اعضای پروژه انجام نشد.',
            ]);
        }
    }

    /**
     * افزودن ستون PositionTitle (سمت فعلی) روی هر آیتم که UserID دارد.
     * چون sp_GetUsers / sp_GetProjectMembers این را برنمی‌گردانند و رابطه‌ی
     * کاربر-سمت در جدول UserPositions (چندبه‌چند) نگه‌داری می‌شود.
     *
     * @param array<int, object{UserID:int}> $rows
     */
    private function attachPositionTitles(array $rows): void
    {
        $positionRows = DB::select("
            SELECT up.UserID, STRING_AGG(p.PositionName, N'، ') AS PositionTitle
            FROM dbo.UserPositions up
            JOIN dbo.Positions p ON p.PositionID = up.PositionID
            WHERE up.IsActive = 1
            GROUP BY up.UserID
        ");
        $positionsByUser = collect($positionRows)->keyBy('UserID');

        foreach ($rows as $row) {
            $row->PositionTitle = optional($positionsByUser->get($row->UserID))->PositionTitle;
        }
    }

    /**
     * افزودن عضو (یا ارتقا به مسئول) — فقط توسط مسئول یا سازنده
     */
    public function addMember(Request $request, int $id)
    {
        if (!$this->canManage($id)) {
            return response()->json([
                'success' => false,
                'message' => 'فقط مسئول پروژه می‌تواند اعضا را مدیریت کند.',
            ], 403);
        }

        $validated = $request->validate([
            'UserID'        => 'required|integer',
            'IsResponsible' => 'boolean',
            'StartDate'     => 'nullable|date',
        ]);

        $response = DB::transaction(function () use ($id, $validated) {
            $promote = (bool) ($validated['IsResponsible'] ?? false);
            $actor = (int) Auth::id();
            if ((int) $validated['UserID'] === $actor) {
                // The current manager is already a member. Keep the manager role on repeat submissions.
                return ['Success' => true, 'Message' => 'کاربر از قبل مسئول پروژه است.'];
            }
            $result = DB::select(
                'EXEC sp_AddProjectMember @ProjectID = ?, @UserID = ?, @IsResponsible = ?, @StartDate = ?, @CreateUser = ?',
                [
                    $id,
                    $validated['UserID'],
                    false,
                    $validated['StartDate'] ?? null,
                    Auth::id(),
                ]
            );

            $response = (array) ($result[0] ?? []);
            if (!empty($response['Success'])) {
                DB::statement('EXEC dbo.sp_SyncProjectDeadlineEvent @ProjectID = ?, @ActorUserID = ?', [$id, $actor]);
                if ($promote) {
                    $this->assertProjectTeamSaved(DB::selectOne(
                        'EXEC sp_AddProjectMember @ProjectID = ?, @UserID = ?, @IsResponsible = 1, @StartDate = NULL, @CreateUser = ?',
                        [$id, $validated['UserID'], $actor]
                    ));
                }
            }

            return $response;
        });
        return response()->json([
            'success' => !empty($response['Success']),
            'message' => $response['Message'] ?? '',
        ]);
    }

    /**
     * حذف عضو — فقط توسط مسئول یا سازنده
     */
    public function removeMember(Request $request, int $id)
    {
        if (!$this->canManage($id)) {
            return response()->json([
                'success' => false,
                'message' => 'فقط مسئول پروژه می‌تواند اعضا را مدیریت کند.',
            ], 403);
        }

        $validated = $request->validate([
            'UserID' => 'required|integer',
        ]);

        $result = DB::select(
            'EXEC sp_RemoveProjectMember @ProjectID = ?, @UserID = ?, @ModifyUser = ?',
            [$id, $validated['UserID'], Auth::id()]
        );

        $response = (array) ($result[0] ?? []);
        return response()->json([
            'success' => !empty($response['Success']),
            'message' => $response['Message'] ?? '',
        ]);
    }

    /**
     * لیست پیمانکارانِ یک پروژه (برایِ تبِ «پیمانکار») — خروجیِ JSON.
     * مشخصاتِ پیمانکار از CrmParties خوانده می‌شود؛ هیچ موجودیتِ مستقلی برایِ
     * پیمانکار وجود ندارد — فقط رابطهٔ ProjectContractors.
     */
    public function contractors(int $id)
    {
        $contractors = DB::select('EXEC sp_GetProjectContractors @ProjectID = ?', [$id]);

        return response()->json(['contractors' => $contractors]);
    }

    /**
     * جست‌وجویِ طرف‌حساب‌هایِ CRM برایِ انتخابِ پیمانکار — مسیرِ محدود و مخصوصِ همین
     * قابلیت (نه endpointِ عمومیِ CRM)، تا کاربری که فقط اجازهٔ مدیریتِ همین پروژه را
     * دارد (نه CRM_VIEW) هم بتواند طرف‌حساب جست‌وجو کند؛ خروجی فقط همان چند ستونِ
     * لازم برایِ Autocomplete است (sp_SearchCrmPartiesForContractor)، نه دسترسیِ کاملِ CRM.
     */
    public function searchContractorCandidates(Request $request, int $id)
    {
        if (!$this->canManage($id)) {
            return response()->json([
                'success' => false,
                'message' => 'فقط مسئول پروژه می‌تواند پیمانکار جست‌وجو کند.',
            ], 403);
        }

        $search = $request->input('search');

        $parties = DB::select('EXEC sp_SearchCrmPartiesForContractor @SearchText = ?', [$search ?: null]);

        // Searchِ خالی (بازشدنِ اولیهٔ Modal، قبل از تایپ) فقط ۵ طرف‌حسابِ اول را برای راهنماییِ
        // کاربر نشان می‌دهد؛ TOP 50 فقط برایِ جست‌وجویِ واقعی (با متن) اعمال می‌شود.
        if (!$search) {
            $parties = array_slice($parties, 0, 5);
        }

        return response()->json(['parties' => $parties]);
    }

    /**
     * افزودن/فعال‌سازیِ پیمانکار — فقط توسط مسئولِ پروژه (هم‌الگو با addMember)
     */
    public function addContractor(Request $request, int $id)
    {
        if (!$this->canManage($id)) {
            return response()->json([
                'success' => false,
                'message' => 'فقط مسئول پروژه می‌تواند پیمانکاران را مدیریت کند.',
            ], 403);
        }

        $validated = $request->validate([
            'PartyID' => 'required|integer',
        ]);

        $response = DB::transaction(function () use ($id, $validated) {
            $result = DB::select(
                'EXEC sp_AddProjectContractor @ProjectID = ?, @PartyID = ?, @CreateUser = ?',
                [$id, $validated['PartyID'], Auth::id()]
            );

            $response = (array) ($result[0] ?? []);
            if (!empty($response['Success'])) {
                DB::statement('EXEC dbo.sp_SyncProjectDeadlineEvent @ProjectID = ?, @ActorUserID = ?', [$id, Auth::id()]);
            }

            return $response;
        });
        return response()->json([
            'success' => !empty($response['Success']),
            'message' => $response['Message'] ?? '',
        ]);
    }

    /**
     * حذف (غیرفعال‌سازیِ نرم‌افزاری) پیمانکار — فقط توسط مسئولِ پروژه (هم‌الگو با removeMember)
     */
    public function removeContractor(Request $request, int $id)
    {
        if (!$this->canManage($id)) {
            return response()->json([
                'success' => false,
                'message' => 'فقط مسئول پروژه می‌تواند پیمانکاران را مدیریت کند.',
            ], 403);
        }

        $validated = $request->validate([
            'PartyID' => 'required|integer',
        ]);

        $result = DB::select(
            'EXEC sp_RemoveProjectContractor @ProjectID = ?, @PartyID = ?, @ModifyUser = ?',
            [$id, $validated['PartyID'], Auth::id()]
        );

        $response = (array) ($result[0] ?? []);
        return response()->json([
            'success' => !empty($response['Success']),
            'message' => $response['Message'] ?? '',
        ]);
    }

    /**
     * POST projects/{id}/contractors/{partyId}/interactions — ثبتِ تعاملِ CRM برایِ یک پیمانکارِ
     * همین Project، از مسیرِ Project→Contractor (نه مسیرِ عمومیِ CRM). Authorization بر اساسِ
     * canManage($id) است — نه CRM_MANAGE_PARTIES — تا مسئولِ پروژه بدونِ دسترسیِ عمومیِ CRM هم
     * بتواند تعاملِ همین پیمانکار را ثبت کند؛ کاملاً محدود به همین Project/Party.
     * PartyID و ProjectID همیشه از خودِ Route گرفته می‌شوند (هرگز از Body) تا کاربر نتواند با
     * دستکاریِ Request تعاملِ پیمانکارِ دیگری را به این Project وصل کند. اعتبارسنجیِ رابطهٔ
     * Party↔Project (ProjectContractors.IsActive=1) همان اعتبارسنجیِ Stage B است —
     * در sp_Crm_SaveInteraction، بدونِ تکرارِ منطق.
     */
    public function storeContractorInteraction(Request $request, int $id, int $partyId)
    {
        if (!$this->canManage($id)) {
            return response()->json([
                'success' => false,
                'message' => 'فقط مسئول پروژه می‌تواند تعاملِ این پیمانکار را ثبت کند.',
            ], 403);
        }

        $validated = $request->validate([
            'interactionTypeId' => 'required|integer|exists:CrmInteractionTypes,InteractionTypeID',
            'subject'           => 'nullable|string|max:200',
            'description'       => 'nullable|string',
            'outcome'           => 'nullable|string',
            'interactionDate'   => 'nullable|date',
            'status'            => 'nullable|string|max:20',
            'ownerUserId'       => 'nullable|integer|exists:Users,UserID',
        ]);

        $validated['partyId'] = $partyId;
        $validated['projectId'] = $id;

        // پیوست‌هایِ اختیاری در همان درخواستِ multipart (مجوز = مسئولِ پروژه، نه CRM_MANAGE_PARTIES)
        $files = $request->file('attachments');
        $descriptions = $request->input('descriptions', []);
        $hasFiles = is_array($files) && $files !== [];

        try {
            if ($request->has('attachments') && ! $hasFiles) {
                throw new CrmValidationException('هیچ فایلی برایِ پیوست انتخاب نشده است.');
            }
            if ($hasFiles) {
                if (! is_array($descriptions)) {
                    throw new CrmValidationException('توضیحاتِ پیوست نامعتبر است.');
                }
                // پیش از ثبتِ تعامل — فایلِ نامعتبر یعنی هیچ چیز ثبت نشود
                $this->crmParties->validateInteractionAttachments($files, $descriptions);
            }

            $res = $this->crmParties->saveInteraction($validated, Auth::id());
            $interactionId = (int) $res->InteractionID;

            // متنِ موفقیتِ این مسیر عمداً ثابت است (نه پیامِ مشترکِ SP) — طبقِ درخواستِ کاربر، فقط برایِ
            // ثبتِ تعامل از Project→Contractor، مستقل از پیامِ عمومیِ sp_Crm_SaveInteraction.
            $message = 'تعامل با موفقیت ثبت شد.';
            if ($hasFiles) {
                try {
                    $this->crmParties->addInteractionAttachments($interactionId, $files, $descriptions, (int) Auth::id());
                } catch (\Throwable $e) {
                    report($e);
                    $message = 'تعامل ثبت شد، ولی ذخیرهٔ پیوست‌ها ناموفق بود؛ از صفحهٔ طرف‌حساب دوباره پیوست کنید.';
                }
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'interactionId' => $interactionId,
            ]);
        } catch (CrmException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * بررسی دسترسی مدیریت اعضا — فقط مسئول فعال پروژه (نه لزوماً سازنده)
     */
    private function canManage(int $projectId): bool
    {
        $userId = Auth::id();
        if (!$userId) {
            return false;
        }

        $responsible = DB::select(
            'SELECT 1 FROM dbo.ProjectMembers WHERE ProjectID = ? AND UserID = ? AND IsResponsible = 1 AND IsActive = 1',
            [$projectId, $userId]
        );

        return !empty($responsible);
    }
}
