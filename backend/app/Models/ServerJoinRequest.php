<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pending request to join a server, created when someone opens an invite link for a
 * server they are not a member of. Approving it makes them a member; declining simply
 * deletes it.
 */
class ServerJoinRequest extends Model
{
    /** @use HasFactory<\Database\Factories\ServerJoinRequestFactory> */
    use HasFactory;

    protected $fillable = ['server_id', 'user_id'];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
