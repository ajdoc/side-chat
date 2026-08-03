<?php

use App\Models\Automation;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the bot did, and whether it worked.
 *
 * An automation runs on the queue, out of sight, in response to something that happened to
 * somebody else. When it doesn't fire — a channel deleted, a badge renamed, a template
 * referring to a member who left — there is otherwise nothing at all for its owner to look
 * at, and "it just doesn't work" is the whole bug report. This table is the answer to that
 * question and it's why every action records here, successes included.
 *
 * It is also the moderation log: a kick performed by a rule needs the same paper trail as a
 * kick performed by a person, and giving them one shape means the dashboard's "recent mod
 * actions" is a query rather than a merge.
 *
 * Not a permanent record. It is pruned nightly (see bot:prune-audit-log); nothing
 * anybody is entitled to keep should live only here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Server::class)->constrained()->cascadeOnDelete();
            // Null for anything the bot did that no rule asked for — a moderation command
            // typed by a person — and for a run whose rule has since been deleted.
            $table->foreignIdFor(Automation::class)->nullable()->constrained()->nullOnDelete();
            // What happened: an action name ('post_message'), or a moderation verb ('kick').
            $table->string('action', 64);
            // 'ok' | 'failed' | 'skipped'. A skip is not a failure: a rule that correctly
            // declined to act on a member who had already left did its job.
            $table->string('outcome', 16);
            // Who it happened to, where it happened, and anything else worth showing. Free
            // JSON on purpose — every action carries different detail, and a column per
            // action would be a migration per action.
            $table->foreignIdFor(User::class, 'subject_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('context')->nullable();
            // The one-line reason a failure failed. Kept short: this is for the person
            // reading the dashboard, not a stack trace.
            $table->string('message', 500)->nullable();
            $table->timestamps();

            // The dashboard reads the newest first, per server and per rule.
            $table->index(['server_id', 'created_at']);
            $table->index(['automation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_audit_log');
    }
};
