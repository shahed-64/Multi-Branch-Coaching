<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'student_id',
        'student_session_id',
        'amount',
        'paid_amount',
        'due_amount',
        'payment_method',
        'payment_date',
        'month',
        'admission_fee',
        'exam_fee',
        'status',
        'due_month',
        'due_payment_amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'due_amount' => 'decimal:2',
        'admission_fee' => 'decimal:2',
        'exam_fee' => 'decimal:2',
        'due_payment_amount' => 'decimal:2',
        'payment_date' => 'date:Y-m-d',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function studentSession(): BelongsTo
    {
        return $this->belongsTo(
            StudentSession::class,
            'student_session_id'
        );
    }
}
