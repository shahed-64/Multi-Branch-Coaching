<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtherPayment extends Model
{
    protected $fillable = [
        'student_id',
        'student_session_id',
        'item_name',
        'quantity',
        'price',
        'total_amount',
        'payment_method',
        'payment_date',
        'remarks',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function studentSession(): BelongsTo
    {
        return $this->belongsTo(StudentSession::class);
    }
}
