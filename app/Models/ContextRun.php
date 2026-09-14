<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContextRun extends Model
{
    protected $guarded = [];
    protected $hidden = [];
    protected function casts(): array { return ['pack'=>'array', 'debug'=>'array']; }
    
}

