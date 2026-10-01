<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    protected $fillable = [
        'full_name',
        'version',
        'fathers_name',
        'mothers_name',
        'student_id',
        'phone',
        'email',
        'section_id',
        'course_name',
        'class_id',
        'monthly_fee',
        'admission_date',
        'image',
        'shift_id',
        'status',
        'class_group_id',
        'branch_id',
    ];

    protected $appends = [
        'image_url',
    ];

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function results()
    {
        return $this->hasMany(Result::class);
    }

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function classInfo()
    {
        return $this->belongsTo(ClssM::class, 'class_id');
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function classGroup()
    {
        return $this->belongsTo(ClassGroup::class, 'class_group_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function getImageUrlAttribute()
    {
        return $this->image
            ? asset('storage/' . $this->image)
            : null;
    }
}
