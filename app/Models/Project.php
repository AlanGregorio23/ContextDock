<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $guarded = [];
    protected $hidden = [];
    protected function casts(): array { return ['settings'=>'array']; }
    public function workspace() { return $this->belongsTo(Workspace::class); }
    public function memories() { return $this->hasMany(Memory::class); }
}

