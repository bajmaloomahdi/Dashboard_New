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

    private const GROUP_CODES = ['USER', 'SYSTEM', 'FORM'];
    private const SOURCE_TYPES = ['USER', 'SYSTEM', 'FORM'];
    private const DATA_TYPES = ['STRING', 'DATE', 'INTEGER', 'DECIMAL'];

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
     * @throws WorkflowValidationException اگر Code/GroupCode/DataType/SourceType/EntityType نامعتبر باشد
     */
    public function save(array $input, int $userId): object
    {
        $code = strtoupper(trim($input['code'] ?? ''));
        if (! preg_match(self::CODE_PATTERN, $code)) {
            throw new WorkflowValidationException('کدِ پارامتر باید با حرفِ بزرگِ لاتین شروع شود و فقط شاملِ حروفِ بزرگ/عدد/آندرلاین باشد (۲ تا ۵۰ نویسه).');
        }

        $groupCode = strtoupper(trim($input['groupCode'] ?? ''));
        if (! in_array($groupCode, self::GROUP_CODES, true)) {
            throw new WorkflowValidationException('GroupCode نامعتبر است.');
        }

        $sourceType = strtoupper(trim($input['sourceType'] ?? ''));
        if (! in_array($sourceType, self::SOURCE_TYPES, true)) {
            throw new WorkflowValidationException('SourceType نامعتبر است.');
        }

        $dataType = strtoupper(trim($input['dataType'] ?? ''));
        if (! in_array($dataType, self::DATA_TYPES, true)) {
            throw new WorkflowValidationException('DataType نامعتبر است.');
        }

        $entityType = isset($input['entityType']) && $input['entityType'] !== '' ? trim($input['entityType']) : null;
        if ($entityType !== null) {
            $row = $this->store->getEntityTypeByCode($entityType);
            if ($row === null) {
                throw new WorkflowValidationException("نوعِ موجودیتِ «{$entityType}» در Registry ثبت نشده است.");
            }
        }

        return $this->store->saveTemplateParameter([
            'templateParameterId' => $input['templateParameterId'] ?? null,
            'code'                => $code,
            'caption'             => trim($input['caption'] ?? ''),
            'groupCode'           => $groupCode,
            'entityType'          => $entityType,
            'dataType'            => $dataType,
            'sourceType'          => $sourceType,
            'sourceKey'           => trim($input['sourceKey'] ?? ''),
            'allowedValuesJson'   => $input['allowedValuesJson'] ?? null,
            'sortOrder'           => $input['sortOrder'] ?? 0,
            'userId'              => $userId,
        ]);
    }

    public function toggleActive(int $templateParameterId, int $userId): object
    {
        return $this->store->toggleTemplateParameterActive($templateParameterId, $userId);
    }
}
