<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffAttendance extends Model
{
    use HasFactory;

    protected $table = 'staff_attendances';

   protected $fillable = [
    'staff_id',
    'branch_id',
    'date',
    'status',
    'in_time',
    'out_time',
    'note',
    'shift_name',
    'leave',
];

    protected $casts = [
        'leave' => 'boolean',
        'date' => 'date',
    ];

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class, 'shift_name', 'name');
    }

    public function holiday()
    {
        return $this->hasOne(Holiday::class, 'start_date', 'date');
    }
}
