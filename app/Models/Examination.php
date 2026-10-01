<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Examination extends Model
{
    protected $fillable = [
        'examination_type',
        'examination_year',
        'exam_mark',
        'branch_id',
    ];

    public function examination()
    {
        return $this->hasMany(Result::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
