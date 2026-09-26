<?php

namespace App\Services\Workflow;

use App\Services\Workflow\Exceptions\WorkflowValidationException;
use App\Services\Workflow\Support\WorkflowStore;

/**
 * مدیریتِ Registryِ پارامترهایِ Template (TemplateParameters) — Phase 3, Phase B.
 *
 * عمداً یک کلاسِ کاملاً جدا (نه بخشی از WorkflowDefinitionService) تا استقلال از
 * Condition Engine در سطحِ خودِ فایل هم قابلِ‌مشاهده باشد — این کلاس هیچ‌گاه
 * WorkflowConditionFields، ConditionContextBuilder یا ConditionDataTypeCaster را
 * import/فراخوانی نمی‌کند. تنها Dependencyِ آن WorkflowStore است (همان Facadeِ
 * DBِ مشترکِ کلِ ماژولِ Workflow — خودِ آن هم به Condition Engine وابسته نیست).
 *
 * اتصال به WorkflowEntityTypes (Phase A) فقط از طریقِ یک Lookupِ سبک
 * (WorkflowStore::getEntityTypeByCode) است — بدونِ isEntityTypeUsable() و بدونِ
 * نیاز به Resolverِ واقعی در config/workflow.php؛ یک پارامترِ Template فقط باید
 * به یک EntityTypeِ واقعاً ثبت‌شده اشاره کند، نه لزوماً یک EntityType با Resolverِ
 * اجراییِ Workflow.
 */
class TemplateParameterService
{
    /** همان الگویِ Codeِ WorkflowConditionFields (هم‌شکل، نه هم‌کلاس — کپیِ محلی برایِ حفظِ استقلال). */
    private const CODE_PATTERN = '/^[A-Z][A-Z0-9_]{1,49}$/';

    /**
     * منبعِ مقدار — در UI یک مفهومِ واحد («منبع») است؛ در DB به دو ستونِ تاریخیِ
     * GroupCode/SourceType نگاشت می‌شود که همیشه یک مقدارِ یکسان دارند (این کلاس
     * تضمین می‌کند). SourceKeyِ متناظرِ هر SourceType در resolveUserField/
     * resolveSystemField در TemplateRenderer.php مصرف می‌شود — همان‌جا تغییر نکرده.
     */
    private const SOURCE_TYPES = ['USER', 'SYSTEM', 'FORM'];
    private const DATA_TYPES = ['STRING', 'DATE', 'TIME', 'INTEGER', 'DECIMAL'];

    /** تنها SourceKeyهایِ شناخته‌شده برایِ SourceType=USER (نگاه کن به TemplateRenderer::resolveUserField). */
    private const USER_SOURCE_KEYS = ['FullName', 'UserCode', 'PositionName', 'UnitName'];

    /** تنها SourceKeyِ شناخته‌شده برایِ SourceType=SYSTEM (نگاه کن به TemplateRenderer::resolveSystemField). */
    private const SYSTEM_SOURCE_KEY = 'Today';

    /** سقفِ تلاش برایِ یافتنِ یک Codeِ یکتا با پسوندِ عددی، قبل از خطایِ صریح. */
    private const MAX_UNIQUE_SUFFIX_ATTEMPTS = 50;

    public function __construct(private WorkflowStore $store)
    {
    }

    public function list(
        ?string $search = null,
        ?string $groupCode = null,
        ?string $entityType = null,
        ?bool $isActive = null
    ): array {
        return $this->store->getTemplateParameters($search, $groupCode, $entityType, $isActive);
    }

    public function getByCode(string $code): ?object
    {
        return $this->store->getTemplateParameterByCode($code);
    }

    /**
     * تولیدِ خودکارِ Code — کاربر هرگز Code را مستقیماً وارد نمی‌کند (UI فیلدِ Code را
     * Read-only نگه می‌دارد). این متد تنها منبعِ حقیقتِ Code است؛ هر مقداری که کلاینت
     * برایِ «code» بفرستد نادیده گرفته می‌شود.
     *
     * - اگر عنوان حاویِ حروفِ لاتینِ کافی باشد، Code مستقیماً از رویِ همان ساخته می‌شود.
     * - وگرنه (عنوانِ کاملاً فارسی/بدونِ حرفِ لاتینِ کافی) از «latinName»ِ ارسالی
     *   (که فقط در همین حالت در UI نمایش داده می‌شود) ساخته می‌شود.
     * - در صورتِ برخورد با Codeِ تکراری، به‌جایِ خطایِ فوری یا پسوندِ تصادفی، یک
     *   پسوندِ عددیِ قابلِ‌پیش‌بینی امتحان می‌شود (AMOUNT، AMOUNT_2، AMOUNT_3، ...).
     *
     * @throws WorkflowValidationException اگر عنوان/نامِ لاتین برایِ ساختِ یک Codeِ معتبر کافی نباشد،
     *         یا DataType/SourceType/EntityType/SourceKey نامعتبر باشد
     */
    public function save(array $input, int $userId): object
    {
        $caption = trim($input['caption'] ?? '');
        $templateParameterId = isset($input['templateParameterId']) && $input['templateParameterId'] !== null
            ? (int) $input['templateParameterId']
            : null;

        if ($templateParameterId !== null) {
            // ویرایش: Code هرگز تغییر نمی‌کند — حتی اگر عنوان عوض شود یا کلاینت مقدارِ
            // دیگری برایِ code بفرستد؛ تنها منبعِ حقیقت همان ردیفِ موجود در DB است.
            $existing = $this->store->getTemplateParameterById($templateParameterId);
            if ($existing === null) {
                throw new WorkflowValidationException('پارامتر یافت نشد.');
            }
            $code = $existing->Code;
        } else {
            $code = $this->generateUniqueCode($caption, isset($input['latinName']) ? trim((string) $input['latinName']) : '');
        }

        $sourceType = strtoupper(trim($input['sourceType'] ?? ''));
        if (! in_array($sourceType, self::SOURCE_TYPES, true)) {
            throw new WorkflowValidationException('منبعِ مقدار نامعتبر است.');
        }

        $dataType = strtoupper(trim($input['dataType'] ?? ''));
        if (! in_array($dataType, self::DATA_TYPES, true)) {
            throw new WorkflowValidationException('DataType نامعتبر است.');
        }

        // GroupCode در UI مفهومی جدا از Source نیست — همیشه با SourceType یکی است
        // (نگاه کن به توضیحِ SOURCE_TYPES بالا). EntityType هم فقط برایِ Source=FORM
        // در UI قابلِ‌انتخاب است (تنها Sourceای که در عمل EntityType-scoped می‌شود).
        $groupCode = $sourceType;

        $sourceKey = trim($input['sourceKey'] ?? '');
        if ($sourceType === 'SYSTEM') {
            // فعلاً تنها یک مقدارِ سیستمی پشتیبانی می‌شود؛ کاملاً خودکار، بدونِ دخالتِ کاربر.
            $sourceKey = self::SYSTEM_SOURCE_KEY;
        } elseif ($sourceType === 'USER') {
            if (! in_array($sourceKey, self::USER_SOURCE_KEYS, true)) {
                throw new WorkflowValidationException('فیلدِ کاربرِ انتخاب‌شده نامعتبر است.');
            }
        } elseif ($sourceKey === '') {
            throw new WorkflowValidationException('نامِ فیلدِ فرم الزامی است.');
        }

        $entityType = $sourceType === 'FORM' && isset($input['entityType']) && $input['entityType'] !== ''
            ? trim($input['entityType'])
            : null;
        if ($entityType !== null) {
            $row = $this->store->getEntityTypeByCode($entityType);
            if ($row === null) {
                throw new WorkflowValidationException("نوعِ موجودیتِ «{$entityType}» در Registry ثبت نشده است.");
            }
        }

        $result = $this->store->saveTemplateParameter([
            'templateParameterId' => $templateParameterId,
            'code'                => $code,
            'caption'             => $caption,
            'groupCode'           => $groupCode,
            'entityType'          => $entityType,
            'dataType'            => $dataType,
            'sourceType'          => $sourceType,
            'sourceKey'           => $sourceKey,
            'allowedValuesJson'   => $input['allowedValuesJson'] ?? null,
            'description'         => isset($input['description']) && trim($input['description']) !== '' ? trim($input['description']) : null,
            'sortOrder'           => $input['sortOrder'] ?? 0,
            'userId'              => $userId,
        ]);
        $result->Code = $code;

        return $result;
    }

    public function toggleActive(int $templateParameterId, int $userId): object
    {
        return $this->store->toggleTemplateParameterActive($templateParameterId, $userId);
    }

    /**
     * Codeِ پایه را ابتدا از عنوان می‌سازد؛ اگر عنوان حرفِ لاتینِ کافی نداشت، از
     * latinName استفاده می‌کند. سپس با retry عددی (AMOUNT_2, AMOUNT_3, ...) یکتا می‌شود.
     *
     * @throws WorkflowValidationException
     */
    private function generateUniqueCode(string $caption, string $latinName): string
    {
        if ($caption === '') {
            throw new WorkflowValidationException('عنوان الزامی است.');
        }

        $base = $this->baseCodeFromText($caption);
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
        while ($this->store->getTemplateParameterByCode($code) !== null) {
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
