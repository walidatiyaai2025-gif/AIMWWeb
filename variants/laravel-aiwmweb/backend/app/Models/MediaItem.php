<?php

namespace App\Models;

class MediaItem extends ContentDomainModel
{
    protected function casts(): array { return ['metadata'=>'array','remote_modified_at'=>'immutable_datetime','synced_at'=>'immutable_datetime']; }
}
