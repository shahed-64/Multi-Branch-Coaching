<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $fillable = [
        'name',
        'code',
        'address',
        'phone',
        'email',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];
}
