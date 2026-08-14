<?php

namespace App\Models;

use App\Support\Apps\AppRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The app a channel is, when its type is `app`.
 *
 * The sibling of {@see SideSpaceMap}: one row per channel, saying what fills the space where a
 * timeline would otherwise be. Everything specific to the app itself lives elsewhere — the
 * tracker's projects in their own tables, a widget app's state on the {@see Widget} — so this
 * model stays a pointer plus its settings.
 */
class ChannelApp extends Model
{
    protected $fillable = ['channel_id', 'app_id', 'config', 'installed_by'];

    protected function casts(): array
    {
        return ['config' => 'array'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** Whoever picked this app when the channel was made. Null once that account is gone. */
    public function installer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    /** Does this app render the channel's widget rather than storage of its own? */
    public function isWidget(): bool
    {
        return AppRegistry::isWidget($this->app_id);
    }
}
