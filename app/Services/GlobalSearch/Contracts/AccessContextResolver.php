<?php

namespace App\Services\GlobalSearch\Contracts;

use App\Services\GlobalSearch\AccessContext;
use Illuminate\Contracts\Auth\Authenticatable;

interface AccessContextResolver
{
    public function resolve(Authenticatable $user): AccessContext;
}
