<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One fee one person owes for one sheet. See SignupCharges for how these are kept in step. */
class SignupCharge extends Model
{
    protected $fillable = [
        'channel_id', 'sheet_id', 'person_id', 'fee_key', 'label', 'amount', 'billed_on',
        'paid_at', 'paid_marked_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'billed_on' => 'date', 'paid_at' => 'datetime'];
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(SignupSheet::class, 'sheet_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(SignupPerson::class, 'person_id');
    }
}
