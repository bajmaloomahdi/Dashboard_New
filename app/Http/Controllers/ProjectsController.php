<?php

namespace App\Http\Controllers;

use App\Services\Crm\CrmPartyService;
use App\Services\Crm\Exceptions\CrmException;
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

        $users = DB::select('EXEC sp_GetUsers @SearchText = NULL, @IsActive = 1');
        $this->attachPositionTitles($users);

        $statuses = DB::select('EXEC sp_GetProjectStatuses @IsActive = NULL');
        $priorities = DB::select('EXEC sp_GetProjectPriorities @IsActive = NULL');

        return Inertia::render('Projects/Index', [
            'projects'   => $projects,
            'users'      => $users,
            'statuses'   => $statuses,
            'priorities' => $priorities,
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
            'members'          => $members,
            'users'            => $users,
            'msgPriorities'    => $msgPriorities,
            'contractors'      => $contractors,
            'interactionTypes' => $interactionTypes,
        ]);
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
        return response()->json([
            'success' => !empty($response['Success']),
            'message' => $response['Message'] ?? '',
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
        ]);

        $result = DB::select(
            'EXEC sp_InsertProject
                @ProjectCode = ?, @ProjectTitle = ?, @Description = ?, @StartDate = ?,
                @PlannedEndDate = ?, @ActualEndDate = ?, @ProjectStatusID = ?, @ProjectPriorityID = ?,
                @ProgressPercent = ?, @IsActive = ?, @ResponsibleUserID = ?, @MemberUserIDs = ?, @CreateUser = ?, @Year = ?',
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
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'ProjectTitle' => $response['Message'] ?? 'خطا در ایجاد پروژه',
            ]);
        }

        return redirect()->route('projects.index')
            ->with('success', $response['Message'] ?? 'پروژه با موفقیت ایجاد شد.');
    }

    /**
     * ویرایش فیلدهای پروژه (بدون دست‌زدن به اعضا)
     */
    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'ProjectCode'      => 'required|string|max:50',
            'ProjectTitle'     => 'required|string|max:250',
            'Description'      => 'nullable|string',
            'StartDate'        => 'nullable|date',
            'PlannedEndDate'   => 'nullable|date',
            'ActualEndDate'    => 'nullable|date',
            'ProjectStatusID'  => 'required|integer',
            'ProjectPriorityID' => 'nullable|integer',
            'ProgressPercent'  => 'nullable|numeric|min:0|max:100',
            'IsActive'         => 'boolean',
        ]);

        $result = DB::select(
            'EXEC sp_UpdateProject
                @ProjectID = ?, @ProjectCode = ?, @ProjectTitle = ?, @Description = ?,
                @StartDate = ?, @PlannedEndDate = ?, @ActualEndDate = ?, @ProjectStatusID = ?, @ProjectPriorityID = ?,
                @ProgressPercent = ?, @IsActive = ?, @ModifyUser = ?',
            [
                $id,
                $validated['ProjectCode'],
                $validated['ProjectTitle'],
                $validated['Description'] ?? null,
                $validated['StartDate'] ?? null,
                $validated['PlannedEndDate'] ?? null,
                $validated['ActualEndDate'] ?? null,
                $validated['ProjectStatusID'],
                $validated['ProjectPriorityID'] ?? null,
                $validated['ProgressPercent'] ?? 0,
                $validated['IsActive'] ?? true,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'ProjectTitle' => $response['Message'] ?? 'خطا در ویرایش پروژه',
            ]);
        }

        return redirect()->route('projects.index')
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

        return response()->json(['members' => $members]);
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

        $result = DB::select(
            'EXEC sp_AddProjectMember @ProjectID = ?, @UserID = ?, @IsResponsible = ?, @StartDate = ?, @CreateUser = ?',
            [
                $id,
                $validated['UserID'],
                $validated['IsResponsible'] ?? false,
                $validated['StartDate'] ?? null,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);
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

        $result = DB::select(
            'EXEC sp_AddProjectContractor @ProjectID = ?, @PartyID = ?, @CreateUser = ?',
            [$id, $validated['PartyID'], Auth::id()]
        );

        $response = (array) ($result[0] ?? []);
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

        try {
            $res = $this->crmParties->saveInteraction($validated, Auth::id());

            // متنِ موفقیتِ این مسیر عمداً ثابت است (نه پیامِ مشترکِ SP) — طبقِ درخواستِ کاربر، فقط برایِ
            // ثبتِ تعامل از Project→Contractor، مستقل از پیامِ عمومیِ sp_Crm_SaveInteraction.
            return response()->json([
                'success' => true,
                'message' => 'تعامل با موفقیت ثبت شد.',
                'interactionId' => (int) $res->InteractionID,
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