<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeachersAttendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'teacher_id',
        'shift_id',
        'branch_id',
        'date',
        'status',
        'in_time',
        'out_time',
        'note',
        'shift_name',
        'leave',
    ];

    // 🔗 Inverse Relationship (Belongs To Teacher)
    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    // 🔗 Shift Relationship
    public function shift()
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }
    public function holiday()
{
    // যদি আপনার ডেট কলামের সাথে মিল রেখে হলিডে চেক করতে চান
    return $this->hasOne(Holiday::class, 'start_date', 'date'); // অথবা লজিক অনুযায়ী
}
}
