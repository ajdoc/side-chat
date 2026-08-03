<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of "what the bot did". See the bot_audit_log migration for why successes are
 * recorded too.
 */
class BotAuditLog extends Model
{
    protected $table = 'bot_audit_log';

    protected $fillable = [
        'server_id', 'automation_id', 'action', 'outcome', 'subject_id', 'context', 'message',
    ];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    public const OK = 'ok';

    public const FAILED = 'failed';

    /** Nothing was wrong; there was simply nothing to do. Not a failure — see the migration. */
    public const SKIPPED = 'skipped';

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    /** The member it happened to, if it happened to anyone. */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_id');
    }
}
