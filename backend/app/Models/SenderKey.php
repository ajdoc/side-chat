<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sender's chain key, sealed for exactly one recipient device.
 *
 * Opaque to everything on this side of the wire. The server stores it, indexes it by who may
 * fetch it, and hands it over — it cannot open it, and no code here should ever look like it
 * is trying to.
 */
class SenderKey extends Model
{
    /** @use HasFactory<\Database\Factories\SenderKeyFactory> */
    use HasFactory;

    protected $fillable = [
        'channel_id', 'epoch', 'sender_device_id', 'recipient_device_id',
        'wrapped_key', 'wrap_iv', 'ephemeral_public', 'prekey_id',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function senderDevice(): BelongsTo
    {
        return $this->belongsTo(DeviceKey::class, 'sender_device_id');
    }

    public function recipientDevice(): BelongsTo
    {
        return $this->belongsTo(DeviceKey::class, 'recipient_device_id');
    }
}
