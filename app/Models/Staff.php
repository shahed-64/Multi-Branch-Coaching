<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class Staff extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'staff';

    protected $fillable = [
        'name',
        'user_name',
        'skill',
        'role',
        'email',
        'password',
        'image',
        'salary',
        'shift_id',
        'branch_id',
    ];

    protected $hidden = [
        'password'
    ];

    protected $appends = ['image_url'];

    public function getImageUrlAttribute()
    {
        return $this->image ? asset('storage/' . $this->image) : null;
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function attendances()
    {
        return $this->hasMany(TeachersAttendance::class);
    }
}
