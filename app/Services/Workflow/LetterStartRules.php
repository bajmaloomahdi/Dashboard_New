<?php

namespace App\Services\Workflow;

/**
 * پیش‌شرط‌هایِ «شروعِ فرایند از طریقِ نامهٔ فرایندی» (Pre-create + Adopt).
 *
 * فقط مسیرِ نامهٔ فرایندی را محدود می‌کند؛ WorkflowEngine::start() و رفتارِ عمومیِ
 * موتور را تغییر نمی‌دهد. ورودی، خروجیِ WorkflowEngine::previewAssignment() است.
 * قواعد کاملاً Generic‌اند و به هیچ Code/نامِ فرایندی وابسته نیستند.
 */
final class LetterStartRules
{
    /** نوعِ موجودیتی که نامهٔ فرایندی می‌سازد. */
    public const ENTITY_TYPE = 'MESSAGE';

    /** @var array<string,string> */
    public const MESSAGES = [
        'NO_ACTIVE_VERSION'      => 'این فرایند نسخهٔ فعالی ندارد؛ امکانِ ارسالِ نامه وجود ندارد.',
        'NO_START_STEP'          => 'تعریفِ این فرایند ناقص است (بدونِ مرحلهٔ شروع)؛ امکانِ ارسالِ نامه وجود ندارد.',
        'DEAD_END'               => 'مسیرِ این فرایند به بن‌بست می‌رسد؛ امکانِ ارسالِ نامه وجود ندارد.',
        'UNSUPPORTED_STEP'       => 'نوعِ مرحله‌ای در مسیرِ ابتدایِ این فرایند برایِ نامهٔ فرایندی پشتیبانی نمی‌شود.',
        'LOOP_GUARD'             => 'مسیرِ ابتدایِ این فرایند قابلِ‌محاسبه نیست.',
        'CONDITION'              => 'گیرنده پس از بررسیِ شرطِ فرایند مشخص می‌شود.',
        'NO_TASK'                => 'این فرایند مرحلهٔ تسکی ندارد؛ نامهٔ فرایندی برایِ آن معنا ندارد.',
        'NO_ASSIGNEE_FOUND'      => 'برایِ مرحلهٔ اولِ این فرایند گیرندهٔ فعالی یافت نشد (مثلاً مدیرِ مستقیمِ شما در ساختارِ سازمانی تعریف نشده است). نامه ثبت نشد؛ لطفاً با مدیرِ سیستم تماس بگیرید.',
        'INSUFFICIENT_ASSIGNEES' => 'تعدادِ انجام‌دهندگانِ قابلِ‌تعیین برایِ مرحلهٔ اول کمتر از «تعدادِ تأییدِ لازم» است؛ فرایند هرگز به نتیجه نمی‌رسد و نامه ثبت نشد.',
        'ENTITY_TYPE_MISMATCH'   => 'این فرایند برایِ نوعِ موجودیتِ «پیام» تعریف نشده است و از طریقِ نامهٔ فرایندی قابلِ شروع نیست.',
    ];

    /**
     * @param  array{resolved?:bool, reason?:?string, users?:array, step?:?array, entityType?:?string}  $preview
     * @return array{ok:bool, reason:?string, message:?string, deferred:bool}
     */
    public static function evaluate(array $preview): array
    {
        $fail = fn (string $reason): array => ['ok' => false, 'reason' => $reason, 'message' => self::MESSAGES[$reason] ?? $reason, 'deferred' => false];

        $entityType = $preview['entityType'] ?? null;
        if ($entityType !== null && $entityType !== self::ENTITY_TYPE) {
            return $fail('ENTITY_TYPE_MISMATCH');
        }

        // وجودِ CONDITION دیگر مانعِ شروع نیست: گیرندهٔ مرحلهٔ اول ممکن است به نتیجهٔ یک شرط
        // بستگی داشته باشد که فقط با Contextِ واقعی (formValues، در لحظهٔ Submit) قابلِ‌ارزیابی
        // است، نه اینجا (previewAssignment بدونِ Context). این حالت دیگر خطا نیست؛ فقط یعنی
        // ساختِ Message باید «معلق» (بدونِ گیرندهٔ اولیه) انجام شود تا Engine خودش شرط را طی
        // کند و گیرندهٔ واقعی را در اولین Stepِ واقعی تعیین کند.
        if (($preview['reason'] ?? null) === 'CONDITION') {
            return ['ok' => true, 'reason' => 'CONDITION', 'message' => self::MESSAGES['CONDITION'], 'deferred' => true];
        }

        if (empty($preview['resolved'])) {
            return $fail((string) ($preview['reason'] ?? 'UNSUPPORTED_STEP'));
        }

        // resolved=true با reason=NO_TASK یا NO_ASSIGNEE_FOUND
        if (($preview['reason'] ?? null) !== null) {
            return $fail((string) $preview['reason']);
        }

        $users = $preview['users'] ?? [];
        if ($users === []) {
            return $fail('NO_ASSIGNEE_FOUND');
        }

        // N_OF_M: اگر انجام‌دهندگان کمتر از N باشند، Task هرگز با تأییدِ کافی بسته نمی‌شود.
        $step = $preview['step'] ?? null;
        if ($step && ($step['assignPolicy'] ?? 'ANY') === 'N_OF_M') {
            $required = (int) ($step['requiredApprovals'] ?? 0);
            if ($required < 1 || count($users) < $required) {
                return $fail('INSUFFICIENT_ASSIGNEES');
            }
        }

        return ['ok' => true, 'reason' => null, 'message' => null, 'deferred' => false];
    }
}
