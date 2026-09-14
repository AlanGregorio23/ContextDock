<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $guarded = [];
    protected $hidden = [];
    protected function casts(): array { return []; }
    public function project() { return $this->belongsTo(Project::class); }
    public function messages() { return $this->hasMany(Message::class); }
}

