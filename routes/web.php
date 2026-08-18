<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\MasterParameterController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MessageStatusController;
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
use App\Http\Controllers\MsgPriorityController;

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
        $result = DB::select('EXEC sp_GetUnreadNotificationsCount @UserID = ?', [Auth::id()]);
        $unreadCount = (int) ($result[0]->UnreadCount ?? 0);

        return Inertia::render('Dashboard', [
            'unreadNotificationsCount' => $unreadCount,
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
    Route::get('messages/{id}', [MessageController::class, 'show'])->name('messages.show');
    Route::post('messages/{id}/status', [MessageController::class, 'changeStatus'])->name('messages.change-status');
    Route::post('messages/{id}/forward', [MessageController::class, 'forward'])->name('messages.forward');
    Route::post('messages/{id}/comment', [MessageController::class, 'addComment'])->name('messages.comment');
    Route::post('messages/{id}/copy', [MessageController::class, 'addCopy'])->name('messages.copy');


    // اولویت‌های پیام
    Route::get('msg-priorities', [MsgPriorityController::class, 'index'])->name('msg-priorities.index');
    Route::post('msg-priorities', [MsgPriorityController::class, 'store'])->name('msg-priorities.store');
    Route::put('msg-priorities/{id}', [MsgPriorityController::class, 'update'])->name('msg-priorities.update');
    Route::post('msg-priorities/{id}/toggle', [MsgPriorityController::class, 'toggleActive'])->name('msg-priorities.toggle');


    // تنظیمات شرکت
    Route::get('company', [CompanyController::class, 'index'])->name('company.index');
    Route::post('company', [CompanyController::class, 'store'])->name('company.store');




    // پروفایل
    Route::get('/change-password', [ProfileController::class, 'showChangePassword'])->name('profile.change-password');
    Route::post('/change-password', [ProfileController::class, 'changePassword']);

    // خروج
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    
});

    Route::get('company/logo', [CompanyController::class, 'logo'])->name('company.logo');
    Route::get('company/favicon', [CompanyController::class, 'favicon'])->name('company.favicon');

// مسیر اصلی → لاگین
Route::get('/', function () {
    return redirect()->route('login');
});