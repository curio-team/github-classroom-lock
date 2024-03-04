<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatLog extends Model
{
    protected $fillable = ['model_id', 'prompt', 'response'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
