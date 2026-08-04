<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a rule's filters must *all* match, or just *any* of them.
 *
 * Conditions shipped as AND-only, with the argument that OR is the first step towards a
 * language nobody wants to write an editor for. That argument holds for *nested* logic —
 * parentheses, precedence, a tree — and it does not hold for this. "All of these" versus
 * "any of these" is one choice per rule, needs no parser, and can't nest.
 *
 * What it replaces is worse: the advice used to be "for either/or, make it two rules", which
 * means two copies of the same actions that then drift apart the first time one is edited.
 *
 * Defaults to `all`, which is what every existing rule already means.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automations', function (Blueprint $table) {
            $table->string('condition_match', 8)->default('all');
        });
    }

    public function down(): void
    {
        Schema::table('automations', function (Blueprint $table) {
            $table->dropColumn('condition_match');
        });
    }
};
