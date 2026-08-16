<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site-wide standing for an account: what it may administer, and whether it may sign in.
 *
 * Deliberately not the same thing as `server_user.role`. That pivot says what you are inside
 * one server; this column says what you are on the instance, which is why it lives on the
 * user and not on a pivot with a server on the other end. There is exactly one value for now
 * (`super_admin`) and null for everybody else — see App\Models\User::ROLES.
 *
 * A ban is three columns rather than a boolean because the reason is shown to the person it
 * happened to, at the login screen, and "you are blocked" with no sentence after it is the
 * thing every support ticket is about. `banned_by` is nullable and nulls on delete: losing
 * the admin who issued a ban must never lift the ban.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->nullable()->index()->after('is_bot');
            $table->timestamp('banned_at')->nullable()->after('role');
            $table->text('ban_reason')->nullable()->after('banned_at');
            $table->foreignId('banned_by')->nullable()->after('ban_reason')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('banned_by');
            $table->dropColumn(['role', 'banned_at', 'ban_reason']);
        });
    }
};
