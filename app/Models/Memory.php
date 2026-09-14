<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Memory extends Model
{
    protected $guarded = [];
    protected $hidden = ['embedding', 'content_hash'];
    protected function casts(): array { return ['metadata'=>'array', 'is_pinned'=>'boolean', 'importance'=>'float', 'confidence'=>'float', 'expires_at'=>'datetime', 'last_used_at'=>'datetime']; }
    public function project() { return $this->belongsTo(Project::class); }
    public function workspace() { return $this->belongsTo(Workspace::class); }
}

