<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $guarded = [];
    protected $hidden = [];
    protected function casts(): array { return ['metadata'=>'array']; }
    
}

