<?php

namespace App\Services\Workflow;

use App\Services\Workflow\Entity\EntityResolverRegistry;
use App\Services\Workflow\Exceptions\WorkflowValidationException;
use App\Services\Workflow\Support\WorkflowStore;
use Illuminate\Support\Facades\DB;

/**
 * مدیریتِ تعریفِ فرایندها و نسخه‌ها: CRUD، ساختِ Draft، Clone، اعتبارسنجی و انتشار.
 *
 * منطقِ کسب‌وکار (اعتبارسنجیِ گراف) در PHP است؛ ذخیره/انتشارِ اتمیک در رویه.
 */
class WorkflowDefinitionService
{
    private const CODE_PATTERN = '/^[A-Z][A-Z0-9_]{1,49}$/';

    /** سقفِ تلاش برایِ یافتنِ یک Codeِ یکتا با پسوندِ عددی، قبل از خطایِ صریح. */
    private const MAX_UNIQUE_SUFFIX_ATTEMPTS = 50;

    public function __construct(
        private WorkflowStore $store,
        private EntityResolverRegistry $entities,
        private ConditionRuleValidator $ruleValidator,
        private ConditionFieldService $conditionFields,
    ) {
    }

    public function list(?string $search = null, ?bool $isActive = null, ?int $categoryId = null): array
    {
        return $this->store->getDefinitions($search, $isActive, $categoryId);
    }

    public function show(int $definitionId): array
    {
        return $this->store->getDefinition($definitionId);
    }

    /**
     * تولیدِ خودکارِ Code — کاربر هرگز Code را مستقیماً وارد نمی‌کند (UI فیلدِ Code را
     * Read-only نگه می‌دارد). این متد تنها منبعِ حقیقتِ Code است؛ هر مقداری که کلاینت
     * برایِ «code» بفرستد نادیده گرفته می‌شود — دقیقاً هم‌الگو با TemplateParameterService/
     * ConditionFieldService::save().
     *
     * @throws WorkflowValidationException اگر نام/نامِ لاتین برایِ ساختِ یک Codeِ معتبر کافی نباشد،
     *         یا EntityType نامعتبر باشد، یا فرایند در ویرایش یافت نشود
     */
    public function save(array $input, int $userId): object
    {
        $entityType = $input['entityType'] ?? '';
        if (! $this->isEntityTypeUsable($entityType)) {
            throw new WorkflowValidationException("نوعِ موجودیتِ «{$entityType}» در Registry فعال/شناخته‌شده نیست.");
        }

        $name = trim($input['name'] ?? '');
        $definitionId = isset($input['definitionId']) && $input['definitionId'] !== null ? (int) $input['definitionId'] : null;

        if ($definitionId !== null) {
            // ویرایش: Code هرگز تغییر نمی‌کند — حتی اگر نام عوض شود یا کلاینت مقدارِ
            // دیگری برایِ code بفرستد؛ تنها منبعِ حقیقت همان ردیفِ موجود در DB است.
            $existing = $this->store->getDefinition($definitionId)['definition'];
            if ($existing === null) {
                throw new WorkflowValidationException('فرایند یافت نشد.');
            }
            $code = $existing->Code;
        } else {
            $code = $this->generateUniqueCode($name, isset($input['latinName']) ? trim((string) $input['latinName']) : '');
        }

        $result = $this->store->saveDefinition([
            'definitionId' => $definitionId,
            'code'         => $code,
            'name'         => $name,
            'description'  => $input['description'] ?? null,
            'entityType'   => $entityType,
            'isActive'     => (int) ($input['isActive'] ?? 1),
            'categoryId'   => $input['categoryId'] ?? null,
            'userId'       => $userId,
        ]);
        $result->Code = $code;

        return $result;
    }

    /**
     * Codeِ پایه را ابتدا از نام می‌سازد؛ اگر نام حرفِ لاتینِ کافی نداشت، از
     * latinName استفاده می‌کند. سپس با retry عددی (LEAVE_REQUEST_2, ...) یکتا می‌شود.
     *
     * @throws WorkflowValidationException
     */
    private function generateUniqueCode(string $name, string $latinName): string
    {
        if ($name === '') {
            throw new WorkflowValidationException('نام الزامی است.');
        }

        $base = $this->baseCodeFromText($name);
        if (! preg_match(self::CODE_PATTERN, $base)) {
            if ($latinName === '') {
                throw new WorkflowValidationException('نام شامل حروفِ لاتینِ کافی نیست — یک «نامِ لاتین» کوتاه وارد کنید.');
            }
            $base = $this->baseCodeFromText($latinName);
            if (! preg_match(self::CODE_PATTERN, $base)) {
                throw new WorkflowValidationException('نامِ لاتین نامعتبر است — باید با یک حرفِ لاتین شروع شود و فقط شاملِ حروفِ لاتین/عدد/آندرلاین باشد.');
            }
        }

        $code = $base;
        $suffix = 2;
        while ($this->store->getDefinitionByCode($code) !== null) {
            if ($suffix > self::MAX_UNIQUE_SUFFIX_ATTEMPTS) {
                throw new WorkflowValidationException('امکانِ ساختِ یک Codeِ یکتا از این نام/نامِ لاتین وجود ندارد — لطفاً مقدارِ دیگری انتخاب کنید.');
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

    /* ---------- دسته‌بندیِ فرایندها ---------- */

    public function listCategories(?string $search = null, ?bool $isActive = null): array
    {
        return $this->store->getCategories($search, $isActive);
    }

    public function saveCategory(array $input, int $userId): object
    {
        return $this->store->saveCategory([
            'categoryId'  => $input['categoryId'] ?? null,
            'code'        => trim($input['code'] ?? ''),
            'name'        => trim($input['name'] ?? ''),
            'description' => $input['description'] ?? null,
            'sortOrder'   => $input['sortOrder'] ?? 0,
            'userId'      => $userId,
        ]);
    }

    public function toggleCategoryActive(int $categoryId, int $userId): object
    {
        return $this->store->toggleCategoryActive($categoryId, $userId);
    }

    /* ---------- Registryِ نوعِ موجودیت‌ها (WorkflowEntityTypes) ----------
     *
     * تفکیکِ عمدی: این Registry «Business/UI Registry» است (Code/DisplayName/
     * IsActive که در UI انتخاب می‌شود)؛ config('workflow.entities') همچنان
     * تنها منبعِ «Technical Resolver Mapping» است (کدامResolverِ PHP واقعاً
     * پشتِ این Code قرار دارد). این دو عمداً به هم Hard-wire نشده‌اند:
     * ثبتِ یک ردیفِ Registry هرگز نیازمندِ وجودِ از‌پیشِ آن Code در Config
     * نیست (کاربر می‌تواند موجودیتی را در Registry تعریف کند که Resolverِ
     * واقعی هنوز برایش نوشته نشده) — اما isEntityTypeUsable() (تنها گیت‌وی
     * برایِ «آیا این EntityType در Workflowِ جدید قابلِ‌استفاده است؟») هر دو
     * منبع را با هم چک می‌کند؛ بدونِ Resolverِ واقعی در Config، هرگز Usable
     * نمی‌شود — صرفِ فعال‌کردنِ IsActive در Registry کافی نیست.
     */

    public function listEntityTypes(?string $search = null, ?bool $isActive = null): array
    {
        return $this->store->getEntityTypes($search, $isActive);
    }

    public function saveEntityType(array $input, int $userId): object
    {
        return $this->store->saveEntityType([
            'entityTypeId'  => $input['entityTypeId'] ?? null,
            'code'          => strtoupper(trim($input['code'] ?? '')),
            'displayName'   => trim($input['displayName'] ?? ''),
            'resolverClass' => $input['resolverClass'] ?? null,
            'sortOrder'     => $input['sortOrder'] ?? 0,
            'userId'        => $userId,
        ]);
    }

    public function toggleEntityTypeActive(int $entityTypeId, int $userId): object
    {
        return $this->store->toggleEntityTypeActive($entityTypeId, $userId);
    }

    /**
     * تنها گیت‌وی برایِ «آیا این EntityType همین حالا در یک Workflowِ جدید
     * قابلِ‌استفاده است؟». دو شرط، هر دو لازم:
     *   ۱) در config('workflow.entities') یک Resolverِ واقعی برایش وصل باشد
     *      (بدونِ آن، فعال‌کردنِ صرفِ IsActive در Registry معنایی ندارد).
     *   ۲) اگر ردیفی در Registry برایِ این Code ثبت شده، IsActive آن true باشد.
     *      اگر اصلاً ردیفی ثبت نشده (مثلِ EntityTypeِ فقط‌تستی)، این شرط را
     *      نادیده می‌گیریم — رفتارِ فعلی (پیش‌از‌Registry) حفظ می‌شود.
     */
    public function isEntityTypeUsable(string $entityType): bool
    {
        if (! $this->entities->isKnown($entityType)) {
            return false;
        }

        $row = $this->store->getEntityTypeByCode($entityType);

        return $row === null || (bool) $row->IsActive;
    }

    public function createDraft(int $definitionId, int $userId): object
    {
        return $this->store->createDraftVersion($definitionId, $userId);
    }

    public function cloneVersion(int $sourceVersionId, int $userId): object
    {
        return $this->store->cloneVersion($sourceVersionId, $userId);
    }

    public function getGraph(int $versionId): array
    {
        return [
            'meta'  => $this->store->getVersionMeta($versionId),
            'graph' => $this->store->getVersionGraph($versionId),
        ];
    }

    public function saveGraph(int $versionId, array $graph, int $userId): object
    {
        return $this->store->saveVersionGraph($versionId, $graph, $userId);
    }

    /**
     * اعتبارسنجیِ گرافِ نسخه. نتیجه در ValidationResultJson ذخیره می‌شود.
     *
     * @return array{ok:bool, errors:string[], warnings:string[]}
     */
    public function validate(int $versionId, int $userId, bool $persist = true): array
    {
        $meta = $this->store->getVersionMeta($versionId);
        if (! $meta) {
            throw new WorkflowValidationException('نسخهٔ فرایند یافت نشد.');
        }

        $g = $this->store->getVersionGraph($versionId);
        $errors = [];
        $warnings = [];

        // فیلدها اکنون سراسری‌اند (Global Registry)، نه Definition-level — همان یک فهرست
        // برایِ اعتبارسنجیِ Ruleهایِ هر Definitionی استفاده می‌شود.
        $fieldsByCode = collect($this->conditionFields->list(includeInactive: true))
            ->keyBy('Code')
            ->all();

        $steps = $g['steps'];
        $byId = [];
        $starts = [];
        $ends = [];
        foreach ($steps as $s) {
            $byId[(int) $s->StepID] = $s;
            if ($s->StepType === 'START') {
                $starts[] = $s;
            }
            if ($s->StepType === 'END') {
                $ends[] = $s;
            }
        }

        if ($steps === []) {
            $errors[] = 'نسخه هیچ مرحله‌ای ندارد.';
        }
        if (count($starts) === 0) {
            $errors[] = 'مرحلهٔ شروع (START) وجود ندارد.';
        }
        if (count($starts) > 1) {
            $errors[] = 'بیش از یک مرحلهٔ شروع (START) تعریف شده است.';
        }
        if (count($ends) === 0) {
            $errors[] = 'حداقل یک مرحلهٔ پایان (END) لازم است.';
        }

        $outByStep = [];
        $inByStep = [];
        foreach ($g['transitions'] as $t) {
            $from = (int) $t->FromStepID;
            $to = (int) $t->ToStepID;
            if (! isset($byId[$from])) {
                $errors[] = "گذارِ «{$t->Code}» از مرحله‌ای خارج از این نسخه شروع می‌شود.";
            }
            if (! isset($byId[$to])) {
                $errors[] = "گذارِ «{$t->Code}» به مرحله‌ای خارج از این نسخه می‌رود.";
            }
            $outByStep[$from][] = $t;
            $inByStep[$to][] = $t;
        }

        $assignByStep = [];
        foreach ($g['assignments'] as $a) {
            $assignByStep[(int) $a->StepID][] = $a;
        }

        foreach ($steps as $s) {
            $id = (int) $s->StepID;
            $type = $s->StepType;

            if ($type !== 'END' && empty($outByStep[$id])) {
                $errors[] = "مرحلهٔ «{$s->Name}» هیچ گذارِ خروجی ندارد.";
            }
            if ($type !== 'START' && empty($inByStep[$id])) {
                $errors[] = "مرحلهٔ «{$s->Name}» از هیچ مسیری قابلِ دسترسی نیست.";
            }
            if ($type === 'START' && ! empty($inByStep[$id])) {
                $errors[] = 'مرحلهٔ شروع نباید گذارِ ورودی داشته باشد.';
            }
            if ($type === 'END' && ! empty($outByStep[$id])) {
                $errors[] = "مرحلهٔ پایانِ «{$s->Name}» نباید گذارِ خروجی داشته باشد.";
            }
            if (in_array($type, ['USER_TASK', 'APPROVAL'], true) && empty($assignByStep[$id])) {
                $errors[] = "مرحلهٔ «{$s->Name}» هیچ انجام‌دهنده‌ای ندارد.";
            }
            if (($s->AssignPolicy ?? 'ANY') === 'N_OF_M' && (int) ($s->RequiredApprovals ?? 0) < 1) {
                $errors[] = "مرحلهٔ «{$s->Name}» با سیاستِ N_OF_M نیازمندِ «تعداد تأییدِ لازم» است.";
            }
            if (in_array($type, ['AUTOMATIC', 'NOTIFICATION', 'PARALLEL_SPLIT', 'PARALLEL_JOIN', 'SUBPROCESS'], true)) {
                $warnings[] = "مرحلهٔ «{$s->Name}» از نوعِ «{$type}» است؛ اجرای این نوع در فاز ۱ پشتیبانی نمی‌شود.";
            }

            if ($type === 'CONDITION') {
                $outgoing = $outByStep[$id] ?? [];
                $defaults = array_values(array_filter($outgoing, fn ($t) => (int) $t->IsDefault === 1));
                if (count($defaults) === 0) {
                    $errors[] = "مرحلهٔ «{$s->Name}» (CONDITION) هیچ گذارِ پیش‌فرض (Else) ندارد؛ در نبودِ آن، Runtime ممکن است بن‌بست شود.";
                } elseif (count($defaults) > 1) {
                    $errors[] = "مرحلهٔ «{$s->Name}» (CONDITION) بیش از یک گذارِ پیش‌فرض دارد.";
                }
            }
        }

        // اعتبارسنجیِ RuleJson: فقط رویِ گذارهایِ خروجیِ Stepِ CONDITION مجاز است.
        foreach ($g['transitions'] as $t) {
            if (empty($t->RuleJson)) {
                continue;
            }

            if ((int) $t->IsDefault === 1) {
                $errors[] = "گذارِ «{$t->Code}» پیش‌فرض است و نباید هم‌زمان دارایِ شرط (RuleJson) باشد.";
            }

            $fromStep = $byId[(int) $t->FromStepID] ?? null;
            if (! $fromStep || $fromStep->StepType !== 'CONDITION') {
                $errors[] = "گذارِ «{$t->Code}» دارایِ Rule است ولی مبدأِ آن از نوعِ CONDITION نیست.";

                continue;
            }

            $rule = json_decode((string) $t->RuleJson, true);
            if (! is_array($rule)) {
                $errors[] = "گذارِ «{$t->Code}»: RuleJson قابلِ‌خواندن نیست.";

                continue;
            }

            foreach ($this->ruleValidator->validate($rule, $fieldsByCode) as $ruleError) {
                $errors[] = "گذارِ «{$t->Code}»: {$ruleError}";
            }
        }

        $result = [
            'ok'         => $errors === [],
            'errors'     => $errors,
            'warnings'   => $warnings,
            'checkedAt'  => now()->toIso8601String(),
            'checkedBy'  => $userId,
        ];

        if ($persist) {
            $this->store->setValidationResult($versionId, $result, $userId);
        }

        return $result;
    }

    /**
     * انتشارِ نسخه: اعتبارسنجی → ذخیرهٔ نتیجه → فعال‌سازیِ اتمیک (بایگانیِ نسخهٔ ACTIVE قبلی).
     */
    public function publish(int $versionId, int $userId): object
    {
        return DB::transaction(function () use ($versionId, $userId) {
            $validation = $this->validate($versionId, $userId, persist: true);

            if (! $validation['ok']) {
                throw new WorkflowValidationException(
                    'نسخه به دلیلِ خطاهای اعتبارسنجی قابلِ انتشار نیست.',
                    $validation['errors']
                );
            }

            return $this->store->publishVersion($versionId, $userId);
        });
    }
}
