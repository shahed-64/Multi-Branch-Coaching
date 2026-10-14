<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Result extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'student_session_id',
        'exam_year',
        'exam_type',
        'branch_id',
        'class_name_snapshot',
        'section_name_snapshot',
        'shift_name_snapshot',
        'class_group_name_snapshot',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function studentSession(): BelongsTo
    {
        return $this->belongsTo(StudentSession::class);
    }

    public function resultSubjects(): HasMany
    {
        return $this->hasMany(ResultSubject::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
