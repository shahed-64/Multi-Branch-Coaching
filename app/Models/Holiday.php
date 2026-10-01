<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use HasFactory;

    protected $table = 'holidays';

    protected $fillable = [
        'branch_id',
        'title',
        'start_date',
        'end_date',
        'description',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
