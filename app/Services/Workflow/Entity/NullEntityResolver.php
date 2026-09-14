<?php

namespace App\Services\Workflow\Entity;

/**
 * Resolver پیش‌فرضِ فاز ۱: موتور را به هیچ ماژولی گره نمی‌زند.
 *
 * - exists() همیشه true است (موتور به کدِ فراخوان اعتماد می‌کند که EntityID معتبر می‌فرستد).
 * - بقیهٔ متدها null برمی‌گردانند؛ در نتیجه AssigneeType هایی مثل ENTITY_OWNER
 *   بدونِ context صریح، هیچ کاربری برنمی‌گردانند.
 *
 * هر ماژول می‌تواند در config/workflow.php یک Resolver اختصاصی جایگزین کند.
 */
class NullEntityResolver implements EntityResolver
{
    public function exists(string $entityType, int $entityId): bool
    {
        return $entityId > 0;
    }

    public function title(string $entityType, int $entityId): ?string
    {
        return null;
    }

    public function ownerUserId(string $entityType, int $entityId): ?int
    {
        return null;
    }

    public function unitId(string $entityType, int $entityId): ?int
    {
        return null;
    }
}
