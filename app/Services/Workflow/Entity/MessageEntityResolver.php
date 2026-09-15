<?php

namespace App\Services\Workflow\Entity;

use Illuminate\Support\Facades\DB;

/**
 * پیاده‌سازیِ واقعیِ EntityResolver برایِ EntityType=MESSAGE (فاز ۳ — P0).
 *
 * فقط ۴ متدِ قراردادِ فعلی؛ بدونِ هیچ متدِ اضافه یا Lookupِ پویا (طبقِ تصمیمِ
 * صریحِ Phase 3: نه fieldValue()، نه ENTITY_FIELD).
 */
class MessageEntityResolver implements EntityResolver
{
    public function exists(string $entityType, int $entityId): bool
    {
        $row = DB::selectOne('SELECT 1 AS X FROM dbo.Messages WHERE MessageID = ?', [$entityId]);

        return $row !== null;
    }

    public function title(string $entityType, int $entityId): ?string
    {
        $row = DB::selectOne('SELECT Subject FROM dbo.Messages WHERE MessageID = ?', [$entityId]);

        return $row->Subject ?? null;
    }

    public function ownerUserId(string $entityType, int $entityId): ?int
    {
        $row = DB::selectOne('SELECT SenderUserID FROM dbo.Messages WHERE MessageID = ?', [$entityId]);

        return $row ? (int) $row->SenderUserID : null;
    }

    /**
     * واحدِ سازمانیِ فرستندهٔ پیام — آخرین سمتِ فعالِ او.
     * دقیقاً همان الگویی که dbo.sp_Wf_ResolveAssignees برایِ AssigneeType=DIRECT_MANAGER
     * جهتِ یافتنِ واحدِ آغازگر استفاده می‌کند (sql/015_workflow_phase1_procs.sql).
     */
    public function unitId(string $entityType, int $entityId): ?int
    {
        $sender = $this->ownerUserId($entityType, $entityId);
        if ($sender === null) {
            return null;
        }

        $row = DB::selectOne(
            'SELECT TOP 1 UnitID FROM dbo.UserPositions
             WHERE UserID = ? AND IsActive = 1
             ORDER BY CreateDate DESC, UserPositionID DESC',
            [$sender]
        );

        return $row ? (int) $row->UnitID : null;
    }
}
