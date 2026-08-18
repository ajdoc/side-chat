<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Files staged for a large upload but never claimed by a message. See PruneChunkedUploads.
Schedule::command('uploads:prune')->hourly();

/*
 * Bot schedules — "post this every Monday at nine".
 *
 * Every minute, because a schedule set for 9:00 should go out at 9:00; the command itself is
 * one indexed query when nothing is due, so the cost of asking that often is near zero.
 * `withoutOverlapping` matters on a slow post: two runners picking up the same due row would
 * post it twice.
 */
Schedule::command('bot:run-schedules')->everyMinute()->withoutOverlapping();

/*
 * Calendar reminders — "the standup starts in 10 minutes".
 *
 * Every minute for the same reason the schedules are: a notice set for ten minutes before nine
 * is worthless at five past. The command stamps each row before it posts, so an overlap or a
 * crash can lose a reminder and can never repeat one — see PostCalendarReminders.
 */
Schedule::command('calendar:post-reminders')->everyMinute()->withoutOverlapping();

// Giveaways whose time is up. Every minute for the same reason: a draw announced at 8:04
// for a giveaway that closed at 8:00 reads as broken.
Schedule::command('bot:draw-giveaways')->everyMinute()->withoutOverlapping();

/*
 * Guest accounts, once their meeting is long over. Hourly rather than nightly: a guest is a
 * live credential, and one that lingers a day past its use is a day of somebody being able to
 * walk back into a room they visited once. See PruneGuests.
 */
Schedule::command('guests:prune')->hourly();

/*
 * The audit log is written on every action of every rule, so it is the fastest-growing
 * table here and explicitly not a permanent record — see the bot_audit_log migration.
 * Nightly, off-peak, because it deletes in chunks and there is no hurry.
 */
Schedule::command('bot:prune-audit-log')->dailyAt('03:30');
