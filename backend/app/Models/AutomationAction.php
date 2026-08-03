<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a rule. `type` names a handler in the action registry; `config` is whatever
 * that handler asked for in its schema.
 */
class AutomationAction extends Model
{
    protected $fillable = ['automation_id', 'type', 'config', 'position'];

    protected function casts(): array
    {
        return ['config' => 'array'];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}
