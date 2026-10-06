<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Task extends Model
{
    protected $fillable = [
        'title',
        'description',
        'status',
        'deadline',
        'deadline_status',
    ];

    protected $casts = [
        'deadline' => 'datetime',
    ];

    public function overdueLog(): HasOne
    {
        return $this->hasOne(TaskOverdueLog::class);
    }
}
