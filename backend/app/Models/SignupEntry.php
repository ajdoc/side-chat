<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One filled slot on a sheet: which column, which row, and who. */
class SignupEntry extends Model
{
    protected $fillable = ['sheet_id', 'column_key', 'position', 'person_id', 'note', 'added_by'];

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(SignupSheet::class, 'sheet_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(SignupPerson::class, 'person_id');
    }
}
