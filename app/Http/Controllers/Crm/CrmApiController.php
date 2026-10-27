<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Services\Crm\Exceptions\CrmDuplicateNameException;
use App\Services\Crm\Exceptions\CrmException;
use App\Services\Crm\Exceptions\CrmValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * پایهٔ کنترلرهای API ماژولِ CRM — هم‌الگو با WorkflowApiController
 * (sp_GetUserPermissions + کشِ درخواستی + abort(403)).
 */
abstract class CrmApiController extends Controller
{
    /** @var array<int,string[]> */
    private array $permissionCache = [];

    protected function actorId(): int
    {
        return (int) Auth::id();
    }

    /** @return string[] */
    protected function loadPermissions(): array
    {
        $userId = (int) Auth::id();

        if (! isset($this->permissionCache[$userId])) {
            $rows = DB::select('EXEC sp_GetUserPermissions @UserID = ?', [$userId]);
            $this->permissionCache[$userId] = collect($rows)->pluck('PermissionCode')->all();
        }

        return $this->permissionCache[$userId];
    }

    protected function userCan(string $code): bool
    {
        return in_array($code, $this->loadPermissions(), true);
    }

    protected function authorizeCrm(string $code): void
    {
        if (! $this->userCan($code)) {
            abort(403, 'شما دسترسی لازم برای این عملیات را ندارید.');
        }
    }

    /**
     * اجرای منطقِ سرویس و تبدیلِ استثناهای ماژول به پاسخِ استاندارد.
     *
     * @param  \Closure():?array  $fn
     */
    protected function runCrm(\Closure $fn): JsonResponse
    {
        try {
            $data = $fn() ?? [];
            if (isset($data['message']) && is_string($data['message'])) {
                $data['message'] = self::groupTerms($data['message']);
            }

            return response()->json(array_merge(['success' => true], $data));
        } catch (CrmDuplicateNameException $e) {
            // هشدارِ نامِ تکراری: چیزی ثبت نشده؛ رابطِ کاربری تأیید می‌گیرد و با allowDuplicateName دوباره می‌فرستد
            return response()->json([
                'success' => false, 'duplicateName' => true, 'message' => $e->getMessage(),
                'duplicates' => $e->duplicates, 'duplicateCount' => $e->duplicateCount,
            ], 409);
        } catch (CrmValidationException $e) {
            return response()->json(['success' => false, 'message' => self::groupTerms($e->getMessage()), 'errors' => $e->errors], 422);
        } catch (CrmException $e) {
            return response()->json(['success' => false, 'message' => self::groupTerms($e->getMessage())], 422);
        }
    }

    /**
     * «نوع» → «گروه» و «فعالیت» → «زیرگروه» در پیام‌هایِ ثابتِ SPهایِ طبقه‌بندیِ طرف‌حساب — فقط برچسب؛ متنِ SPها در
     * دیتابیس عوض نشده است. عبارت‌ها دقیق‌اند تا «نوعِ تماس/تعامل/مالکیت» و پیام‌هایِ دیگر دست نخورند.
     */
    protected const GROUP_TERMS = [
        'نوع ایجاد شد.' => 'گروه ایجاد شد.',
        'نوع ویرایش شد.' => 'گروه ویرایش شد.',
        'نوع یافت نشد.' => 'گروه یافت نشد.',
        'نوع غیرفعال شد.' => 'گروه غیرفعال شد.',
        'نوع فعال شد.' => 'گروه فعال شد.',
        'نوع ذخیره شد.' => 'گروه ذخیره شد.',
        'این نوع دارایِ یک یا چند فعالیتِ فعال است؛ ابتدا آن‌ها را غیرفعال کنید.' => 'این گروه دارایِ یک یا چند زیرگروهِ فعال است؛ ابتدا آن‌ها را غیرفعال کنید.',
        'فعالیت ایجاد شد.' => 'زیرگروه ایجاد شد.',
        'فعالیت ویرایش شد.' => 'زیرگروه ویرایش شد.',
        'فعالیت یافت نشد.' => 'زیرگروه یافت نشد.',
        'فعالیت غیرفعال شد.' => 'زیرگروه غیرفعال شد.',
        'فعالیت فعال شد.' => 'زیرگروه فعال شد.',
        'فعالیت ذخیره شد.' => 'زیرگروه ذخیره شد.',
        'حداقل یکی از دپارتمان/نوع/فعالیت باید انتخاب شود.' => 'حداقل یکی از دپارتمان/گروه/زیرگروه باید انتخاب شود.',
        'فعالیتِ انتخاب‌شده متعلق به نوعِ انتخاب‌شده نیست.' => 'زیرگروهِ انتخاب‌شده متعلق به گروهِ انتخاب‌شده نیست.',
        '«نوع»' => '«گروه»',
        '«فعالیت»' => '«زیرگروه»',
    ];

    public static function groupTerms(string $message): string
    {
        return strtr($message, self::GROUP_TERMS);
    }
}
