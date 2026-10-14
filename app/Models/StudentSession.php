<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentSession extends Model
{
    protected $fillable = [
        'student_id',
        'academic_session_id',
        'branch_id',
        'class_id',
        'section_id',
        'shift_id',
        'class_group_id',
        'monthly_fee',
        'status',
    ];

    protected $casts = [
        'monthly_fee' => 'decimal:2',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function classInfo(): BelongsTo
    {
        return $this->belongsTo(ClssM::class, 'class_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(StudentAttendance::class);
    }
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'student_session_id');
    }

}
