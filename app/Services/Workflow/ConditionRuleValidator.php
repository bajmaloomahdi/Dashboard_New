<?php

namespace App\Services\Workflow;

use App\Services\Workflow\Support\ConditionDataTypeCaster;
use App\Services\Workflow\Support\ConditionOperators;

/**
 * Validate طراحی‌زمانِ RuleJson — طبقِ Final Designِ Condition Engine:
 *   - Root همیشه یک GROUP است (نسخه‌بندی‌شده با version=1)
 *   - GROUP فقط logic (AND/OR) + children دارد
 *   - CONDITION فقط field + operator (+ value در صورتِ نیاز) دارد
 *   - Operatorهایِ تک‌عملوندی (IS_EMPTY/IS_NOT_EMPTY/IS_TRUE/IS_FALSE) هرگز value ندارند
 *   - فقط value.kind=CONSTANT در فاز ۱ مجاز است
 *   - حداکثر عمق ۵، حداکثر تعدادِ گره ۵۰
 *
 * این کلاس فقط در مسیرِ Validate/Publish استفاده می‌شود (نه در Runtime) و به همین
 * دلیل مجاز است WorkflowConditionFields را بخواند — بر خلافِ ConditionEvaluator که
 * هرگز این جدول را نمی‌بیند.
 */
class ConditionRuleValidator
{
    private const MAX_DEPTH = 5;
    private const MAX_NODES = 50;

    /**
     * @param  array<string,mixed>  $rule         RuleJsonِ Decodeشده
     * @param  array<string,object>  $fieldsByCode  Code => ردیفِ WorkflowConditionFields (شاملِ غیرفعال‌ها)
     * @return string[]  فهرستِ خطاها (خالی یعنی معتبر)
     */
    public function validate(array $rule, array $fieldsByCode): array
    {
        $errors = [];

        if (($rule['version'] ?? null) !== 1) {
            $errors[] = 'نسخهٔ Schemaِ Rule (version) باید ۱ باشد.';
        }
        if (($rule['type'] ?? null) !== 'GROUP') {
            $errors[] = 'ریشهٔ Rule باید از نوعِ GROUP باشد.';

            return $errors;
        }

        $nodeCount = 0;
        $this->walk($rule, $fieldsByCode, 1, $nodeCount, $errors);

        if ($nodeCount > self::MAX_NODES) {
            $errors[] = 'تعدادِ گره‌هایِ Rule از سقفِ مجاز (' . self::MAX_NODES . ') بیشتر است.';
        }

        return $errors;
    }

    /** استخراجِ همهٔ Codeهایِ فیلدِ ارجاع‌شده در یک Rule — بدونِ نیازِ DB (برایِ Guardِ Immutabilityِ Code). */
    public function extractFieldCodes(array $rule): array
    {
        $codes = [];
        $this->collectCodes($rule, $codes);

        return array_values(array_unique($codes));
    }

    /* ============================ داخلی ============================ */

    private function walk(mixed $node, array $fieldsByCode, int $depth, int &$nodeCount, array &$errors): void
    {
        $nodeCount++;

        if ($depth > self::MAX_DEPTH) {
            $errors[] = 'عمقِ تودرتوییِ Rule از سقفِ مجاز (' . self::MAX_DEPTH . ') بیشتر است.';

            return;
        }

        if (! is_array($node) || ! isset($node['type'])) {
            $errors[] = 'ساختارِ یک گره نامعتبر است (type مشخص نیست).';

            return;
        }

        match ($node['type']) {
            'GROUP'     => $this->validateGroup($node, $fieldsByCode, $depth, $nodeCount, $errors),
            'CONDITION' => $this->validateCondition($node, $fieldsByCode, $errors),
            default     => $errors[] = "نوعِ گرهِ «{$node['type']}» نامعتبر است.",
        };
    }

    private function validateGroup(array $node, array $fieldsByCode, int $depth, int &$nodeCount, array &$errors): void
    {
        foreach (['field', 'operator', 'value'] as $forbidden) {
            if (array_key_exists($forbidden, $node)) {
                $errors[] = "کلیدِ «{$forbidden}» در گرهِ GROUP مجاز نیست.";
            }
        }

        if (! in_array($node['logic'] ?? null, ['AND', 'OR'], true)) {
            $errors[] = 'logic گروه باید AND یا OR باشد.';
        }

        $children = $node['children'] ?? null;
        if (! is_array($children) || $children === [] || ! array_is_list($children)) {
            $errors[] = 'گروه باید حداقل یک فرزند داشته باشد (children).';

            return;
        }

        foreach ($children as $child) {
            $this->walk($child, $fieldsByCode, $depth + 1, $nodeCount, $errors);
        }
    }

    private function validateCondition(array $node, array $fieldsByCode, array &$errors): void
    {
        if (array_key_exists('children', $node) || array_key_exists('logic', $node)) {
            $errors[] = 'کلیدهایِ «children»/«logic» در گرهِ CONDITION مجاز نیستند.';
        }

        $fieldCode = $node['field'] ?? null;
        $operator = $node['operator'] ?? null;

        if (! is_string($fieldCode) || $fieldCode === '') {
            $errors[] = 'field در یک شرط الزامی است.';

            return;
        }
        if (! is_string($operator) || $operator === '') {
            $errors[] = "فیلدِ «{$fieldCode}»: operator الزامی است.";

            return;
        }

        $field = $fieldsByCode[$fieldCode] ?? null;
        if ($field === null) {
            $errors[] = "فیلدِ «{$fieldCode}» در این فرایند تعریف نشده است.";

            return;
        }
        if (! (bool) $field->IsActive) {
            $errors[] = "فیلدِ «{$fieldCode}» غیرفعال است؛ Ruleِ ارجاع‌دهنده به آن نمی‌تواند Publish شود.";
        }

        $dataType = $field->DataType;
        if (! ConditionOperators::isAllowed($dataType, $operator)) {
            $errors[] = "Operatorِ «{$operator}» برایِ نوعِ «{$dataType}» (فیلدِ «{$fieldCode}») مجاز نیست.";

            return;
        }

        $isUnary = ConditionOperators::isUnary($operator);
        $hasValue = array_key_exists('value', $node);

        if ($isUnary) {
            if ($hasValue) {
                $errors[] = "فیلدِ «{$fieldCode}»: Operatorِ «{$operator}» تک‌عملوندی است و نباید کلیدِ value داشته باشد.";
            }

            return;
        }

        if (! $hasValue) {
            $errors[] = "فیلدِ «{$fieldCode}»: value الزامی است.";

            return;
        }

        $this->validateValue($node['value'], $fieldCode, $operator, $field, $errors);
    }

    private function validateValue(mixed $value, string $fieldCode, string $operator, object $field, array &$errors): void
    {
        if (! is_array($value) || ($value['kind'] ?? null) !== 'CONSTANT') {
            $errors[] = "فیلدِ «{$fieldCode}»: در فاز ۱ فقط value.kind=CONSTANT مجاز است.";

            return;
        }
        if (! array_key_exists('data', $value)) {
            $errors[] = "فیلدِ «{$fieldCode}»: value.data الزامی است.";

            return;
        }

        $dataType = $field->DataType;
        $allowedValues = null;
        if ($dataType === 'SELECT' && $field->AllowedValuesJson) {
            $allowedValues = json_decode((string) $field->AllowedValuesJson, true) ?: [];
        }

        if (in_array($operator, ['IN', 'NOT_IN'], true)) {
            $items = $value['data'];
            if (! is_array($items) || $items === [] || ! array_is_list($items)) {
                $errors[] = "فیلدِ «{$fieldCode}»: برایِ Operatorِ «{$operator}»، value.data باید آرایهٔ غیرِخالی باشد.";

                return;
            }
            foreach ($items as $item) {
                $cast = ConditionDataTypeCaster::cast($dataType, $item, $allowedValues);
                if (! $cast['ok']) {
                    $errors[] = "فیلدِ «{$fieldCode}»: مقدارِ نامعتبر در لیست — {$cast['error']}";
                }
            }

            return;
        }

        $cast = ConditionDataTypeCaster::cast($dataType, $value['data'], $allowedValues);
        if (! $cast['ok']) {
            $errors[] = "فیلدِ «{$fieldCode}»: {$cast['error']}";
        }
    }

    private function collectCodes(mixed $node, array &$codes): void
    {
        if (! is_array($node) || ! isset($node['type'])) {
            return;
        }

        if ($node['type'] === 'CONDITION') {
            if (is_string($node['field'] ?? null)) {
                $codes[] = $node['field'];
            }

            return;
        }

        if ($node['type'] === 'GROUP' && is_array($node['children'] ?? null)) {
            foreach ($node['children'] as $child) {
                $this->collectCodes($child, $codes);
            }
        }
    }
}
