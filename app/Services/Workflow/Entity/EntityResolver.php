<?php

namespace App\Services\Workflow\Entity;

/**
 * قراردادِ اتصالِ موتورِ فرایند به یک ماژولِ کسب‌وکار.
 *
 * موتور هرگز مستقیماً جدولِ ماژول‌ها را نمی‌خواند؛ فقط از این قرارداد استفاده
 * می‌کند. در فاز ۱ نگاشتِ EntityType → Resolver در config/workflow.php است؛
 * در فازهای بعد به جدولِ WorkflowEntityProviders منتقل می‌شود بدونِ تغییرِ این
 * قرارداد.
 */
interface EntityResolver
{
    /**
     * آیا این موجودیت وجود دارد و شروعِ فرایند روی آن مجاز است؟
     */
    public function exists(string $entityType, int $entityId): bool;

    /**
     * عنوانِ نمایشیِ موجودیت برای درج در عنوانِ تسک‌ها/تاریخچه.
     */
    public function title(string $entityType, int $entityId): ?string;

    /**
     * شناسهٔ کاربرِ مالکِ موجودیت (برای AssigneeType = ENTITY_OWNER). null اگر نامشخص.
     */
    public function ownerUserId(string $entityType, int $entityId): ?int;

    /**
     * شناسهٔ واحدِ سازمانیِ مرتبط با موجودیت (برای AssigneeType = UNIT_MANAGER بدونِ RefID).
     */
    public function unitId(string $entityType, int $entityId): ?int;
}
