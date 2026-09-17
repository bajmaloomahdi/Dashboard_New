<?php

namespace App\Services\Workflow;

use App\Services\Workflow\Exceptions\WorkflowValidationException;
use App\Services\Workflow\Support\WorkflowStore;

/**
 * مدیریتِ Registryِ قالب‌هایِ نامه (LetterTemplates) — Phase 3, Phase C.
 *
 * مستقل از Condition Engine (هیچ Importی از WorkflowConditionFields/
 * ConditionContextBuilder/ConditionDataTypeCaster). اتصال به WorkflowEntityTypes
 * (Phase A) فقط از طریقِ Lookupِ سبکِ WorkflowStore::getEntityTypeByCode.
 * اعتبارسنجیِ Tokenها از طریقِ TemplateRenderer::validateTokens انجام می‌شود —
 * همان منطقی که در لحظهٔ Render هم استفاده می‌شود (یک منبعِ واحدِ حقیقت).
 */
class LetterTemplateService
{
    private const CODE_PATTERN = '/^[A-Z][A-Z0-9_]{1,49}$/';

    public function __construct(
        private WorkflowStore $store,
        private TemplateRenderer $renderer,
    ) {
    }

    public function list(
        ?string $search = null,
        ?string $entityType = null,
        ?int $definitionId = null,
        ?bool $isActive = null
    ): array {
        return $this->store->getLetterTemplates($search, $entityType, $definitionId, $isActive);
    }

    public function find(int $letterTemplateId): ?object
    {
        return $this->store->getLetterTemplateById($letterTemplateId);
    }

    /** Templateهایِ مجاز برایِ یک Definitionِ انتخاب‌شده (اختصاصی + عمومیِ همان EntityType). */
    public function listForDefinition(int $definitionId): array
    {
        return $this->store->getLetterTemplatesForDefinition($definitionId);
    }

    /**
     * @throws WorkflowValidationException اگر Code/EntityType/سازگاریِ Definition/Tokenها نامعتبر باشد
     */
    public function save(array $input, int $userId): object
    {
        $code = strtoupper(trim($input['code'] ?? ''));
        if (! preg_match(self::CODE_PATTERN, $code)) {
            throw new WorkflowValidationException('کدِ قالب باید با حرفِ بزرگِ لاتین شروع شود و فقط شاملِ حروفِ بزرگ/عدد/آندرلاین باشد (۲ تا ۵۰ نویسه).');
        }

        $entityType = trim($input['entityType'] ?? '');
        if ($entityType === '') {
            throw new WorkflowValidationException('نوعِ موجودیت الزامی است.');
        }
        $entityRow = $this->store->getEntityTypeByCode($entityType);
        if ($entityRow === null || ! (bool) $entityRow->IsActive) {
            throw new WorkflowValidationException("نوعِ موجودیتِ «{$entityType}» در Registry فعال/شناخته‌شده نیست.");
        }

        $definitionId = isset($input['definitionId']) && $input['definitionId'] !== '' ? (int) $input['definitionId'] : null;
        if ($definitionId !== null) {
            $definition = $this->store->getDefinition($definitionId)['definition'] ?? null;
            if ($definition === null) {
                throw new WorkflowValidationException('فرایندِ انتخاب‌شده یافت نشد.');
            }
            if ($definition->EntityType !== $entityType) {
                throw new WorkflowValidationException('نوعِ موجودیتِ قالب با نوعِ موجودیتِ فرایندِ انتخاب‌شده یکسان نیست.');
            }
        }

        $subjectTemplate = $input['subjectTemplate'] ?? '';
        $bodyTemplate = $input['bodyTemplate'] ?? '';
        if (trim($subjectTemplate) === '' || trim($bodyTemplate) === '') {
            throw new WorkflowValidationException('موضوع و متنِ قالب الزامی‌اند.');
        }

        // Backendِ مستقل و قطعی — تمامِ Tokenها باید در Registryِ TemplateParameters
        // موجود، فعال، و (اگر EntityType دارند) سازگار با EntityTypeِ همین Template باشند.
        $this->renderer->validateTokens($subjectTemplate, $bodyTemplate, $entityType);

        return $this->store->saveLetterTemplate([
            'letterTemplateId' => $input['letterTemplateId'] ?? null,
            'code'             => $code,
            'name'             => trim($input['name'] ?? ''),
            'entityType'       => $entityType,
            'definitionId'     => $definitionId,
            'subjectTemplate'  => $subjectTemplate,
            'bodyTemplate'     => $bodyTemplate,
            'userId'           => $userId,
        ]);
    }

    public function toggleActive(int $letterTemplateId, int $userId): object
    {
        return $this->store->toggleLetterTemplateActive($letterTemplateId, $userId);
    }
}
