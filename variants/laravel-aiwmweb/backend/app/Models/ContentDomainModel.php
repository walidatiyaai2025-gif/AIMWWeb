<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

abstract class ContentDomainModel extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];
}
