<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An account's escrowed sender chains.
 *
 * Deliberately featureless. There is nothing to compute here — the blob is opaque, the KDF
 * fields are a note the client wrote to its future self, and any method on this model that
 * looked inside would mean the encryption had stopped being end-to-end.
 */
class KeyBackup extends Model
{
    /** @use HasFactory<\Database\Factories\KeyBackupFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'blob', 'kdf', 'iterations'];

    protected function casts(): array
    {
        return ['iterations' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
