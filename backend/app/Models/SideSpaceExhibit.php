<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * The picture hanging in one of a map's frames, and the card beside it.
 *
 * The half of an exhibit that isn't geometry — see the migration for why the two are split. The
 * rectangle lives in the map document where any member may move it; the image and its label live
 * here, where only staff may put them.
 */
class SideSpaceExhibit extends Model
{
    protected $fillable = [
        'side_space_map_id',
        'exhibit_id',
        'title',
        'artist',
        'caption',
        'disk',
        'path',
        'mime_type',
        'size',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /**
     * How long a picture's URL is good for.
     *
     * Long, because unlike a download this is *displayed* — the map is read when you walk into
     * the room and not again until it changes, so a URL that expired in half an hour would leave
     * a gallery of broken frames for anybody who stayed a while. Six hours matches what an
     * attachment gets for the same reason.
     */
    private const URL_TTL_HOURS = 6;

    public function map(): BelongsTo
    {
        return $this->belongsTo(SideSpaceMap::class, 'side_space_map_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Where the browser fetches the picture.
     *
     * A signed, expiring route rather than a public path: a museum can be a private channel, and
     * an image served from a guessable URL would be readable by anybody who guessed it however
     * shut the room was. The signature is the grant, so an `<img>` needs no auth header.
     */
    public function url(): string
    {
        return URL::temporarySignedRoute(
            'space-exhibits.show',
            now()->addHours(self::URL_TTL_HOURS),
            ['exhibit' => $this->id],
        );
    }
}
