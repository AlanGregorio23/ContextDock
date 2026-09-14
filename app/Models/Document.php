<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $guarded = [];
    protected $hidden = [];
    protected function casts(): array { return []; }
    public function chunks() { return $this->hasMany(DocumentChunk::class); }
    public function project() { return $this->belongsTo(Project::class); }
}

