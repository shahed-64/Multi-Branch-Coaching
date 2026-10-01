<?php

namespace App\Models;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Teacher extends Model
{
    use HasFactory;

    protected $fillable = [
        'full_name',
        'designation',
        'department',
        'qualification',
        'phone',
        'email',
        'join_date',
        'salary',
        'image',
        'teacher_id',
        'branch_id',
    ];

    protected $casts = [
        'join_date' => 'date',
        'salary' => 'decimal:2',
    ];

    public function attendances()
    {
        return $this->hasMany(TeachersAttendance::class);
    }

    public function shifts(): BelongsToMany
    {
        return $this->belongsToMany(Shift::class, 'teacher_shift');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
