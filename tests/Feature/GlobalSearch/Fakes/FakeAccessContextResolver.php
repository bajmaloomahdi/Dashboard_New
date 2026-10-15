<?php

namespace Tests\Feature\GlobalSearch\Fakes;

use App\Services\GlobalSearch\AccessContext;
use App\Services\GlobalSearch\Contracts\AccessContextResolver;
use Illuminate\Contracts\Auth\Authenticatable;

/** جایگزینِ ساختگیِ sp_GetUserPermissions — هیچ دسترسی به دیتابیس ندارد. */
class FakeAccessContextResolver implements AccessContextResolver
{
    /** @param string[] $permissions */
    public function __construct(
        public bool $isActive = true,
        public array $permissions = [],
        public int $calls = 0,
    ) {
    }

    public function resolve(Authenticatable $user): AccessContext
    {
        $this->calls++;

        return new AccessContext((int) $user->getAuthIdentifier(), $this->isActive, $this->isActive ? $this->permissions : []);
    }
}
