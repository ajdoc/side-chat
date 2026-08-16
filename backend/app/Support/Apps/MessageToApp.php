<?php

namespace App\Support\Apps;

use App\Models\Channel;
use App\Models\KanbanCard;
use App\Models\Message;
use App\Models\TrackerProject;
use App\Models\User;
use App\Services\Widgets\WidgetService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Turning a message into an item in a productivity app.
 *
 * ## The gap it closes
 *
 * The apps and the timeline have been two separate places since the Side Desk existed. The
 * thing being tracked is nearly always *said first* — "we should redo the onboarding", "where
 * are we eating?", "here's the signed contract" — and until now the only route from the message
 * to the app was retyping it. This is that route.
 *
 * ## One target, not two
 *
 * The UI asks "the chat's own app, or an app channel?", and that question does not exist here.
 * Every app's storage is scoped to a *channel*, so both answers are a channel id: this one, or
 * the app channel's. That's the same collapse `useDeskApps`'s `channelable` flag made — a desk
 * tab and an app channel were never different storage — and it's why this is one parameter
 * rather than a mode.
 *
 * ## Reading the message
 *
 * The first line is the title and the rest is the body, everywhere. A poll goes further and
 * reads a markdown list as its options, because that is what people already type when they mean
 * one. All of that lives in {@see MessageParts}, which the client previews from, so what the
 * dialog shows and what gets created are one rule.
 *
 * ## What it never does
 *
 * It doesn't move, quote-link or delete the message — the message stays exactly where it is,
 * and the item is a copy of its text. And it never creates the target: adding to an app channel
 * that doesn't exist is a channel you should have made on purpose.
 */
final class MessageToApp
{
    /**
     * The apps a message can become something in, and the handler that does it.
     *
     * Absent apps are absent for a reason rather than by omission — see {@see unsupported}.
     *
     * @var array<string, string>
     */
    private const HANDLERS = [
        'notes' => 'toNotes',
        'kanban' => 'toKanban',
        'tracker' => 'toTracker',
        'calendar' => 'toCalendar',
        'polls' => 'toPoll',
        'canvas' => 'toCanvas',
        'docs' => 'toDocs',
    ];

    /** @return array<int, string> */
    public static function apps(): array
    {
        return array_keys(self::HANDLERS);
    }

    public static function supports(string $app): bool
    {
        return isset(self::HANDLERS[$app]);
    }

    /**
     * Why an app isn't offered, for the ones somebody will look for.
     *
     * A sticker is drawn, not written; the board holds strokes. Neither has a "make one of these
     * out of a sentence" that means anything, and an option that quietly did nothing would be
     * worse than its absence.
     */
    public static function unsupported(): array
    {
        return [
            'stickers' => 'A sticker is a drawing, not text.',
            'board' => 'The board holds strokes, not messages.',
        ];
    }

    /**
     * Do it. Returns a one-line summary of what was made, for the confirmation.
     *
     * In a transaction, because several of these write two rows (a project and its task, a poll
     * and its options) and half of one is an item nobody asked for.
     *
     * @param  array<string, mixed>  $options  app-specific extras from the dialog
     */
    public static function run(string $app, Message $message, Channel $target, User $actor, array $options = []): string
    {
        $handler = self::HANDLERS[$app] ?? null;

        if ($handler === null) {
            throw ValidationException::withMessages(['app' => 'That app can’t take a message.']);
        }

        return DB::transaction(fn () => self::$handler($message, $target, $actor, $options));
    }

    // --- the handlers ------------------------------------------------------------------------

    /**
     * Appended to the shared note, under a rule and with a byline.
     *
     * Appended rather than replacing, for the reason an import is: a note is prose somebody is
     * in the middle of. The byline is plain text, deliberately not `@Name` — a note that
     * mentions the author every time you file one of their messages would notify them for
     * something they didn't do.
     */
    private static function toNotes(Message $message, Channel $target, User $actor, array $options): string
    {
        $note = $target->spaceNote()->firstOrCreate([], ['content' => '']);
        $existing = rtrim((string) $note->content);
        $body = trim((string) $message->body);

        $entry = "**{$message->user?->name}** wrote:\n\n{$body}";

        $note->applyEdit(
            $existing === '' ? $entry : $existing."\n\n---\n\n".$entry,
            $actor->getKey(),
        );

        return 'Added to the notes.';
    }

    private static function toKanban(Message $message, Channel $target, User $actor, array $options): string
    {
        $board = KanbanBoards::for($target, $actor);
        $column = $board->resolveColumn((string) ($options['column'] ?? '')) ?? $board->firstColumn();

        $card = $board->cards()->create([
            'channel_id' => $target->getKey(),
            'column' => $column,
            'position' => KanbanBoards::nextPosition($board, $column),
            // The whole message, capped — a card is a line, and the conversation it came from is
            // still where it was if somebody needs the rest.
            'text' => mb_substr(trim((string) $message->body), 0, KanbanCard::MAX_TEXT),
            'added_by' => $message->user_id,
            'added_by_name' => $message->user?->name,
        ]);

        $card->recordActivity('created', $actor, ['column' => $column, 'from_message' => $message->id]);
        KanbanBoards::cardSaved($card);

        self::surfaceWidget($target, $actor, 'kanban');

        return "Added as card #{$card->id}.";
    }

    /**
     * A task under a project, or a new project.
     *
     * Two shapes because both are real: "this is a thing to do" and "this is a whole workstream
     * we've just decided on". `as` picks; a task needs a `project_id` in the target channel,
     * which the dialog fills from the project list it already fetched.
     */
    private static function toTracker(Message $message, Channel $target, User $actor, array $options): string
    {
        $title = MessageParts::title($message->body, 200);
        $description = MessageParts::rest($message->body);

        if ($title === '') {
            throw ValidationException::withMessages(['app' => 'There’s no text to make a task out of.']);
        }

        if (($options['as'] ?? 'task') === 'project') {
            $project = $target->trackerProjects()->create([
                'key' => self::projectKey($target, $title),
                'name' => mb_substr($title, 0, 80),
                'description' => $description ?: null,
                'position' => ((int) $target->trackerProjects()->max('position')) + 1,
                'created_by' => $actor->getKey(),
            ]);

            return "Created project {$project->key}.";
        }

        $project = TrackerProject::where('channel_id', $target->getKey())
            ->find((int) ($options['project_id'] ?? 0));

        if ($project === null) {
            throw ValidationException::withMessages(['project_id' => 'Pick a project in that channel.']);
        }

        // The same row-locked counter the Tracker's own create path uses — a task key is quoted
        // in chat and in commits, so a number handed out twice would be a reference that
        // silently changes meaning.
        $number = $project->takeNextNumber();

        $task = $project->tasks()->create([
            'number' => $number,
            'title' => $title,
            'description' => $description ?: null,
            'created_by' => $actor->getKey(),
            'position' => ((int) $project->tasks()->max('position')) + 1,
        ]);

        $task->recordActivity('created', $actor, ['from_message' => $message->id]);

        return "Created {$project->key}-{$task->number}.";
    }

    /**
     * An entry on the shared calendar, at the time the dialog names — defaulting to when the
     * message was sent.
     *
     * The default is the honest one: a message about Tuesday doesn't say *which* Tuesday in any
     * way this could read, so guessing a date out of prose would be confidently wrong on a
     * calendar, where wrong is a meeting nobody attends. The picker is right there.
     */
    private static function toCalendar(Message $message, Channel $target, User $actor, array $options): string
    {
        $starts = isset($options['starts_at'])
            ? Carbon::parse((string) $options['starts_at'])
            : $message->created_at;

        $event = $target->calendarEvents()->create([
            'user_id' => $actor->getKey(),
            'title' => MessageParts::title($message->body, 120) ?: 'Untitled',
            'description' => MessageParts::rest($message->body) ?: null,
            'starts_at' => $starts,
            'ends_at' => (clone $starts)->addHour(),
            'all_day' => (bool) ($options['all_day'] ?? false),
        ]);

        return "Added “{$event->title}” to the calendar.";
    }

    /**
     * A poll on the wall, read out of the message's own markdown list.
     *
     * Its type follows what was typed: a list becomes a `single`-choice poll, and a message with
     * no list at all becomes `yes_no`, which is the poll a bare question is. Nobody has to
     * choose a type they didn't know they were choosing.
     */
    private static function toPoll(Message $message, Channel $target, User $actor, array $options): string
    {
        $parsed = MessageParts::poll($message->body);

        if ($parsed['question'] === '') {
            throw ValidationException::withMessages(['app' => 'There’s no question in that message.']);
        }

        $poll = $target->polls()->create([
            'type' => $parsed['options'] === [] ? 'yes_no' : 'single',
            'question' => $parsed['question'],
            'anonymous' => (bool) ($options['anonymous'] ?? false),
            'created_by' => $actor->getKey(),
        ]);

        // Yes/No writes its own options server-side, which is why they're only added here when
        // the message actually listed some.
        foreach ($parsed['options'] as $i => $label) {
            $poll->options()->create(['label' => $label, 'position' => $i]);
        }

        if ($parsed['options'] === []) {
            foreach (['Yes', 'No'] as $i => $label) {
                $poll->options()->create(['label' => $label, 'position' => $i]);
            }
        }

        return 'Put the question on the Polls wall.';
    }

    private static function toCanvas(Message $message, Channel $target, User $actor, array $options): string
    {
        $target->canvasItems()->create([
            'user_id' => $actor->getKey(),
            'kind' => 'note',
            'content' => ['text' => trim((string) $message->body)],
            // Dropped at the origin rather than at a guessed empty spot: the canvas is a place
            // people arrange by hand, and a card that turns up somewhere clever is a card
            // somebody has to go looking for.
            'x' => 40, 'y' => 40, 'w' => 260, 'h' => 200,
            'z' => ((int) $target->canvasItems()->max('z')) + 1,
        ]);

        return 'Pinned to the canvas.';
    }

    /**
     * The message's attached files, onto the Docs shelf.
     *
     * The one handler that isn't about the text. Files are *copied* on the disk rather than
     * pointed at: two rows sharing a stored path would make deleting either one delete both,
     * and the message that brought the file has its own delete button.
     *
     * Encrypted attachments are refused. The bytes on disk are ciphertext, the key never reaches
     * the server, and a shelf entry nobody can open is worse than an honest no.
     */
    private static function toDocs(Message $message, Channel $target, User $actor, array $options): string
    {
        $files = $message->attachments->where('encrypted', false);

        if ($files->isEmpty()) {
            throw ValidationException::withMessages([
                'app' => $message->attachments->isEmpty()
                    ? 'That message has no files on it.'
                    : 'Encrypted files can’t be put on a shelf — the server can’t read them.',
            ]);
        }

        $added = 0;

        foreach ($files as $file) {
            $disk = Storage::disk($file->disk);
            $path = dirname($file->path).'/'.uniqid('doc-').'-'.basename($file->path);

            if (! $disk->exists($file->path) || ! $disk->copy($file->path, $path)) {
                continue;
            }

            $target->spaceDocuments()->create([
                'user_id' => $actor->getKey(),
                'disk' => $file->disk,
                'path' => $path,
                'name' => $file->name,
                'mime_type' => $file->mime_type,
                'extension' => $file->extension,
                'size' => $file->size,
            ]);
            $added++;
        }

        if ($added === 0) {
            throw ValidationException::withMessages(['app' => 'Those files couldn’t be copied.']);
        }

        return $added === 1 ? 'Added the file to Docs.' : "Added {$added} files to Docs.";
    }

    /**
     * Put the app's widget card in the target's timeline, so the thing you just filed is
     * visible to the room you filed it into.
     *
     * Only for a channel whose body is a *conversation* — a text channel, a DM, a voice room, a
     * Side Space. An app channel already **is** the board, full-window, and a card announcing it
     * in the timeline underneath would be a picture of the room you're standing in.
     *
     * And only when the channel has no card yet; see WidgetService::surface.
     */
    private static function surfaceWidget(Channel $target, User $actor, string $type): void
    {
        if ($target->type === 'app') {
            return;
        }

        app(WidgetService::class)->surface($target, $actor, $type);
    }

    /**
     * A project key from its name — initials, or the first letters of one long word.
     *
     * Suffixed until it's free in this channel, because a key is what a task is quoted by and
     * two projects answering to `ONB` would make `ONB-4` ambiguous forever.
     */
    private static function projectKey(Channel $target, string $name): string
    {
        $words = preg_split('/\s+/u', trim($name)) ?: [];
        $initials = mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($words, 0, 4))));
        $base = preg_replace('/[^A-Z0-9]/u', '', $initials) ?: 'PRJ';
        $base = mb_substr($base, 0, 6);

        $taken = $target->trackerProjects()->pluck('key')->map(fn ($k) => mb_strtoupper($k))->all();
        $key = $base;

        for ($n = 2; in_array($key, $taken, true); $n++) {
            $key = $base.$n;
        }

        return $key;
    }
}
