<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['configuration_key', 'site_id', 'transport', 'host', 'port', 'encryption', 'username', 'from_address', 'from_name', 'reply_to', 'enabled', 'timeout_seconds', 'max_attempts', 'settings'])]
class MailConfiguration extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'settings' => 'array'];
    }
}
