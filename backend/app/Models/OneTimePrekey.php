<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A public key meant to be used once and destroyed.
 *
 * There is deliberately no "used" flag. A consumed prekey is a deleted row, because a flag
 * is a thing that can be read wrong, reset, or missed by a query — and the cost of handing
 * the same prekey out twice is the loss of the forward secrecy it exists to provide. Gone is
 * unambiguous. See {@see DeviceKey::claimPrekey()}.
 */
class OneTimePrekey extends Model
{
    /** @use HasFactory<\Database\Factories\OneTimePrekeyFactory> */
    use HasFactory;

    protected $fillable = ['device_key_id', 'prekey_id', 'public_key'];

    public function deviceKey(): BelongsTo
    {
        return $this->belongsTo(DeviceKey::class);
    }
}
