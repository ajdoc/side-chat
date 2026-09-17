<?php

use App\Models\Channel;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Sign-ups app — custom sign-up sheets, the roster they draw names from, and what each
 * name owes.
 *
 * ## Shape
 *
 * A sheet's *structure* (header rows, columns, fees) is JSON on the sheet, because it is
 * authored as one thing and never queried into. The *names* are rows, because they are: a
 * slot is unique, a name links to a person, and the payables are computed from them.
 *
 * ## Why charges are rows and not a view
 *
 * "Who owes what" could be derived from entries × fees on every read, but then marking a
 * charge paid has nowhere to live, and editing a fee next month would rewrite what people
 * already paid last month. So a charge is materialised when a name lands in an attending
 * column, and {@see \App\Support\Signups\SignupCharges::sync} keeps the *unpaid* ones in step.
 * A paid charge is a fact and is never rewritten or removed by a sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The roster: every name that has ever signed up in this channel. Suggestions come from here.
        Schema::create('signup_people', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Channel::class)->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            // Lower-cased, trimmed, single-spaced — "Pat M" and "pat  m" are one person.
            $table->string('name_key', 80);
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['channel_id', 'name_key']);
        });

        Schema::create('signup_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Channel::class)->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('category', 24)->default('training');
            // The archive bucket. Set from the event date when there is one; editable.
            $table->unsignedSmallInteger('year');
            $table->date('event_date')->nullable();
            $table->json('header');   // [{label, value}]
            $table->json('columns');  // [{key, label, color, kind}]
            $table->json('fees');     // [{key, label, amount, note}]
            $table->unsignedSmallInteger('slots')->default(15);
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignIdFor(User::class, 'created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['channel_id', 'year', 'category']);
        });

        Schema::create('signup_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sheet_id')->constrained('signup_sheets')->cascadeOnDelete();
            $table->string('column_key', 40);
            $table->unsignedSmallInteger('position');
            $table->foreignId('person_id')->constrained('signup_people')->cascadeOnDelete();
            $table->string('note', 120)->nullable();
            $table->foreignIdFor(User::class, 'added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['sheet_id', 'column_key', 'position']);
            $table->index('person_id');
        });

        Schema::create('signup_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Channel::class)->constrained()->cascadeOnDelete();
            $table->foreignId('sheet_id')->constrained('signup_sheets')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('signup_people')->cascadeOnDelete();
            $table->string('fee_key', 40);
            $table->string('label', 80);
            $table->decimal('amount', 10, 2);
            // The month the charge belongs to — the sheet's event date, else when it was raised.
            $table->date('billed_on');
            $table->timestamp('paid_at')->nullable();
            $table->foreignIdFor(User::class, 'paid_marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['sheet_id', 'person_id', 'fee_key']);
            $table->index(['channel_id', 'billed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signup_charges');
        Schema::dropIfExists('signup_entries');
        Schema::dropIfExists('signup_sheets');
        Schema::dropIfExists('signup_people');
    }
};
