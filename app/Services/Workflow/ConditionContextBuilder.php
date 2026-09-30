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
 * از زمانِ Global Registryِ WorkflowConditionFields (فیلدها دیگر Definition-level
 * نیستند)، «فیلدهایِ مرتبط با این Start» دیگر با یک Queryِ ساده روی DefinitionID
 * معلوم نمی‌شود؛ به‌جایش دقیقاً همان Codeهایی که در RuleJsonِ Transitionهایِ همین
 * Version ارجاع شده‌اند استخراج می‌شوند (با همان extractFieldCodes که Publish-time
 * Validation هم استفاده می‌کند) و بعد در Registryِ سراسری Lookup می‌شوند —
 * **صرف‌نظر از IsActive**، تا اجرایِ Versionِ منتشرشده هرگز با غیرفعال‌شدنِ بعدیِ
 * یک فیلد خراب نشود (Field فقط برایِ ساختِ Ruleِ جدید باید فعال باشد، نه برایِ
 * اجرایِ Ruleِ از‌قبل‌منتشرشده).
 *
 * اگر حتی یک فیلد Cast نشود، کل عملیات با WorkflowValidationException متوقف
 * می‌شود — یعنی هیچ Instanceِ ناقصی هرگز ساخته نمی‌شود (فراخوان باید این را قبل
 * از DB::transaction صدا بزند).
 */
class ConditionContextBuilder
{
    public function __construct(
        private WorkflowStore $store,
        private ConditionRuleValidator $ruleValidator,
    ) {
    }

    /**
     * @param  array<string,mixed>  $rawContext  ورودیِ خامِ Start (StartWorkflowRequest::$context)
     * @return array<string,mixed>  Contextِ Cast‌شده، آمادهٔ json_encode برایِ ContextJson
     *
     * @throws WorkflowValidationException  اگر Cast/Validateِ هر فیلدی شکست بخورد
     */
    public function build(int $versionId, array $rawContext): array
    {
        $referencedCodes = $this->referencedFieldCodes($versionId);

        $fields = $referencedCodes === []
            ? []
            : array_filter(
                $this->store->getConditionFields(includeInactive: true),
                fn ($f) => $f->SourceType === 'START_CONTEXT' && in_array($f->Code, $referencedCodes, true)
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
            $errors[] = "کلیدِ «{$unknownKey}» در Contextِ ارسالی به هیچ فیلدِ شرطِ ارجاع‌شده در این نسخه (SourceType=START_CONTEXT) متناظر نیست.";
        }

        if ($errors !== []) {
            throw new WorkflowValidationException('دادهٔ Contextِ ارسالی برایِ شروعِ فرایند نامعتبر است.', $errors);
        }

        return $result;
    }

    /**
     * فیلترِ Additive برایِ مسیرِ نامهٔ فرایندی (Deferred/CONDITION): از یک آرایهٔ خامِ
     * دلخواه (مثلاً formValuesِ نامه، که ممکن است کلیدهایِ کاملاً بی‌ربط هم داشته باشد —
     * تاریخ، توضیحِ آزاد، ...) فقط همان کلیدهایی را نگه می‌دارد که واقعاً به یک
     * WorkflowConditionField با SourceType=START_CONTEXTِ ارجاع‌شده در همین Version
     * تعلق دارند؛ نتیجه مستقیماً آمادهٔ عبور به build() است و هرگز باعثِ خطایِ
     * «کلیدِ ناشناخته» در build() نمی‌شود. build() خودش هیچ تغییری نکرده و مصرف‌کنندگانِ
     * فعلیِ آن (Contextِ صریحِ کاربر در POST /workflow/instances) دقیقاً همان رفتارِ
     * سخت‌گیرانه را دارند.
     *
     * @param  array<string,mixed>  $rawValues
     * @return array<string,mixed>
     */
    public function extractRelevantContext(int $versionId, array $rawValues): array
    {
        $referencedCodes = $this->referencedFieldCodes($versionId);
        if ($referencedCodes === []) {
            return [];
        }

        $sourceKeys = array_map(
            fn ($f) => $f->SourceKey,
            array_filter(
                $this->store->getConditionFields(includeInactive: true),
                fn ($f) => $f->SourceType === 'START_CONTEXT' && in_array($f->Code, $referencedCodes, true)
            )
        );

        return array_intersect_key($rawValues, array_flip($sourceKeys));
    }

    /** @return string[] Codeهایِ فیلدِ ارجاع‌شده در RuleJsonِ Transitionهایِ این Version (بدونِ نیاز به وجودِ Instance). */
    private function referencedFieldCodes(int $versionId): array
    {
        $graph = $this->store->getVersionGraph($versionId);

        $codes = [];
        foreach ($graph['transitions'] as $t) {
            if (empty($t->RuleJson)) {
                continue;
            }
            $rule = json_decode((string) $t->RuleJson, true);
            if (is_array($rule)) {
                $codes = array_merge($codes, $this->ruleValidator->extractFieldCodes($rule));
            }
        }

        return array_values(array_unique($codes));
    }
}
