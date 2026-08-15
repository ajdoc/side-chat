<?php

use App\Models\SideSpaceMap;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What is actually hanging in a frame — the picture, and what to say about it.
 *
 * A drawn room paints its own art. The Met's walls are covered in paintings and its halls are
 * full of statues, and every one of them is a few dozen pixels: enough to read as a gallery,
 * nowhere near enough to *look at*. So an exhibit is a place on the map you can walk up to and
 * open, and what opens is a real image somebody uploaded.
 *
 * ## Why this isn't in the map document
 *
 * The rectangle *is* — see the `exhibits` column on `side_space_maps`, which any member may draw
 * and move like a zone or a screen. What lives here is the part that must not be: a file, and the
 * words shown beside it.
 *
 * Exactly the split {@see \App\Models\SideSpaceLock} makes against a map's furniture, and for the
 * same two reasons. A map save is open to every member, so an image path stored in the document
 * would be an arbitrary string any member could point at any file. And hanging a picture in a
 * museum everybody shares is a curatorial act rather than a building one — it is staff-only,
 * which a field inside an open document could never be.
 *
 * `exhibit_id` is the id of the rectangle in the map document, not a foreign key: the geometry is
 * user-authored JSON with no rows of its own. A frame deleted from the document leaves its
 * picture here, unreferenced and harmless, which is the forgiving direction — a rectangle
 * deleted by accident can be drawn again and its picture is still attached.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('side_space_exhibits', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(SideSpaceMap::class)->constrained()->cascadeOnDelete();
            // One picture per frame. Replacing is an upsert, so re-hanging a wall is one action
            // rather than a delete and an add that can half-fail.
            $table->string('exhibit_id', 40);
            $table->string('title', 120);
            $table->string('artist', 120)->nullable();
            // The wall label. Long-form on purpose: the interesting part of a museum is the card
            // beside the painting, not the painting's name.
            $table->text('caption')->nullable();
            $table->string('disk');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->foreignIdFor(User::class, 'uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['side_space_map_id', 'exhibit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('side_space_exhibits');
    }
};
