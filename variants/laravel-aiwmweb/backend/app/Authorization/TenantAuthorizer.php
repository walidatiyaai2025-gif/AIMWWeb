<?php

namespace App\Authorization;

use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

final class TenantAuthorizer
{
    public function __construct(private readonly TenantContext $context) {}

    public function authorize(string $permission): void
    {
        if (! $this->context->membership()->hasPermission($permission)) {
            throw new AuthorizationException;
        }
    }
}
