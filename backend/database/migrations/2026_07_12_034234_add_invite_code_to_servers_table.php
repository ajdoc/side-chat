<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('invite_code', 32)->nullable()->unique()->after('name');
        });

        // Backfill existing servers so every server has a working invite link.
        foreach (DB::table('servers')->whereNull('invite_code')->pluck('id') as $id) {
            DB::table('servers')->where('id', $id)->update([
                'invite_code' => Str::lower(Str::random(10)),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropUnique(['invite_code']);
            $table->dropColumn('invite_code');
        });
    }
};
