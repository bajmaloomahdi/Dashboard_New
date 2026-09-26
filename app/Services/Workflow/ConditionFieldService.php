<?php

namespace App\Services\Workflow;

use App\Services\Workflow\Exceptions\WorkflowValidationException;
use App\Services\Workflow\Support\WorkflowStore;

/**
 * Registryِ سراسریِ فیلدهایِ شرط (WorkflowConditionFields) — Phase 2 (Global Registry).
 *
 * یک Field یک‌بار تعریف می‌شود و می‌تواند در RuleJsonِ هر تعداد Definition/Version
 * استفاده شود (برخلافِ نسخهٔ قبلی که هر Definition نسخهٔ مستقلِ خودش را داشت).
 * عمداً یک کلاسِ کاملاً جدا (نه بخشی از WorkflowDefinitionService)، دقیقاً هم‌الگو با
 * TemplateParameterService — همان تصمیمِ معماریِ استقلالِ Registryها از منطقِ Definition.
 *
 * Immutabilityِ Code پس از استفاده در هر RuleJsonی (در هر Definition/Version/Statusی،
 * حتی Archived) توسطِ خودِ sp_Wf_SaveConditionField به‌صورتِ سراسری بررسی می‌شود؛ این
 * سرویس دوباره همان بررسی را در PHP تکرار نمی‌کند (SP تنها منبعِ حقیقت است).
 */
class ConditionFieldService
{
    private const CODE_PATTERN = '/^[A-Z][A-Z0-9_]{1,49}$/';
    private const DATA_TYPES = ['STRING', 'INTEGER', 'DECIMAL', 'DATE', 'TIME', 'BOOLEAN', 'SELECT', 'USER', 'UNIT'];

    /** سقفِ تلاش برایِ یافتنِ یک Codeِ یکتا با پسوندِ عددی، قبل از خطایِ صریح. */
    private const MAX_UNIQUE_SUFFIX_ATTEMPTS = 50;

    public function __construct(private WorkflowStore $store)
    {
    }

    public function list(bool $includeInactive = false): array
    {
        return $this->store->getConditionFields($includeInactive);
    }

    /**
     * تولیدِ خودکارِ Code — کاربر هرگز Code را مستقیماً وارد نمی‌کند (UI فیلدِ Code را
     * Read-only نگه می‌دارد). این متد تنها منبعِ حقیقتِ Code است؛ هر مقداری که کلاینت
     * برایِ «code» بفرستد نادیده گرفته می‌شود — دقیقاً هم‌الگو با TemplateParameterService::save().
     *
     * @throws WorkflowValidationException اگر عنوان/نامِ لاتین برایِ ساختِ یک Codeِ معتبر کافی نباشد،
     *         یا DataType نامعتبر باشد
     */
    public function save(array $input, int $userId): object
    {
        $displayName = trim($input['displayName'] ?? '');
        $fieldId = isset($input['fieldId']) && $input['fieldId'] !== null ? (int) $input['fieldId'] : null;

        if ($fieldId !== null) {
            // ویرایش: Code هرگز تغییر نمی‌کند — حتی اگر عنوان عوض شود یا کلاینت مقدارِ
            // دیگری برایِ code بفرستد؛ تنها منبعِ حقیقت همان ردیفِ موجود در DB است.
            $existing = $this->store->getConditionFieldById($fieldId);
            if ($existing === null) {
                throw new WorkflowValidationException('فیلد یافت نشد.');
            }
            $code = $existing->Code;
        } else {
            $code = $this->generateUniqueCode($displayName, isset($input['latinName']) ? trim((string) $input['latinName']) : '');
        }

        $dataType = strtoupper(trim($input['dataType'] ?? ''));
        if (! in_array($dataType, self::DATA_TYPES, true)) {
            throw new WorkflowValidationException("نوعِ دادهٔ «{$dataType}» شناخته‌شده نیست.");
        }

        $allowedValuesJson = null;
        if ($dataType === 'SELECT') {
            $allowedValues = $input['allowedValues'] ?? null;
            if (! is_array($allowedValues) || $allowedValues === [] || ! array_is_list($allowedValues)) {
                throw new WorkflowValidationException('برایِ DataType=SELECT فهرستِ گزینه‌هایِ مجاز (allowedValues) الزامی است.');
            }
            $allowedValuesJson = json_encode(array_values(array_map('strval', $allowedValues)), JSON_UNESCAPED_UNICODE);
        }

        // SourceType هرگز از ورودیِ کاربر گرفته نمی‌شود — فعلاً تنها مقدارِ پشتیبانی‌شده.
        // SourceKey هم در UIِ ساده‌شده هرگز از کاربر گرفته نمی‌شود (پیش‌فرض = همان Code،
        // دقیقاً چیزی که Frontendِ فعلی می‌فرستد) — اما استقلالِ فنیِ Code از SourceKey
        // در سطحِ Contract حفظ می‌شود (نگاه کن به ConditionContextBuilder/ConditionEvaluator
        // که عمداً SourceKey را برایِ خواندنِ Contextِ خام استفاده می‌کنند، نه Code).
        $sourceKey = isset($input['sourceKey']) && trim((string) $input['sourceKey']) !== ''
            ? trim((string) $input['sourceKey'])
            : $code;

        $result = $this->store->saveConditionField([
            'fieldId'           => $fieldId,
            'code'              => $code,
            'displayName'       => $displayName,
            'dataType'          => $dataType,
            'sourceType'        => 'START_CONTEXT',
            'sourceKey'         => $sourceKey,
            'allowedValuesJson' => $allowedValuesJson,
            'description'       => isset($input['description']) && trim($input['description']) !== '' ? trim($input['description']) : null,
            'sortOrder'         => $input['sortOrder'] ?? 0,
            'userId'            => $userId,
        ]);
        $result->Code = $code;

        return $result;
    }

    public function toggleActive(int $fieldId, int $userId): object
    {
        return $this->store->toggleConditionFieldActive($fieldId, $userId);
    }

    /**
     * Codeِ پایه را ابتدا از عنوان می‌سازد؛ اگر عنوان حرفِ لاتینِ کافی نداشت، از
     * latinName استفاده می‌کند. سپس با retry عددی (AMOUNT_2, AMOUNT_3, ...) یکتا می‌شود.
     *
     * @throws WorkflowValidationException
     */
    private function generateUniqueCode(string $displayName, string $latinName): string
    {
        if ($displayName === '') {
            throw new WorkflowValidationException('عنوان الزامی است.');
        }

        $base = $this->baseCodeFromText($displayName);
        if (! preg_match(self::CODE_PATTERN, $base)) {
            if ($latinName === '') {
                throw new WorkflowValidationException('عنوان شامل حروفِ لاتینِ کافی نیست — یک «نامِ لاتین» کوتاه وارد کنید.');
            }
            $base = $this->baseCodeFromText($latinName);
            if (! preg_match(self::CODE_PATTERN, $base)) {
                throw new WorkflowValidationException('نامِ لاتین نامعتبر است — باید با یک حرفِ لاتین شروع شود و فقط شاملِ حروفِ لاتین/عدد/آندرلاین باشد.');
            }
        }

        $code = $base;
        $suffix = 2;
        while ($this->store->getConditionFieldByCode($code) !== null) {
            if ($suffix > self::MAX_UNIQUE_SUFFIX_ATTEMPTS) {
                throw new WorkflowValidationException('امکانِ ساختِ یک Codeِ یکتا از این عنوان/نامِ لاتین وجود ندارد — لطفاً مقدارِ دیگری انتخاب کنید.');
            }
            $stem = mb_substr($base, 0, 50 - mb_strlen('_' . $suffix));
            $code = $stem . '_' . $suffix;
            $suffix++;
        }

        return $code;
    }

    /** حروفِ بزرگِ لاتین/عدد/آندرلاین را نگه می‌دارد؛ بقیه (فارسی/علائم) حذف می‌شود — هم‌ارزِ suggestCode() در Frontend. */
    private function baseCodeFromText(string $text): string
    {
        $upper = mb_strtoupper($text, 'UTF-8');
        $stripped = preg_replace('/[^A-Z0-9_\s]/u', '', $upper) ?? '';
        $trimmed = trim($stripped);
        $underscored = preg_replace('/\s+/', '_', $trimmed) ?? '';
        $collapsed = preg_replace('/_+/', '_', $underscored) ?? '';
        $noLeadingDigits = preg_replace('/^[^A-Z]+/', '', $collapsed) ?? '';

        return mb_substr($noLeadingDigits, 0, 50);
    }
}
