<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * کاربرِ تازه بدونِ هیچ نقش/دسترسی — داخلِ همان Transactionِ آزمون (DatabaseTransactions) ساخته
 * و با Rollback حذف می‌شود. آزمون‌هایِ «بدونِ دسترسی → 403» به این کاربر تکیه می‌کنند، نه به
 * یک کاربرِ واقعیِ ثابت (مثلاً UserID = 3) که ممکن است در دادهٔ واقعی دسترسی گرفته باشد.
 */
trait CreatesPermissionlessUser
{
    private ?int $permissionlessUserId = null;

    protected function permissionlessUser(): int
    {
        if ($this->permissionlessUserId === null) {
            $code = 'NOPERM_' . strtoupper(bin2hex(random_bytes(6)));
            DB::insert(
                'INSERT INTO dbo.Users (UserCode, UserName, PasswordHash, FirstName, LastName, IsActive, CreateDate, RowGuid, IsLocked, FailedLoginCount) '
                . "VALUES (?, ?, N'x', N'بدونِ', N'دسترسی', 1, GETDATE(), NEWID(), 0, 0)",
                [$code, strtolower($code)]
            );
            $this->permissionlessUserId = (int) DB::table('Users')->where('UserCode', $code)->value('UserID');
        }

        return $this->permissionlessUserId;
    }

    /**
     * فقط همین دسترسی‌ها را (از طریقِ یک نقشِ موقتِ تازه، داخلِ همان Transaction) به کاربر می‌دهد —
     * برایِ آزمون‌هایی که «فقط دسترسیِ X، نه Y» را می‌سنجند.
     */
    protected function grantPermissionsTo(int $userId, array $permissionCodes): void
    {
        $code = 'TMPROLE_' . strtoupper(bin2hex(random_bytes(6)));
        DB::insert('INSERT INTO dbo.Roles (RoleCode, RoleName) VALUES (?, ?)', [$code, 'نقشِ موقتِ آزمون ' . $code]);
        $roleId = (int) DB::table('Roles')->where('RoleCode', $code)->value('RoleID');
        foreach (DB::table('Permissions')->whereIn('PermissionCode', $permissionCodes)->pluck('PermissionID') as $permissionId) {
            DB::insert('INSERT INTO dbo.RolePermissions (RoleID, PermissionID, CanAccess, IsActive) VALUES (?, ?, 1, 1)', [$roleId, $permissionId]);
        }
        DB::insert('INSERT INTO dbo.UserRoles (UserID, RoleID, IsActive) VALUES (?, ?, 1)', [$userId, $roleId]);
    }
}
