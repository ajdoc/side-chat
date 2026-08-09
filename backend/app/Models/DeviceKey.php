<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One device's published public keys.
 *
 * The server's whole role in the encryption is this model and the two beside it: hold public
 * material, hand it to people who are allowed to ask, and never be in a position to read
 * anything. Nothing on this record is secret, and that is deliberate — see the migration.
 */
class DeviceKey extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceKeyFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'device_id', 'identity_public', 'signing_public',
        'signed_prekey', 'prekey_signature', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function oneTimePrekeys(): HasMany
    {
        return $this->hasMany(OneTimePrekey::class);
    }

    /** Sender keys addressed *to* this device — what it needs to read a channel. */
    public function inboundSenderKeys(): HasMany
    {
        return $this->hasMany(SenderKey::class, 'recipient_device_id');
    }

    /**
     * Claim one single-use prekey, or return null if the stock has run dry.
     *
     * Deleted as it is handed out, in one statement, because a prekey given to two people is
     * a prekey that no longer does its job. `lockForUpdate` is what makes that true under
     * concurrency: two requests arriving together for the last prekey must not both get it,
     * and a plain read-then-delete would let them.
     *
     * Running out is a normal outcome and returns null rather than throwing. The caller falls
     * back to a session without one — weaker against a future device compromise, fine against
     * everything else, and far better than refusing to start the conversation.
     */
    public function claimPrekey(): ?OneTimePrekey
    {
        return $this->getConnection()->transaction(function () {
            $prekey = $this->oneTimePrekeys()->lockForUpdate()->oldest('id')->first();

            $prekey?->delete();

            return $prekey;
        });
    }
}
