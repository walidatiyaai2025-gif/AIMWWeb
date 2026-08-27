<?php

namespace App\Models;

class ContentConflict extends ContentDomainModel
{
    protected function casts(): array { return ['local_snapshot'=>'array','remote_snapshot'=>'array','expected_modified_at'=>'immutable_datetime','remote_modified_at'=>'immutable_datetime','resolved_at'=>'immutable_datetime']; }
}
