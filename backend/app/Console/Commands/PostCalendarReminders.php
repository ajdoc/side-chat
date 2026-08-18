<?php

namespace App\Console\Commands;

use App\Events\ChannelActivity;
use App\Events\MessageSent;
use App\Jobs\SendPushNotifications;
use App\Models\CalendarEvent;
use App\Services\Notifications\FcmSender;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Posts a notice in the channel shortly before a calendar entry starts. Every minute — see
 * routes/console.php.
 *
 * ## The order of operations is the whole design
 *
 * Stamp `reminded_at` **first**, then post. A post that throws therefore loses one reminder;
 * stamping afterwards would mean a throw leaves the row due, and it fires again the next minute
 * and the minute after — a channel turning into a flood. The same trade `RunBotSchedules` makes,
 * and for the same reason: missing a notice is recoverable by looking at the calendar, which is
 * where the entry is anyway.
 *
 * ## The grace window
 *
 * A worker that was down for an hour must not wake up and announce a dozen things that have
 * already happened, so the query's *lower* bound is {@see GRACE_MINUTES} before now: anything
 * older is never a candidate again. Left unstamped deliberately — writing a row to record that
 * we decided not to post is work, and an index range that excludes it costs nothing. A room
 * being told about a meeting it already missed is worse than silence.
 */
class PostCalendarReminders extends Command
{
    protected $signature = 'calendar:post-reminders';

    protected $description = 'Post reminders for calendar entries that are about to start.';

    /** How late a reminder may still be worth posting. */
    private const GRACE_MINUTES = 10;

    public function handle(): int
    {
        $now = now();

        // Every entry still owing a reminder whose time has come. `remind_minutes` is compared
        // in PHP rather than in SQL, because "starts_at minus N minutes" is an expression no
        // index can serve — the indexed half (`reminded_at IS NULL`, ordered by `starts_at`) has
        // already narrowed this to the handful of rows that could possibly be due.
        $candidates = CalendarEvent::query()
            ->whereNull('reminded_at')
            ->whereNotNull('remind_minutes')
            ->whereNotNull('channel_id')
            ->where('starts_at', '<=', $now->copy()->addMinutes(max(CalendarEvent::REMIND_CHOICES)))
            // The grace window, as a range the index can serve — see the class comment.
            ->where('starts_at', '>=', $now->copy()->subMinutes(self::GRACE_MINUTES))
            ->with(['channel.server', 'user', 'roomChannel'])
            ->orderBy('starts_at')
            ->limit(200)
            ->get();

        $posted = 0;

        foreach ($candidates as $event) {
            if ($event->remindAt()?->isAfter($now)) {
                continue; // not yet
            }

            // Stamped before anything else can fail. See the class comment.
            $event->forceFill(['reminded_at' => $now])->saveQuietly();

            if ($this->post($event, $now)) {
                $posted++;
            }
        }

        $this->info("Posted {$posted} reminder(s).");

        return self::SUCCESS;
    }

    /**
     * The notice itself.
     *
     * A `system` message, authored by whoever put the entry in the calendar: nobody *said* this,
     * but "who scheduled it" is the question the room asks next, and the timeline can only answer
     * it from the author.
     */
    private function post(CalendarEvent $event, Carbon $now): bool
    {
        $channel = $event->channel;

        if ($channel === null || $event->user === null) {
            return false;
        }

        $message = $channel->messages()->create([
            'user_id' => $event->user_id,
            'body' => $this->body($event, $now),
            'type' => 'system',
        ]);

        $message->load('user');

        broadcast(new MessageSent($message));
        // An ordinary unread rather than a mention: an entry concerns the room, and marking it
        // as a mention for everybody would make the word mean nothing.
        broadcast(new ChannelActivity($message));
        // And the phones of whoever asked to hear about this channel — which for "starting in
        // ten minutes" is the delivery that matters most. NotificationPolicy decides who.
        SendPushNotifications::dispatchIf(FcmSender::enabled(), $message->id);

        return true;
    }

    private function body(CalendarEvent $event, Carbon $now): string
    {
        $minutes = (int) max(0, $now->diffInMinutes($event->starts_at, false));

        $when = match (true) {
            $minutes <= 0 => 'is starting now',
            $minutes === 1 => 'starts in a minute',
            $minutes < 60 => "starts in {$minutes} minutes",
            default => 'starts at '.$event->starts_at->format('H:i'),
        };

        $line = "📅 **{$event->title}** {$when}.";

        // The room, when there is one. This is the half that makes a reminder a way in rather
        // than a fact: the client turns the channel reference into a link people can follow.
        if ($event->roomChannel !== null) {
            $kind = $event->roomChannel->type === 'space' ? 'Side Space' : 'voice channel';
            $line .= " In the {$kind} **{$event->roomChannel->name}**.";
        }

        return $line;
    }
}
