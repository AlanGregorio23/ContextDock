<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentChunk extends Model
{
    protected $guarded = [];
    protected $hidden = ['embedding'];
    protected function casts(): array { return []; }
    public function document() { return $this->belongsTo(Document::class); }
}

