<?php

namespace App\Models;

class Comment extends ContentDomainModel
{
    protected $table = 'content_comments';
    protected function casts(): array { return ['metadata'=>'array','remote_created_at'=>'immutable_datetime','remote_modified_at'=>'immutable_datetime','synced_at'=>'immutable_datetime']; }
}
