<?php

namespace App\Services\Workflow;

use App\Services\Workflow\Exceptions\WorkflowStateException;

/**
 * انتخابِ گذارِ (Transition) بعدی از یک مرحله.
 *
 * فاز ۱: بدونِ شرط (ConditionJson). انتخاب فقط بر اساسِ:
 *   ۱) تطابقِ کدِ Action ای که کاربر انجام داده با TriggerActionID گذار
 *   ۲) در نبودِ تطابق، گذارِ پیش‌فرض (IsDefault = 1)
 *   ۳) در مراحلِ خودکار/شروع (بدونِ Action)، تنها گذارِ خروجی یا گذارِ پیش‌فرض
 * در هر گروه، مرتب‌سازی بر Priority صعودی و انتخابِ اولی.
 *
 * Condition Builder در این کلاس پیاده نشده و ساختار طوری است که افزودنِ آن
 * (یک مرحلهٔ فیلترِ اضافه قبل از انتخاب) موتور را بازنویسی نمی‌کند.
 */
class TransitionResolver
{
    /**
     * @param  array<int,object>  $transitions  همهٔ گذارهای نسخه
     * @param  array<int,object>  $actions      همهٔ Actionهای نسخه (برای نگاشتِ Code → ActionID)
     * @param  int                $fromStepId
     * @param  string|null        $actionCode   کدِ Action ای که باعثِ خروج شده (null برای مراحلِ خودکار)
     * @return object  ردیفِ گذارِ انتخاب‌شده
     */
    public function resolve(array $transitions, array $actions, int $fromStepId, ?string $actionCode): object
    {
        $outgoing = array_values(array_filter(
            $transitions,
            fn ($t) => (int) $t->FromStepID === $fromStepId
        ));

        if ($outgoing === []) {
            throw new WorkflowStateException('این مرحله هیچ گذارِ خروجی‌ای ندارد (بن‌بست در تعریفِ فرایند).');
        }

        usort($outgoing, fn ($a, $b) => ($a->Priority <=> $b->Priority) ?: ($a->TransitionID <=> $b->TransitionID));

        // ۱) گذارِ منطبق با Action
        if ($actionCode !== null) {
            $actionIds = [];
            foreach ($actions as $a) {
                if ($a->Code === $actionCode && (int) $a->StepID === $fromStepId) {
                    $actionIds[(int) $a->ActionID] = true;
                }
            }

            foreach ($outgoing as $t) {
                if ($t->TriggerActionID !== null && isset($actionIds[(int) $t->TriggerActionID])) {
                    return $t;
                }
            }
        }

        // ۲) گذارِ پیش‌فرض
        foreach ($outgoing as $t) {
            if ((int) $t->IsDefault === 1) {
                return $t;
            }
        }

        // ۳) اگر فقط یک گذارِ خروجیِ بدونِ Trigger داریم، همان
        if (count($outgoing) === 1 && $outgoing[0]->TriggerActionID === null) {
            return $outgoing[0];
        }

        throw new WorkflowStateException(
            $actionCode !== null
                ? "برای اقدامِ «{$actionCode}» هیچ گذارِ متناظری در این مرحله تعریف نشده است."
                : 'برای این مرحلهٔ خودکار هیچ گذارِ پیش‌فرضی تعریف نشده است.'
        );
    }
}
