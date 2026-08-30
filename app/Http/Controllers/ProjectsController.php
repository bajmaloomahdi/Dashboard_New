<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ProjectsController extends Controller
{
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

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
            'users'    => $users,
            'statuses' => $statuses,
            'filters'  => [
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

        return Inertia::render('Projects/Show', [
            'project' => $project,
            'members' => $members,
        ]);
    }

    /**
     * ایجاد پروژه (همراه با مسئول و اعضا)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'ProjectCode'      => 'required|string|max:50',
            'ProjectTitle'     => 'required|string|max:250',
            'Description'      => 'nullable|string',
            'StartDate'        => 'nullable|date',
            'PlannedEndDate'   => 'nullable|date',
            'ActualEndDate'    => 'nullable|date',
            'ProjectStatusID'  => 'required|integer',
            'ProgressPercent'  => 'nullable|numeric|min:0|max:100',
            'IsActive'         => 'boolean',
            'ResponsibleUserID' => 'required|integer',
            'MemberUserIDs'    => 'nullable|array',
            'MemberUserIDs.*'  => 'integer',
        ]);

        $result = DB::select(
            'EXEC sp_InsertProject
                @ProjectCode = ?, @ProjectTitle = ?, @Description = ?, @StartDate = ?,
                @PlannedEndDate = ?, @ActualEndDate = ?, @ProjectStatusID = ?,
                @ProgressPercent = ?, @IsActive = ?, @ResponsibleUserID = ?, @MemberUserIDs = ?, @CreateUser = ?',
            [
                $validated['ProjectCode'],
                $validated['ProjectTitle'],
                $validated['Description'] ?? null,
                $validated['StartDate'] ?? null,
                $validated['PlannedEndDate'] ?? null,
                $validated['ActualEndDate'] ?? null,
                $validated['ProjectStatusID'],
                $validated['ProgressPercent'] ?? 0,
                $validated['IsActive'] ?? true,
                $validated['ResponsibleUserID'],
                isset($validated['MemberUserIDs']) ? implode(',', $validated['MemberUserIDs']) : null,
                Auth::id(),
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
            'ProgressPercent'  => 'nullable|numeric|min:0|max:100',
            'IsActive'         => 'boolean',
        ]);

        $result = DB::select(
            'EXEC sp_UpdateProject
                @ProjectID = ?, @ProjectCode = ?, @ProjectTitle = ?, @Description = ?,
                @StartDate = ?, @PlannedEndDate = ?, @ActualEndDate = ?, @ProjectStatusID = ?,
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
