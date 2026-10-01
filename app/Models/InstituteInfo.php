<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstituteInfo extends Model
{
    protected $table = 'institute_infos';

    protected $fillable = [
        'branch_id',
        'institute_name',
        'established_year',
        'location',
        'contact',
        'logo',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
