<?php

namespace App\Http\Requests\SideSpace;

use App\Http\Requests\ServerStaffRequest;

/**
 * Hanging a picture in one of a map's frames. Staff only.
 *
 * The one part of building a Side Space room that is *not* open to every member, and the reason
 * is that it isn't building — it's curating. A frame is geometry anybody may draw; what goes in
 * it is a file uploaded to the server and shown to everybody who walks past, which is the same
 * kind of act as setting the server's icon rather than the same kind as painting a wall.
 *
 * It is also the only place in this feature where bytes are accepted at all, which is the other
 * half of the answer: an open upload endpoint is an open file host.
 */
class StoreSpaceExhibitRequest extends ServerStaffRequest
{
    /**
     * How large a single artwork may be.
     *
     * Generous by this app's standards, deliberately. The whole point of an exhibit is that the
     * few dozen pixels painted on the wall are replaced by something worth looking at, and a
     * scan of a painting at a size that rewards zooming in is several megabytes. It is fetched
     * only when somebody opens it — never with the map — so the cost falls on the one person
     * looking rather than on everybody in the room.
     */
    public const MAX_KILOBYTES = 12288;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
             * An image, and the server's own opinion of what an image is.
             *
             * `image` checks the *contents* rather than the name or the type the browser claimed,
             * which is what stops this being a way to store arbitrary bytes under a filename that
             * ends in .png. The explicit list narrows it further to formats every browser can
             * render, since the whole feature is "it opens and you look at it".
             */
            'image' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp,gif,avif',
                'max:'.self::MAX_KILOBYTES,
            ],
            'title' => ['required', 'string', 'max:120'],
            'artist' => ['sometimes', 'nullable', 'string', 'max:120'],
            // The wall label. Long-form, because the interesting part of a museum is the card
            // beside the painting rather than the painting's name.
            'caption' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
