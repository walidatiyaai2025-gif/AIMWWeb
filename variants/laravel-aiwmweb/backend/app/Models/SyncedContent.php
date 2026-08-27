<?php

namespace App\Models;

class SyncedContent extends DomainModel
{
    protected function casts(): array
    {
        return ['headings' => 'array', 'taxonomy' => 'array', 'media' => 'array', 'remote_modified_at' => 'datetime'];
    }
}
