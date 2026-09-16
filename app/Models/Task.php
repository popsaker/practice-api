<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
