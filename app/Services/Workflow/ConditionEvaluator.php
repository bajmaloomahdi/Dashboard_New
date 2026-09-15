<?php

namespace App\Services\Workflow;

use App\Services\Workflow\Support\ConditionOperators;
use App\Services\Workflow\Support\DecimalMath;

/**
 * ارزیابیِ Runtimeِ RuleJson — تنها بر رویِ Contextِ همان Instance (Snapshotِ زمانِ
 * Start). این کلاس **هرگز** جدولِ WorkflowConditionFields را نمی‌خواند (طبقِ اصلِ
 * Immutabilityِ نسخه‌هایِ Publishشده) — چون RuleJsonِ Snapshotشده و ContextJson
 * برایِ ارزیابی کاملاً کافی‌اند؛ هیچ eval/SQL Dynamic در کار نیست، فقط یک پیمایشِ
 * بازگشتیِ سادهٔ AND/OR رویِ ساختارِ JSONِ ازپیش‌Validateشده.
 *
 * قاعدهٔ NULL: هر مقایسه‌ای (به‌جز Operatorهایِ تک‌عملوندی) که مقدارِ Context برایِ
 * آن فیلد غایب/NULL باشد، همیشه FALSE برمی‌گرداند — نه Exception.
 */
class ConditionEvaluator
{
    /** @param  array<string,mixed>  $context */
    public function evaluate(array $rule, array $context): bool
    {
        $type = $rule['type'] ?? null;

        if ($type === 'GROUP') {
            $logic = $rule['logic'] ?? 'AND';
            $children = $rule['children'] ?? [];

            if ($logic === 'OR') {
                foreach ($children as $child) {
                    if ($this->evaluate($child, $context)) {
                        return true;
                    }
                }

                return false;
            }

            foreach ($children as $child) {
                if (! $this->evaluate($child, $context)) {
                    return false;
                }
            }

            return true;
        }

        if ($type === 'CONDITION') {
            return $this->evaluateCondition($rule, $context);
        }

        return false;
    }

    /** بازنمودِ کوتاهِ خوانا برایِ ثبت در History (نه برایِ ارزیابی). */
    public function summarize(array $rule): string
    {
        $type = $rule['type'] ?? null;

        if ($type === 'GROUP') {
            $logic = $rule['logic'] ?? 'AND';
            $parts = array_map(fn ($c) => $this->summarize($c), $rule['children'] ?? []);
            $joined = implode(" {$logic} ", $parts);

            return count($parts) > 1 ? "({$joined})" : $joined;
        }

        if ($type === 'CONDITION') {
            $field = $rule['field'] ?? '?';
            $operator = $rule['operator'] ?? '?';

            if (ConditionOperators::isUnary($operator)) {
                return "{$field} {$operator}";
            }

            $data = $rule['value']['data'] ?? null;
            $val = is_array($data) ? implode(',', $data) : (string) $data;

            return "{$field} {$operator} {$val}";
        }

        return '?';
    }

    private function evaluateCondition(array $node, array $context): bool
    {
        $field = $node['field'] ?? null;
        $operator = $node['operator'] ?? null;
        if (! is_string($field) || ! is_string($operator)) {
            return false;
        }

        $ctxValue = array_key_exists($field, $context) ? $context[$field] : null;

        if (ConditionOperators::isUnary($operator)) {
            return match ($operator) {
                'IS_EMPTY'     => $ctxValue === null || $ctxValue === '',
                'IS_NOT_EMPTY' => ! ($ctxValue === null || $ctxValue === ''),
                'IS_TRUE'      => $ctxValue === true,
                'IS_FALSE'     => $ctxValue === false,
                default        => false,
            };
        }

        if ($ctxValue === null) {
            return false;
        }

        $ruleValue = $node['value']['data'] ?? null;

        if ($operator === 'IN') {
            return is_array($ruleValue) && in_array($ctxValue, $ruleValue, false);
        }
        if ($operator === 'NOT_IN') {
            return is_array($ruleValue) && ! in_array($ctxValue, $ruleValue, false);
        }

        return match ($operator) {
            'EQ'       => $this->valuesEqual($ctxValue, $ruleValue),
            'NE'       => ! $this->valuesEqual($ctxValue, $ruleValue),
            'GT', 'GTE', 'LT', 'LTE' => $this->compareOrdered($operator, $ctxValue, $ruleValue),
            'CONTAINS' => is_string($ctxValue) && is_string($ruleValue) && str_contains($ctxValue, $ruleValue),
            default    => false,
        };
    }

    /**
     * تساویِ دقیق — بدونِ Type Jugglingِ ضمنیِ PHP (`==`). برایِ دو رشتهٔ Decimal
     * (طبقِ الگویِ `DecimalMath::isDecimalString`)، حتی اگر Canonical نباشند (مثلِ
     * «100.50» در برابرِ «100.5»)، با `bccomp` مقایسه می‌شوند تا false-negative ندهد؛
     * برایِ بقیه (INTEGER/STRING/DATE/BOOLEAN/SELECT/USER/UNIT) تساویِ رشته‌ای/نوعیِ
     * دقیق (===) کافی و صحیح است.
     */
    private function valuesEqual(mixed $a, mixed $b): bool
    {
        if (is_string($a) && is_string($b) && DecimalMath::isDecimalString($a) && DecimalMath::isDecimalString($b)) {
            return DecimalMath::compare($a, $b) === 0;
        }

        return $a === $b;
    }

    /**
     * GT/GTE/LT/LTE — بدونِ هیچ float:
     *   - INTEGER (int در برابرِ int): مقایسهٔ Nativeِ PHP (دقیق، بدونِ float).
     *   - DECIMAL (رشتهٔ Decimalِ هر دو طرف): `DecimalMath::compare` (bcmath).
     *   - DATE (رشتهٔ «YYYY-MM-DD» هر دو طرف): مقایسهٔ لغویِ رشته‌ای، که برایِ این
     *     فرمتِ ثابت‌طول دقیقاً معادلِ مقایسهٔ زمانی است.
     */
    private function compareOrdered(string $operator, mixed $a, mixed $b): bool
    {
        if (is_int($a) && is_int($b)) {
            $cmp = $a <=> $b;
        } elseif (is_string($a) && is_string($b) && DecimalMath::isDecimalString($a) && DecimalMath::isDecimalString($b)) {
            $cmp = DecimalMath::compare($a, $b);
        } elseif (is_string($a) && is_string($b)) {
            $cmp = $a <=> $b;
        } else {
            return false;
        }

        return match ($operator) {
            'GT'  => $cmp > 0,
            'GTE' => $cmp >= 0,
            'LT'  => $cmp < 0,
            'LTE' => $cmp <= 0,
            default => false,
        };
    }
}
