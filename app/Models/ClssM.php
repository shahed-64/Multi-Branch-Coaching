<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Models\Branch;

class ClssM extends Model
{
    protected $table = 'clss_m_s';

    protected $guarded = [];

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'class_id');
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(
            Subject::class,
            'class_subject',
            'class_id',
            'subject_id'
        );
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
