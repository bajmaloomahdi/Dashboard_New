<?php

namespace App\Services\Workflow;

use App\Services\Workflow\Exceptions\WorkflowValidationException;
use App\Services\Workflow\Support\ConditionDataTypeCaster;
use App\Services\Workflow\Support\WorkflowStore;

/**
 * ساختِ Snapshotِ Contextِ زمانِ Start — طبقِ Final Design:
 *   - فقط فیلدهایِ SourceType=START_CONTEXT (تنها مقدارِ فعالِ فاز ۱)
 *   - همهٔ فیلدها Optional (غیابِ مقدار خطا نیست، فقط از Context کنار گذاشته می‌شود)
 *   - Validate/Cast اینجا و **قبل از Transactionِ ساختِ Instance** انجام می‌شود
 *   - خروجی با کلیدِ Code (نه SourceKey) ذخیره می‌شود — چون RuleJson و
 *     ConditionEvaluator بعداً با همین Code به Context مراجعه می‌کنند
 *
 * اگر حتی یک فیلد Cast نشود، کل عملیات با WorkflowValidationException متوقف
 * می‌شود — یعنی هیچ Instanceِ ناقصی هرگز ساخته نمی‌شود (فراخوان باید این را قبل
 * از DB::transaction صدا بزند).
 */
class ConditionContextBuilder
{
    public function __construct(private WorkflowStore $store)
    {
    }

    /**
     * @param  array<string,mixed>  $rawContext  ورودیِ خامِ Start (StartWorkflowRequest::$context)
     * @return array<string,mixed>  Contextِ Cast‌شده، آمادهٔ json_encode برایِ ContextJson
     *
     * @throws WorkflowValidationException  اگر Cast/Validateِ هر فیلدی شکست بخورد
     */
    public function build(int $definitionId, array $rawContext): array
    {
        $fields = array_filter(
            $this->store->getConditionFields($definitionId, includeInactive: false),
            fn ($f) => $f->SourceType === 'START_CONTEXT'
        );

        $result = [];
        $errors = [];
        $knownSourceKeys = [];

        foreach ($fields as $field) {
            $knownSourceKeys[] = $field->SourceKey;

            $raw = $rawContext[$field->SourceKey] ?? null;
            if ($raw === null) {
                continue;
            }

            $allowedValues = null;
            if ($field->DataType === 'SELECT' && $field->AllowedValuesJson) {
                $allowedValues = json_decode((string) $field->AllowedValuesJson, true) ?: [];
            }

            $cast = ConditionDataTypeCaster::cast($field->DataType, $raw, $allowedValues);
            if (! $cast['ok']) {
                $errors[] = "فیلدِ «{$field->Code}»: {$cast['error']}";

                continue;
            }

            $result[$field->Code] = $cast['value'];
        }

        // کلیدهایِ ناشناخته Silently Ignore نمی‌شوند — تایپوی SourceKey یا Contextِ
        // اشتباه‌فرستاده‌شده باید همان زمانِ Start آشکار شود، نه بعداً به‌صورتِ یک
        // Ruleِ ساکتاً هیچ‌وقت TRUE‌نشونده.
        foreach (array_diff(array_keys($rawContext), $knownSourceKeys) as $unknownKey) {
            $errors[] = "کلیدِ «{$unknownKey}» در Contextِ ارسالی به هیچ فیلدِ شرطِ فعالی (SourceType=START_CONTEXT) متناظر نیست.";
        }

        if ($errors !== []) {
            throw new WorkflowValidationException('دادهٔ Contextِ ارسالی برایِ شروعِ فرایند نامعتبر است.', $errors);
        }

        return $result;
    }
}
