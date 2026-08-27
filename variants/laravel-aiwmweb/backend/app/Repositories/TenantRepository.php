<?php

namespace App\Repositories;

use Illuminate\Database\Eloquent\Model;

final class TenantRepository
{
    public function findOrFail(string $model, int|string $id): Model
    {
        return $model::query()->findOrFail($id);
    }

    public function create(string $model, array $attributes): Model
    {
        unset($attributes['tenant_id']);

        return $model::query()->create($attributes);
    }
}
