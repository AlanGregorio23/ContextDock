<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $guarded = [];
    protected $hidden = [];
    protected function casts(): array { return ['metadata'=>'array']; }
    public function conversation() { return $this->belongsTo(Conversation::class); }
}

