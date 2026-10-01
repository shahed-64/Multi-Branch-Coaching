<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ClassGroup extends Model
{
    protected $fillable = [
        'group_name',
        'branch_id',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(
            Subject::class,
            'group_subjects',
            'class_group_id',
            'subject_id'
        );
    }

    public function groupSubjectMappings()
    {
        return $this->hasMany(
            GroupSubjectMapping::class,
            'class_group_id'
        );
    }
}
