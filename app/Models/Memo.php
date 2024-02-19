<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Memo extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'memo',
        'note_today',
        'memo_date',
    ];

    public function users()
    {
        return $this->belongsTo(User::class, 'user_id', 'telegram_chat_id');
    }
}
