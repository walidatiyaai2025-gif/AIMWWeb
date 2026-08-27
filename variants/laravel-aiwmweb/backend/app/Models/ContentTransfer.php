<?php

namespace App\Models;

class ContentTransfer extends ContentDomainModel
{
    protected function casts(): array { return ['options'=>'array','result'=>'array','started_at'=>'immutable_datetime','completed_at'=>'immutable_datetime']; }
}
