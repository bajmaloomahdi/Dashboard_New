<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\MasterParameterController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MessageStatusController;
use App\Http\Controllers\MessageTemplateController;
use App\Http\Controllers\MessageTypeController;
use App\Http\Controllers\OrganizationalUnitController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportManagementController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserPositionController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\Crm\CrmAddressController;
use App\Http\Controllers\Crm\CrmBrandController;
use App\Http\Controllers\Crm\CrmClassificationController;
use App\Http\Controllers\Crm\CrmContactController;
use App\Http\Controllers\Crm\CrmDirectoryController;
use App\Http\Controllers\Crm\CrmGeographyController;
use App\Http\Controllers\Crm\CrmPartyController;
use App\Http\Controllers\Crm\CrmPersonController;
use App\Http\Controllers\MsgPriorityController;
use App\Http\Controllers\ProjectsController;
use App\Http\Controllers\Process\ProcessDefinitionController;
use App\Http\Controllers\Process\ProcessEntityTypeController;
use App\Http\Controllers\Process\ProcessInstanceController;
use App\Http\Controllers\Process\ProcessLetterTemplateController;
use App\Http\Controllers\Process\ProcessTemplateParameterController;
use App\Http\Controllers\Process\ProcessVersionController;
use App\Http\Controllers\Workflow\WorkflowCategoryController;
use App\Http\Controllers\Workflow\WorkflowConditionFieldController;
use App\Http\Controllers\Workflow\WorkflowDefinitionController;
use App\Http\Controllers\Workflow\WorkflowEntityTypeController;
use App\Http\Controllers\Workflow\WorkflowLetterTemplateController;
use App\Http\Controllers\Workflow\WorkflowRuntimeController;
use App\Http\Controllers\Workflow\WorkflowTemplateParameterController;
use App\Http\Controllers\Workflow\WorkflowTaskController;
use App\Http\Controllers\Workflow\WorkflowVersionController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// --- مسیرهای مهمان (بدون نیاز به لاگین) ---
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

// --- مسیرهای احراز هویت‌شده ---
Route::middleware('auth')->group(function () {

 
    // داشبورد
    Route::get('/dashboard', function () {
        $userId = Auth::id();

        $result = DB::select('EXEC sp_GetUnreadNotificationsCount @UserID = ?', [$userId]);
        $unreadCount = (int) ($result[0]->UnreadCount ?? 0);

        $homeTabs     = DB::select('EXEC sp_GetUserHomeTabs @UserID = ?', [$userId]);
        $homeTabItems = DB::select('EXEC sp_GetUserHomeTabItems @UserID = ?', [$userId]);
        $messageStats = DB::select('EXEC sp_GetUserMessagePriorityStats @UserID = ?', [$userId]);

        // لیست کامل اولویت‌های فعال (همان SP صفحه‌ی «نامه‌ها») تا کارت‌های اولویت در
        // داشبورد همیشه نمایش داده شوند، حتی وقتی هیچ پیامی برای کاربر نیست.
        $priorities   = DB::select('EXEC sp_GetMsgPriorities @SearchText = ?, @IsActive = ?', [null, 1]);

        // آیا کاربر دسترسی مشاهده‌ی تقویم را دارد؟ (کارت «رویدادهای امروز من» در داشبورد)
        $calPerm = DB::select('EXEC sp_CheckUserPermission @UserID = ?, @PermissionCode = ?', [$userId, 'CALENDAR_VIEW']);
        $canViewCalendar = (bool) ($calPerm[0]->HasPermission ?? 0);

        return Inertia::render('Dashboard', [
            'unreadCount'          => $unreadCount,
            'homeTabs'             => $homeTabs,
            'homeTabItems'         => $homeTabItems,
            'messagePriorityStats' => $messageStats,
            'priorities'           => $priorities,
            'canViewCalendar'      => $canViewCalendar,
        ]);
    })->name('dashboard');

    // کاربران
    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::put('/{id}', [UserController::class, 'update'])->name('update');
        Route::post('/{id}/toggle', [UserController::class, 'toggleActive'])->name('toggle');
        Route::get('/{id}/roles', [UserController::class, 'roles'])->name('roles');
        Route::post('/{id}/roles', [UserController::class, 'saveRoles'])->name('save-roles');
        Route::post('/{id}/reset-password', [UserController::class, 'resetPassword'])->name('reset-password');
    });

    // سمت‌های کاربران
    Route::get('users/{id}/positions', [UserPositionController::class, 'index'])->name('users.positions.index');
    Route::post('users/{id}/positions', [UserPositionController::class, 'store'])->name('users.positions.store');

    // نقش‌ها
    Route::prefix('roles')->name('roles.')->group(function () {
        Route::get('/', [RoleController::class, 'index'])->name('index');
        Route::post('/', [RoleController::class, 'store'])->name('store');
        Route::put('/{id}', [RoleController::class, 'update'])->name('update');
        Route::post('/{id}/toggle', [RoleController::class, 'toggleActive'])->name('toggle');
        Route::get('/{id}/permissions', [RoleController::class, 'permissions'])->name('permissions');
        Route::post('/{id}/menus', [RoleController::class, 'saveMenus'])->name('save-menus');
        Route::post('/{id}/reports', [RoleController::class, 'saveReports'])->name('save-reports');
        Route::post('/{id}/permissions', [RoleController::class, 'savePermissions'])->name('save-permissions');
    });

    // منوها
    Route::prefix('menus')->name('menus.')->group(function () {
        Route::get('/', [MenuController::class, 'index'])->name('index');
        Route::get('/parent-options', [MenuController::class, 'parentOptions'])->name('parent-options');
        Route::post('/', [MenuController::class, 'store'])->name('store');
        Route::put('/{id}', [MenuController::class, 'update'])->name('update');
        Route::post('/{id}/toggle', [MenuController::class, 'toggleActive'])->name('toggle');
    });

    // پارامترهای پایه
    Route::prefix('master-parameters')->name('master-parameters.')->group(function () {
        Route::get('/', [MasterParameterController::class, 'index'])->name('index');
        Route::post('/', [MasterParameterController::class, 'store'])->name('store');
        Route::put('/{id}', [MasterParameterController::class, 'update'])->name('update');
        Route::post('/{id}/toggle', [MasterParameterController::class, 'toggleActive'])->name('toggle');
    });

    // مدیریت گزارش‌ها
    Route::prefix('reports-manage')->name('reports-manage.')->group(function () {
        Route::get('/', [ReportManagementController::class, 'index'])->name('index');
        Route::post('/', [ReportManagementController::class, 'store'])->name('store');
        Route::put('/{id}', [ReportManagementController::class, 'update'])->name('update');
        Route::post('/{id}/toggle', [ReportManagementController::class, 'toggleActive'])->name('toggle');
        Route::get('/{id}/parameters', [ReportManagementController::class, 'parameters'])->name('parameters');
        Route::post('/{id}/parameters', [ReportManagementController::class, 'saveParameters'])->name('parameters.save');
        Route::put('/{id}/parameters/{paramId}', [ReportManagementController::class, 'updateParameterSettings'])->name('parameters.settings');
    });

    // گزارش‌ها
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/lookup', [ReportController::class, 'lookup'])->name('lookup');
        Route::get('/{code}', [ReportController::class, 'show'])->name('show');
        Route::post('/{code}/execute', [ReportController::class, 'execute'])->name('execute');
    });

    // واحدهای سازمانی
    Route::get('organizational-units', [OrganizationalUnitController::class, 'index'])->name('organizational-units.index');
    Route::post('organizational-units', [OrganizationalUnitController::class, 'store'])->name('organizational-units.store');
    Route::put('organizational-units/{unit}', [OrganizationalUnitController::class, 'update'])->name('organizational-units.update');
    Route::post('organizational-units/{unit}/toggle', [OrganizationalUnitController::class, 'toggleActive'])->name('organizational-units.toggle');

    // سمت‌ها
    Route::controller(PositionController::class)->group(function () {
        Route::get('positions', 'index')->name('positions.index');
        Route::post('positions', 'store')->name('positions.store');
        Route::put('positions/{position}', 'update')->name('positions.update');
        Route::post('positions/{position}/toggle', 'toggleActive')->name('positions.toggle');
    });

    // انواع پیام
    Route::get('message-types', [MessageTypeController::class, 'index'])->name('message-types.index');
    Route::post('message-types', [MessageTypeController::class, 'store'])->name('message-types.store');
    Route::put('message-types/{id}', [MessageTypeController::class, 'update'])->name('message-types.update');
    Route::post('message-types/{id}/toggle', [MessageTypeController::class, 'toggleActive'])->name('message-types.toggle');

    // وضعیت‌های پیام
    Route::get('message-statuses', [MessageStatusController::class, 'index'])->name('message-statuses.index');
    Route::post('message-statuses', [MessageStatusController::class, 'store'])->name('message-statuses.store');
    Route::put('message-statuses/{id}', [MessageStatusController::class, 'update'])->name('message-statuses.update');
    Route::post('message-statuses/{id}/toggle', [MessageStatusController::class, 'toggleActive'])->name('message-statuses.toggle');

    // پیام‌ها
    Route::get('messages', [MessageController::class, 'index'])->name('messages.index');
    Route::get('messages/sent', [MessageController::class, 'index'])->name('messages.sent')->defaults('mode', 'sent');
    Route::get('messages/create', [MessageController::class, 'create'])->name('messages.create');
    Route::get('messages/archive', [MessageController::class, 'archive'])->name('messages.archive');
    Route::post('messages', [MessageController::class, 'store'])->name('messages.store');
    Route::post('messages/from-template', [MessageTemplateController::class, 'store'])->name('messages.from-template');
    Route::get('messages/{id}', [MessageController::class, 'show'])->name('messages.show');
    Route::post('messages/{id}/status', [MessageController::class, 'changeStatus'])->name('messages.change-status');
    Route::post('messages/{id}/forward', [MessageController::class, 'forward'])->name('messages.forward');
    Route::post('messages/{id}/comment', [MessageController::class, 'addComment'])->name('messages.comment');
    Route::post('messages/{id}/copy', [MessageController::class, 'addCopy'])->name('messages.copy');
    Route::get('messages/{id}/attachments/{attachmentId}', [MessageController::class, 'downloadAttachment'])->name('messages.attachments.show');
    Route::get('messages/{id}/comments/{commentId}/attachments/{attachmentId}', [MessageController::class, 'downloadCommentAttachment'])->name('messages.comment-attachments.show');


    // اولویت‌های پیام
    Route::get('msg-priorities', [MsgPriorityController::class, 'index'])->name('msg-priorities.index');
    Route::post('msg-priorities', [MsgPriorityController::class, 'store'])->name('msg-priorities.store');
    Route::put('msg-priorities/{id}', [MsgPriorityController::class, 'update'])->name('msg-priorities.update');
    Route::post('msg-priorities/{id}/toggle', [MsgPriorityController::class, 'toggleActive'])->name('msg-priorities.toggle');


    // ───────────────────────── پروژه‌ها ─────────────────────────
    Route::get('/projects', [ProjectsController::class, 'index'])->name('projects.index');
    Route::post('/projects', [ProjectsController::class, 'store'])->name('projects.store');
    Route::get('/projects/{id}', [ProjectsController::class, 'show'])->name('projects.show');
    Route::put('/projects/{id}', [ProjectsController::class, 'update'])->name('projects.update');
    Route::post('/projects/{id}/toggle', [ProjectsController::class, 'toggleActive'])->name('projects.toggle-active');
    Route::get('/projects/{id}/members', [ProjectsController::class, 'members'])->name('projects.members');
    Route::post('/projects/{id}/members', [ProjectsController::class, 'addMember'])->name('projects.members.add');
    Route::delete('/projects/{id}/members', [ProjectsController::class, 'removeMember'])->name('projects.members.remove');
    Route::post('/projects/{id}/tasks', [ProjectsController::class, 'createTask'])->name('projects.tasks.store');
    Route::get('/projects/{id}/tasks', [ProjectsController::class, 'tasks'])->name('projects.tasks.index');
    Route::get('/projects/{id}/comments', [ProjectsController::class, 'comments'])->name('projects.comments.index');
    Route::post('/projects/{id}/comments', [ProjectsController::class, 'addComment'])->name('projects.comments.store');
    Route::get('/projects/{id}/attachments/{attachmentId}', [ProjectsController::class, 'downloadAttachment'])->name('projects.attachments.show');


    // ───────────────────────── تقویم و یادآوری‌ها ─────────────────────────
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
    Route::get('/calendar/events', [CalendarController::class, 'events'])->name('calendar.events');

    // یادآوری‌ها (قبل از مسیرهای {id} رویداد تا تداخل نداشته باشند)
    Route::get('/calendar/reminders/due', [CalendarController::class, 'dueReminders'])->name('calendar.reminders.due');
    Route::get('/calendar/reminders', [CalendarController::class, 'myReminders'])->name('calendar.reminders.index');
    Route::post('/calendar/reminders/standalone', [CalendarController::class, 'storeStandaloneReminder'])->name('calendar.reminders.standalone.store');
    Route::put('/calendar/reminders/standalone/{id}', [CalendarController::class, 'updateStandaloneReminder'])->name('calendar.reminders.standalone.update');
    Route::post('/calendar/reminders/{id}/seen', [CalendarController::class, 'markReminderSeen'])->name('calendar.reminders.seen');
    Route::delete('/calendar/reminders/{id}', [CalendarController::class, 'destroyReminder'])->name('calendar.reminders.destroy');

    // رویدادها
    Route::get('/calendar/events/{id}', [CalendarController::class, 'show'])->name('calendar.events.show');
    Route::post('/calendar/events', [CalendarController::class, 'store'])->name('calendar.events.store');
    Route::put('/calendar/events/{id}', [CalendarController::class, 'update'])->name('calendar.events.update');
    Route::delete('/calendar/events/{id}', [CalendarController::class, 'destroy'])->name('calendar.events.destroy');
    Route::post('/calendar/events/{id}/respond', [CalendarController::class, 'respond'])->name('calendar.events.respond');
    Route::post('/calendar/events/{id}/reminder', [CalendarController::class, 'saveMyEventReminder'])->name('calendar.events.reminder');

    // ───────────────────────── موتورِ فرایند (Workflow API) ─────────────────────────
    Route::prefix('workflow')->name('workflow.')->group(function () {

        // --- تعریفِ فرایندها ---
        Route::get('definitions', [WorkflowDefinitionController::class, 'index'])->name('definitions.index');
        Route::post('definitions', [WorkflowDefinitionController::class, 'store'])->name('definitions.store');
        Route::get('definitions/{definitionId}', [WorkflowDefinitionController::class, 'show'])
            ->whereNumber('definitionId')->name('definitions.show');
        Route::post('definitions/{definitionId}/toggle', [WorkflowDefinitionController::class, 'toggleActive'])
            ->whereNumber('definitionId')->name('definitions.toggle');
        Route::post('definitions/{definitionId}/versions', [WorkflowVersionController::class, 'createDraft'])
            ->whereNumber('definitionId')->name('versions.draft');
        Route::get('definitions/{definitionId}/templates', [WorkflowLetterTemplateController::class, 'forDefinition'])
            ->whereNumber('definitionId')->name('definitions.templates');
        Route::get('definitions/{definitionId}/preview-assignees', [WorkflowRuntimeController::class, 'previewAssignees'])
            ->whereNumber('definitionId')->name('definitions.preview-assignees');

        // --- دسته‌بندیِ فرایندها ---
        Route::get('categories', [WorkflowCategoryController::class, 'index'])->name('categories.index');
        Route::post('categories', [WorkflowCategoryController::class, 'store'])->name('categories.store');
        Route::post('categories/{categoryId}/toggle', [WorkflowCategoryController::class, 'toggleActive'])
            ->whereNumber('categoryId')->name('categories.toggle');

        // --- Registryِ نوعِ موجودیت‌ها (WorkflowEntityTypes) ---
        Route::get('entity-types', [WorkflowEntityTypeController::class, 'index'])->name('entity-types.index');
        Route::post('entity-types', [WorkflowEntityTypeController::class, 'store'])->name('entity-types.store');
        Route::post('entity-types/{entityTypeId}/toggle', [WorkflowEntityTypeController::class, 'toggleActive'])
            ->whereNumber('entityTypeId')->name('entity-types.toggle');

        // --- Registryِ پارامترهایِ Template (TemplateParameters) — مستقل از Condition Engine ---
        Route::get('template-parameters', [WorkflowTemplateParameterController::class, 'index'])->name('template-parameters.index');
        Route::post('template-parameters', [WorkflowTemplateParameterController::class, 'store'])->name('template-parameters.store');
        Route::post('template-parameters/{templateParameterId}/toggle', [WorkflowTemplateParameterController::class, 'toggleActive'])
            ->whereNumber('templateParameterId')->name('template-parameters.toggle');

        // --- Registryِ قالب‌هایِ نامه (LetterTemplates) — مستقل از Condition Engine ---
        Route::get('templates', [WorkflowLetterTemplateController::class, 'index'])->name('templates.index');
        Route::post('templates', [WorkflowLetterTemplateController::class, 'store'])->name('templates.store');
        Route::post('templates/{letterTemplateId}/toggle', [WorkflowLetterTemplateController::class, 'toggleActive'])
            ->whereNumber('letterTemplateId')->name('templates.toggle');
        Route::post('templates/{letterTemplateId}/render', [WorkflowLetterTemplateController::class, 'render'])
            ->whereNumber('letterTemplateId')->name('templates.render');

        // --- فیلدهایِ شرط (Condition Engine — Definition-level) ---
        Route::get('definitions/{definitionId}/condition-fields', [WorkflowConditionFieldController::class, 'index'])
            ->whereNumber('definitionId')->name('condition-fields.index');
        Route::post('definitions/{definitionId}/condition-fields', [WorkflowConditionFieldController::class, 'store'])
            ->whereNumber('definitionId')->name('condition-fields.store');
        Route::post('condition-fields/{fieldId}/toggle', [WorkflowConditionFieldController::class, 'toggleActive'])
            ->whereNumber('fieldId')->name('condition-fields.toggle');

        // --- نسخه‌ها ---
        Route::prefix('versions/{versionId}')->whereNumber('versionId')->name('versions.')->group(function () {
            Route::get('/', [WorkflowVersionController::class, 'show'])->name('show');
            Route::get('graph', [WorkflowVersionController::class, 'graph'])->name('graph');
            Route::get('steps', [WorkflowVersionController::class, 'steps'])->name('steps');
            Route::get('actions', [WorkflowVersionController::class, 'actions'])->name('actions');
            Route::get('assignments', [WorkflowVersionController::class, 'assignments'])->name('assignments');
            Route::get('transitions', [WorkflowVersionController::class, 'transitions'])->name('transitions');
            Route::put('graph', [WorkflowVersionController::class, 'saveGraph'])->name('graph.save');
            Route::post('validate', [WorkflowVersionController::class, 'validateGraph'])->name('validate');
            Route::post('clone', [WorkflowVersionController::class, 'clone'])->name('clone');
            Route::post('publish', [WorkflowVersionController::class, 'publish'])->name('publish');
        });

        // --- زمانِ اجرا ---
        Route::post('instances', [WorkflowRuntimeController::class, 'start'])->name('instances.start');
        Route::get('instances/{instanceId}', [WorkflowRuntimeController::class, 'show'])
            ->whereNumber('instanceId')->name('instances.show');
        Route::get('instances/{instanceId}/history', [WorkflowRuntimeController::class, 'history'])
            ->whereNumber('instanceId')->name('instances.history');

        // --- چرخهٔ حیاتِ Instance ---
        Route::post('instances/{instanceId}/cancel', [WorkflowRuntimeController::class, 'cancel'])
            ->whereNumber('instanceId')->name('instances.cancel');
        Route::post('instances/{instanceId}/suspend', [WorkflowRuntimeController::class, 'suspend'])
            ->whereNumber('instanceId')->name('instances.suspend');
        Route::post('instances/{instanceId}/resume', [WorkflowRuntimeController::class, 'resume'])
            ->whereNumber('instanceId')->name('instances.resume');

        // --- تسکِ مرحله (بر مبنای MessageID) — کارتابل از /messages می‌آید، اینجا فقط نمای workflow ---
        Route::get('messages/{messageId}', [WorkflowTaskController::class, 'show'])
            ->whereNumber('messageId')->name('messages.show');
        Route::post('messages/{messageId}/actions', [WorkflowTaskController::class, 'act'])
            ->whereNumber('messageId')->name('messages.act');
        Route::post('messages/{messageId}/forward', [WorkflowTaskController::class, 'forward'])
            ->whereNumber('messageId')->name('messages.forward');
        Route::post('messages/{messageId}/delegate', [WorkflowTaskController::class, 'delegate'])
            ->whereNumber('messageId')->name('messages.delegate');
        Route::post('messages/{messageId}/revoke-delegation', [WorkflowTaskController::class, 'revokeDelegation'])
            ->whereNumber('messageId')->name('messages.revoke-delegation');
    });

    // ───────────────────────── موتورِ فرایند (صفحاتِ Inertia) ─────────────────────────
    // عمداً پیشوندی جدا از workflow/* دارد؛ آن مسیر همیشه JSON است (shouldRenderJsonWhen
    // در bootstrap/app.php) و برایِ رندرِ صفحه مناسب نیست. اکشن‌ها (Cancel/Suspend/Resume/…)
    // همچنان از سمتِ کلاینت مستقیماً به همان /workflow/* می‌روند.
    Route::prefix('process')->name('process.')->group(function () {
        Route::get('definitions', [ProcessDefinitionController::class, 'index'])->name('definitions.index');
        Route::get('definitions/{definitionId}/open', [ProcessDefinitionController::class, 'open'])
            ->whereNumber('definitionId')->name('definitions.open');
        Route::get('definitions/{definitionId}', [ProcessDefinitionController::class, 'show'])
            ->whereNumber('definitionId')->name('definitions.show');
        Route::get('instances', [ProcessInstanceController::class, 'index'])->name('instances.index');
        Route::get('instances/{instanceId}', [ProcessInstanceController::class, 'show'])
            ->whereNumber('instanceId')->name('instances.show');
        Route::get('versions/{versionId}', [ProcessVersionController::class, 'show'])
            ->whereNumber('versionId')->name('versions.show');
        Route::get('entity-types', [ProcessEntityTypeController::class, 'index'])->name('entity-types.index');
        Route::get('template-parameters', [ProcessTemplateParameterController::class, 'index'])->name('template-parameters.index');
        Route::get('templates', [ProcessLetterTemplateController::class, 'index'])->name('templates.index');
    });

    // ───────────────────────── CRM — Master Data (فازِ ۱) ─────────────────────────
    Route::prefix('crm')->name('crm.')->group(function () {
        Route::get('classification', [CrmClassificationController::class, 'page'])->name('classification.page');
        Route::get('classification/departments', [CrmClassificationController::class, 'departmentsIndex'])->name('classification.departments.index');
        Route::post('classification/departments', [CrmClassificationController::class, 'departmentsStore'])->name('classification.departments.store');
        Route::post('classification/departments/{departmentId}/toggle', [CrmClassificationController::class, 'departmentsToggle'])
            ->whereNumber('departmentId')->name('classification.departments.toggle');
        Route::get('classification/party-types', [CrmClassificationController::class, 'partyTypesIndex'])->name('classification.party-types.index');
        Route::post('classification/party-types', [CrmClassificationController::class, 'partyTypesStore'])->name('classification.party-types.store');
        Route::post('classification/party-types/{partyTypeId}/toggle', [CrmClassificationController::class, 'partyTypesToggle'])
            ->whereNumber('partyTypeId')->name('classification.party-types.toggle');
        Route::get('classification/activities', [CrmClassificationController::class, 'activitiesIndex'])->name('classification.activities.index');
        Route::post('classification/activities', [CrmClassificationController::class, 'activitiesStore'])->name('classification.activities.store');
        Route::post('classification/activities/{activityId}/toggle', [CrmClassificationController::class, 'activitiesToggle'])
            ->whereNumber('activityId')->name('classification.activities.toggle');

        Route::get('geography', [CrmGeographyController::class, 'page'])->name('geography.page');
        Route::get('geography/provinces', [CrmGeographyController::class, 'provincesIndex'])->name('geography.provinces.index');
        Route::post('geography/provinces', [CrmGeographyController::class, 'provincesStore'])->name('geography.provinces.store');
        Route::post('geography/provinces/{provinceId}/toggle', [CrmGeographyController::class, 'provincesToggle'])
            ->whereNumber('provinceId')->name('geography.provinces.toggle');
        Route::get('geography/cities', [CrmGeographyController::class, 'citiesIndex'])->name('geography.cities.index');
        Route::post('geography/cities', [CrmGeographyController::class, 'citiesStore'])->name('geography.cities.store');
        Route::post('geography/cities/{cityId}/toggle', [CrmGeographyController::class, 'citiesToggle'])
            ->whereNumber('cityId')->name('geography.cities.toggle');
        Route::get('geography/counties', [CrmGeographyController::class, 'countiesIndex'])->name('geography.counties.index');
        Route::post('geography/counties', [CrmGeographyController::class, 'countiesStore'])->name('geography.counties.store');
        Route::post('geography/counties/{countyId}/toggle', [CrmGeographyController::class, 'countiesToggle'])
            ->whereNumber('countyId')->name('geography.counties.toggle');
        Route::get('geography/municipal-zones', [CrmGeographyController::class, 'municipalZonesIndex'])->name('geography.municipal-zones.index');
        Route::post('geography/municipal-zones', [CrmGeographyController::class, 'municipalZonesStore'])->name('geography.municipal-zones.store');
        Route::post('geography/municipal-zones/{municipalZoneId}/toggle', [CrmGeographyController::class, 'municipalZonesToggle'])
            ->whereNumber('municipalZoneId')->name('geography.municipal-zones.toggle');

        Route::get('directory', [CrmDirectoryController::class, 'page'])->name('directory.page');
        Route::get('directory/titles', [CrmDirectoryController::class, 'titlesIndex'])->name('directory.titles.index');
        Route::post('directory/titles', [CrmDirectoryController::class, 'titlesStore'])->name('directory.titles.store');
        Route::post('directory/titles/{titleId}/toggle', [CrmDirectoryController::class, 'titlesToggle'])
            ->whereNumber('titleId')->name('directory.titles.toggle');
        Route::get('directory/positions', [CrmDirectoryController::class, 'positionsIndex'])->name('directory.positions.index');
        Route::post('directory/positions', [CrmDirectoryController::class, 'positionsStore'])->name('directory.positions.store');
        Route::post('directory/positions/{positionId}/toggle', [CrmDirectoryController::class, 'positionsToggle'])
            ->whereNumber('positionId')->name('directory.positions.toggle');
        Route::get('directory/contact-roles', [CrmDirectoryController::class, 'contactRolesIndex'])->name('directory.contact-roles.index');
        Route::post('directory/contact-roles', [CrmDirectoryController::class, 'contactRolesStore'])->name('directory.contact-roles.store');
        Route::post('directory/contact-roles/{contactRoleId}/toggle', [CrmDirectoryController::class, 'contactRolesToggle'])
            ->whereNumber('contactRoleId')->name('directory.contact-roles.toggle');
        Route::get('directory/contact-types', [CrmDirectoryController::class, 'contactTypesIndex'])->name('directory.contact-types.index');
        Route::post('directory/contact-types', [CrmDirectoryController::class, 'contactTypesStore'])->name('directory.contact-types.store');
        Route::post('directory/contact-types/{contactTypeId}/toggle', [CrmDirectoryController::class, 'contactTypesToggle'])
            ->whereNumber('contactTypeId')->name('directory.contact-types.toggle');
        Route::get('directory/address-titles', [CrmDirectoryController::class, 'addressTitlesIndex'])->name('directory.address-titles.index');
        Route::post('directory/address-titles', [CrmDirectoryController::class, 'addressTitlesStore'])->name('directory.address-titles.store');
        Route::post('directory/address-titles/{addressTitleId}/toggle', [CrmDirectoryController::class, 'addressTitlesToggle'])
            ->whereNumber('addressTitleId')->name('directory.address-titles.toggle');

        // ───────────────── فازِ ۲: طرف‌حساب و موجودیت‌هایِ وابسته ─────────────────
        Route::get('parties', [CrmPartyController::class, 'page'])->name('parties.page');
        Route::get('parties-list', [CrmPartyController::class, 'index'])->name('parties.index');
        Route::get('parties/{partyId}', [CrmPartyController::class, 'show'])->whereNumber('partyId')->name('parties.show');
        Route::post('parties', [CrmPartyController::class, 'store'])->name('parties.store');
        Route::post('parties/{partyId}/toggle', [CrmPartyController::class, 'toggleActive'])
            ->whereNumber('partyId')->name('parties.toggle');

        Route::get('brands', [CrmBrandController::class, 'index'])->name('brands.index'); // ?partyId=
        Route::post('brands', [CrmBrandController::class, 'store'])->name('brands.store');
        Route::post('brands/{brandId}/toggle', [CrmBrandController::class, 'toggleActive'])
            ->whereNumber('brandId')->name('brands.toggle');

        Route::get('addresses', [CrmAddressController::class, 'index'])->name('addresses.index'); // ?partyId=
        Route::get('addresses/cities', [CrmAddressController::class, 'citiesByProvince'])->name('addresses.cities');
        Route::get('addresses/counties', [CrmAddressController::class, 'countiesByCity'])->name('addresses.counties');
        Route::post('addresses', [CrmAddressController::class, 'store'])->name('addresses.store');
        Route::post('addresses/{addressId}/toggle', [CrmAddressController::class, 'toggleActive'])
            ->whereNumber('addressId')->name('addresses.toggle');

        Route::get('contacts', [CrmContactController::class, 'index'])->name('contacts.index'); // ?partyId=
        Route::post('contacts', [CrmContactController::class, 'store'])->name('contacts.store');
        Route::post('contacts/{contactId}/toggle', [CrmContactController::class, 'toggleActive'])
            ->whereNumber('contactId')->name('contacts.toggle');

        Route::get('persons-page', [CrmPersonController::class, 'page'])->name('persons.page');
        Route::get('persons', [CrmPersonController::class, 'index'])->name('persons.index'); // ?search=
        Route::get('persons/{personId}', [CrmPersonController::class, 'show'])->whereNumber('personId')->name('persons.show');
        Route::post('persons', [CrmPersonController::class, 'store'])->name('persons.store');
        Route::post('persons/{personId}/toggle', [CrmPersonController::class, 'toggleActive'])
            ->whereNumber('personId')->name('persons.toggle');

        Route::get('relations', [CrmPersonController::class, 'relationsIndex'])->name('relations.index'); // ?partyId=
        Route::get('relations/{relationId}/roles', [CrmPersonController::class, 'relationRoles'])
            ->whereNumber('relationId')->name('relations.roles');
        Route::post('relations', [CrmPersonController::class, 'relationsStore'])->name('relations.store');
        Route::post('relations/{relationId}/toggle', [CrmPersonController::class, 'relationsToggle'])
            ->whereNumber('relationId')->name('relations.toggle');
    });

    // تنظیمات شرکت
    Route::get('company', [CompanyController::class, 'index'])->name('company.index');
    Route::post('company', [CompanyController::class, 'store'])->name('company.store');

    // پروفایل
    Route::get('/change-password', [ProfileController::class, 'showChangePassword'])->name('profile.change-password');
    Route::post('/change-password', [ProfileController::class, 'changePassword']);

    // خروج
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    
});

// لوگو و فاوآیکون شرکت
Route::get('company/logo', [CompanyController::class, 'logo'])->name('company.logo');
Route::get('company/favicon', [CompanyController::class, 'favicon'])->name('company.favicon');
Route::get('manifest.json', [CompanyController::class, 'manifest'])->name('company.manifest');

// مسیر اصلی → لاگین
Route::get('/', function () {
    return redirect()->route('login');
});