<?php

use App\Models\Badge;
use App\Models\Channel;
use App\Models\Giveaway;
use App\Models\Message;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "React to enter. One winner, drawn Friday."
 *
 * The entries are the interesting part. A giveaway is *entered* by reacting to its message,
 * which means the mechanism already exists: a `reaction.added` rule with the giveaway's
 * message id as its condition and `enter_giveaway` as its action. Creating a giveaway
 * creates that rule, so there is no second path into the entries table and no listener that
 * has to be kept in step with the automation engine.
 *
 * `drawn_at` rather than a status column. The three states anybody cares about — running,
 * drawn, cancelled — are all readable from the two timestamps and the `cancelled_at`, and a
 * status string that has to be kept consistent with them is a fourth source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('giveaways', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Server::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Channel::class)->constrained()->cascadeOnDelete();
            /*
             * The message people react to.
             *
             * Nullable because the giveaway row has to exist before its announcement can
             * name it, and null-on-delete because somebody deleting the message shouldn't
             * delete the record of who won — it should stop *new* entries, which it does,
             * since a deleted message can't be reacted to.
             */
            $table->foreignIdFor(Message::class)->nullable()->constrained()->nullOnDelete();

            $table->string('prize', 200);
            $table->string('emoji', 16)->default('🎉');
            // More than one winner is the common case for a key giveaway, and drawing them
            // in one pass is the only way to guarantee they're distinct people.
            $table->unsignedSmallInteger('winner_count')->default(1);
            // Only badge-holders may enter. Null is anybody.
            $table->foreignIdFor(Badge::class, 'required_badge_id')->nullable()->constrained('badges')->nullOnDelete();

            $table->timestamp('ends_at');
            $table->timestamp('drawn_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // The drawer's whole query: everything past its end that hasn't been drawn.
            $table->index(['drawn_at', 'ends_at']);
            $table->index(['server_id', 'created_at']);
        });

        Schema::create('giveaway_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Giveaway::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            // Set when this entry is drawn. A column on the entry rather than a winners
            // table: a winner *is* an entry, and a separate table could disagree with this
            // one about whether somebody entered at all.
            $table->boolean('won')->default(false);
            $table->timestamps();

            // Reacting twice is not two chances.
            $table->unique(['giveaway_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('giveaway_entries');
        Schema::dropIfExists('giveaways');
    }
};
