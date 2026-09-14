<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Workspace extends Model
{
    protected $guarded = [];
    protected $hidden = [];
    protected function casts(): array { return []; }
    public function projects() { return $this->hasMany(Project::class); }
}

