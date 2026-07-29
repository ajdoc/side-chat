<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What makes search fast, and nothing that makes it *work*.
 *
 * Everything here is an index or a derived column. Drop the lot and search still returns
 * the right rows — see App\Search\LikeSearchDriver, which is what a SQLite dev database
 * gets and what Postgres falls back to if the extension below can't be created. That's the
 * property to preserve when adding to this file: no query may *depend* on an object
 * defined here, or the fallback stops being a fallback and becomes a second, untested
 * implementation of the feature.
 *
 * Two different problems, so two different tools:
 *
 * - **Message bodies** are prose. You want "deploying" to find "deployed", and you want the
 *   match ranked. That's `tsvector` + GIN. It's a stored generated column rather than an
 *   expression index because `ts_rank_cd` and `ts_headline` in the select list would
 *   otherwise re-parse the body on every returned row.
 * - **Names** (channels, servers, chats, people) are short labels. Stemming is useless on
 *   them — nobody searches a channel by the grammatical root of its name — and prefix and
 *   fuzzy matching are everything, which is `pg_trgm` + GIN over `lower(name)`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::statement("ALTER TABLE messages ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(body, ''))) STORED");
        DB::statement('CREATE INDEX messages_search_vector_index ON messages USING gin (search_vector)');

        // The filtered variants of a message search: "in this channel", "from this person".
        // Postgres can combine these with the GIN index above via a bitmap AND, but only if
        // they exist — a channel-scoped search is the common case and it is what the search
        // panel opens on.
        DB::statement('CREATE INDEX IF NOT EXISTS messages_channel_id_id_index ON messages (channel_id, id DESC)');

        if (! $this->enableTrigrams()) {
            return;
        }

        foreach (['channels', 'servers', 'conversations'] as $table) {
            DB::statement("CREATE INDEX {$table}_name_trigram_index ON {$table} USING gin (lower(name) gin_trgm_ops)");
        }

        DB::statement('CREATE INDEX users_name_trigram_index ON users USING gin (lower(name) gin_trgm_ops)');
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        foreach (['channels', 'servers', 'conversations', 'users'] as $table) {
            DB::statement("DROP INDEX IF EXISTS {$table}_name_trigram_index");
        }

        DB::statement('DROP INDEX IF EXISTS messages_channel_id_id_index');
        DB::statement('DROP INDEX IF EXISTS messages_search_vector_index');

        Schema::table('messages', function ($table) {
            $table->dropColumn('search_vector');
        });
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * `pg_trgm` ships with Postgres but creating an extension wants rights the database
     * user may not have (a managed instance, a locked-down role). Not having it costs an
     * index, not a feature — name search is `ILIKE` either way — so a refusal here is
     * swallowed rather than failing the deploy.
     */
    private function enableTrigrams(): bool
    {
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
};
