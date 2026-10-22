<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    protected $fillable = [
        'expense_type',
        'employee_name',
        'salary_amount',
        'paid_amount',
        'due_amount',
        'payment_method',
        'payment_date',
        'payment_month',
        'created_by',
        'branch_id',
        'academic_session_id',
        'remarks',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }
}
