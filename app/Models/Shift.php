<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Shift extends Model
{
    protected $fillable = [
        'name',
        'start_time',
        'branch_id',
    ];

    // Branch relation
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    // Teacher relation
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(
            Teacher::class,
            'teacher_shift'
        );
    }

    // Staff relation
    public function staffs()
    {
        return $this->hasMany(Staff::class);
    }

    // Student relation
    public function students()
    {
        return $this->hasMany(Student::class);
    }
}
