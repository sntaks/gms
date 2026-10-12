<?php

namespace App\Models;

use App\Enums\PayoutStatus;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Payout extends Model
{
    use Auditable;

    protected $table = 'payouts';

    protected $guarded = [];

    protected $casts = [
        'status' => PayoutStatus::class,
        'created_by' => 'integer',
        'processed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Payout $payout) {
            if (empty($payout->idempotency_key)) {
                $payout->idempotency_key = Str::uuid()->toString();
            }

            if (empty($payout->created_by)) {
                $payout->created_by = auth()->id();
            }
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payee(): BelongsTo
    {
        return $this->belongsTo(Payee::class, 'payee_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function declinedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declined_by');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'expense_id');
    }
}
