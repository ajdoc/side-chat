<?php

namespace App\Support\Training;

use Illuminate\Validation\ValidationException;

/**
 * The one gate every program document passes through — imported, edited or copied from a
 * template.
 *
 * Hand-rolled rather than Laravel rules because the document is keyed by author-chosen ids
 * (`sessions.cut-build`, `plan.tue`), which wildcard rules read badly, and because the checks
 * that matter are *between* parts: a plan naming a session that doesn't exist, an override for
 * a day that isn't there. Those are what would render as a blank day, so they're refused here.
 *
 * Returns a *rebuilt* document — only known fields, trimmed, typed. Unknown keys in an import
 * are dropped rather than stored, so nothing rides in that the client would then render.
 */
final class TrainingContent
{
    /**
     * Encoded-size cap. The Winter Arc is ~40KB; a program with every week customised (a session
     * copy per week and day) is about 20× that. Generous without being a file host.
     */
    public const MAX_BYTES = 1_500_000;

    private const ID = '/^[a-z0-9][a-z0-9_-]{0,39}$/';

    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function normalise(array $doc, string $field = 'content'): array
    {
        if (strlen((string) json_encode($doc)) > self::MAX_BYTES) {
            throw ValidationException::withMessages([$field => 'That program is too large to import.']);
        }

        $v = new self;
        $out = $v->build($doc);

        if ($v->errors) {
            // Prefix every path so the client can say where the problem is.
            $messages = [];
            foreach (array_slice($v->errors, 0, 10, true) as $path => $msg) {
                $messages["$field.$path"] = $msg;
            }
            throw ValidationException::withMessages($messages);
        }

        return $out;
    }

    private function fail(string $path, string $message): void
    {
        $this->errors[$path] ??= $message;
    }

    private function str(mixed $value, string $path, int $max, bool $required = true): ?string
    {
        if ($value === null || $value === '') {
            if ($required) $this->fail($path, 'Required.');

            return $required ? '' : null;
        }
        if (! is_string($value) && ! is_numeric($value)) {
            $this->fail($path, 'Must be text.');

            return '';
        }
        $s = trim((string) $value);
        if (mb_strlen($s) > $max) $this->fail($path, "At most $max characters.");

        return $s;
    }

    private function int(mixed $value, string $path, int $min, int $max): int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            $this->fail($path, 'Must be a whole number.');

            return $min;
        }
        $n = (int) $value;
        if ($n < $min || $n > $max) $this->fail($path, "Must be between $min and $max.");

        return $n;
    }

    /** A list entry that should be an object; anything else fails and reads as empty. */
    private function obj(mixed $value, string $path): array
    {
        if (is_array($value) && ! array_is_list($value)) return $value;
        $this->fail($path, 'Must be an object.');

        return [];
    }

    private function list(mixed $value, string $path, int $max): array
    {
        if ($value === null) return [];
        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail($path, 'Must be a list.');

            return [];
        }
        if (count($value) > $max) $this->fail($path, "At most $max entries.");

        return array_slice($value, 0, $max);
    }

    private function map(mixed $value, string $path): array
    {
        if ($value === null || $value === []) return [];
        if (! is_array($value) || array_is_list($value)) {
            $this->fail($path, 'Must be an object.');

            return [];
        }

        return $value;
    }

    private function build(array $doc): array
    {
        $meta = is_array($doc['meta'] ?? null) ? $doc['meta'] : [];
        $weeks = $this->int($doc['weeks'] ?? null, 'weeks', 1, 52);

        // Days.
        $days = [];
        foreach ($this->list($doc['days'] ?? null, 'days', 7) as $i => $d) {
            $d = $this->obj($d, "days.$i");
            $p = "days.$i";
            $key = $this->str($d['key'] ?? null, "$p.key", 16);
            if (! preg_match(self::ID, $key)) $this->fail("$p.key", 'Lower-case letters, numbers, - or _.');
            if (isset($days[$key])) $this->fail("$p.key", 'Used twice.');
            $days[$key] = [
                'key' => $key,
                'label' => $this->str($d['label'] ?? null, "$p.label", 16),
                'tag' => $this->str($d['tag'] ?? '', "$p.tag", 40, false) ?? '',
                'offset' => $this->int($d['offset'] ?? $i, "$p.offset", 0, 6),
            ];
        }
        if (! $days) $this->fail('days', 'At least one day.');

        // Phases.
        $phases = [];
        foreach ($this->list($doc['phases'] ?? null, 'phases', 12) as $i => $ph) {
            $ph = $this->obj($ph, "phases.$i");
            $p = "phases.$i";
            $from = $this->int($ph['from'] ?? null, "$p.from", 1, $weeks);
            $to = $this->int($ph['to'] ?? null, "$p.to", 1, $weeks);
            if ($from > $to) $this->fail("$p.to", 'Ends before it starts.');
            $phases[] = [
                'name' => $this->str($ph['name'] ?? null, "$p.name", 40),
                'from' => $from,
                'to' => $to,
                'line' => $this->str($ph['line'] ?? '', "$p.line", 500, false) ?? '',
            ];
        }
        // A program with no phases is one phase long.
        if (! $phases) $phases[] = ['name' => 'Program', 'from' => 1, 'to' => $weeks, 'line' => ''];

        // Notes, keyed by week number.
        $notes = [];
        foreach ($this->map($doc['notes'] ?? null, 'notes') as $w => $text) {
            $week = $this->int((string) $w, "notes.$w", 1, $weeks);
            $s = $this->str($text, "notes.$w", 1000, false);
            if ($s) $notes[(string) $week] = $s;
        }

        // Warm-ups.
        $warmups = [];
        foreach ($this->map($doc['warmups'] ?? null, 'warmups') as $id => $w) {
            $w = $this->obj($w, "warmups.$id");
            $p = "warmups.$id";
            if (! preg_match(self::ID, (string) $id)) $this->fail($p, 'Bad id.');
            $items = [];
            foreach ($this->list($w['items'] ?? null, "$p.items", 30) as $j => $item) {
                if (is_array($item)) {
                    $videos = $this->videos($item['videos'] ?? null, "$p.items.$j.videos");
                    $text = $this->str($item['text'] ?? null, "$p.items.$j.text", 300);
                    $items[] = $videos ? ['text' => $text, 'videos' => $videos] : $text;
                } else {
                    $items[] = $this->str($item, "$p.items.$j", 300);
                }
            }
            $warmups[(string) $id] = ['label' => $this->str($w['label'] ?? 'Warm-up', "$p.label", 80), 'items' => $items];
        }

        // Sessions.
        $sessions = [];
        $raw = $this->map($doc['sessions'] ?? null, 'sessions');
        if (count($raw) > 400) $this->fail('sessions', 'At most 400 sessions.');
        foreach (array_slice($raw, 0, 400, true) as $id => $s) {
            $s = $this->obj($s, "sessions.$id");
            $p = "sessions.$id";
            if (! preg_match(self::ID, (string) $id)) $this->fail($p, 'Lower-case letters, numbers, - or _.');
            $warm = $s['warmup'] ?? null;
            if ($warm !== null && ! is_string($warm)) $warm = '';
            if ($warm !== null && ! isset($warmups[$warm])) $this->fail("$p.warmup", 'No such warm-up.');
            $blocks = [];
            $blockIds = [];
            foreach ($this->list($s['blocks'] ?? null, "$p.blocks", 40) as $j => $b) {
                $b = $this->obj($b, "$p.blocks.$j");
                $bp = "$p.blocks.$j";
                $block = [
                    'id' => $this->blockId($b['id'] ?? null, $j, $blockIds),
                    'name' => $this->str($b['name'] ?? null, "$bp.name", 120),
                    'dose' => $this->str($b['dose'] ?? '', "$bp.dose", 500, false) ?? '',
                    'cue' => $this->str($b['cue'] ?? '', "$bp.cue", 500, false) ?? '',
                ];
                $videos = $this->videos($b['videos'] ?? null, "$bp.videos");
                if ($videos) $block['videos'] = $videos;
                $blocks[] = $block;
            }
            $sessions[(string) $id] = [
                'title' => $this->str($s['title'] ?? null, "$p.title", 120),
                'time' => $this->str($s['time'] ?? '', "$p.time", 60, false) ?? '',
                'hard' => (bool) ($s['hard'] ?? false),
                'warmup' => $warm,
                'intent' => $this->str($s['intent'] ?? '', "$p.intent", 1000, false) ?? '',
                'blocks' => $blocks,
            ];
        }
        if (! $sessions) $this->fail('sessions', 'At least one session.');

        // Plan: each day names a session, or one per phase. A day may be left out (rest day).
        $plan = [];
        foreach ($this->map($doc['plan'] ?? null, 'plan') as $day => $entry) {
            $p = "plan.$day";
            if (! isset($days[$day])) {
                $this->fail($p, 'No such day.');

                continue;
            }
            if (is_array($entry)) {
                $entry = $this->list($entry, $p, count($phases));
                // null (or "") is a rest phase; anything else must name a session.
                $entry = array_map(fn ($sid) => $sid === null || $sid === '' ? null : (is_string($sid) ? $sid : '~'), $entry);
                foreach ($entry as $j => $sid) {
                    if ($sid !== null && ! isset($sessions[$sid])) $this->fail("$p.$j", "No session called “{$sid}”.");
                }
                $plan[$day] = array_values($entry);
            } else {
                $entry = is_string($entry) ? $entry : '';
                if (! isset($sessions[$entry])) $this->fail($p, "No session called “{$entry}”.");
                $plan[$day] = (string) $entry;
            }
        }

        $overrides = [];
        foreach ($this->list($doc['overrides'] ?? null, 'overrides', 400) as $i => $o) {
            $o = $this->obj($o, "overrides.$i");
            $p = "overrides.$i";
            $ws = array_map(fn ($w) => $this->int($w, "$p.weeks", 1, $weeks), $this->list($o['weeks'] ?? null, "$p.weeks", 52));
            $day = is_string($o['day'] ?? null) ? $o['day'] : '';
            // null is "rest that week".
            $sid = ($o['session'] ?? null) === null ? null : (is_string($o['session']) ? $o['session'] : '');
            if (! isset($days[$day])) $this->fail("$p.day", 'No such day.');
            if ($sid !== null && ! isset($sessions[$sid])) $this->fail("$p.session", "No session called “{$sid}”.");
            $overrides[] = ['weeks' => array_values(array_unique($ws)), 'day' => $day, 'session' => $sid];
        }

        $reference = [];
        foreach ($this->list($doc['reference'] ?? null, 'reference', 20) as $i => $r) {
            $r = $this->obj($r, "reference.$i");
            $p = "reference.$i";
            $reference[] = [
                'title' => $this->str($r['title'] ?? null, "$p.title", 120),
                'body' => $this->str($r['body'] ?? null, "$p.body", 2000, false),
                'items' => array_map(
                    fn ($item) => $this->str($item, "$p.items", 500),
                    $this->list($r['items'] ?? null, "$p.items", 40),
                ),
            ];
        }

        return [
            'meta' => array_filter([
                'title' => $this->str($meta['title'] ?? null, 'meta.title', 120, false),
                'description' => $this->str($meta['description'] ?? null, 'meta.description', 500, false),
                'default_start' => is_string($meta['default_start'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $meta['default_start'])
                    ? $meta['default_start'] : null,
            ]),
            'weeks' => $weeks,
            'days' => array_values($days),
            'phases' => $phases,
            'notes' => (object) $notes,
            'warmups' => (object) $warmups,
            'plan' => (object) $plan,
            'overrides' => $overrides,
            'sessions' => (object) $sessions,
            'reference' => $reference,
        ];
    }

    /**
     * A block's id — what a tick points at, so it must never change once people have ticked.
     *
     * A block without one takes its position ("0", "1", …): that is exactly what ticks used
     * before blocks had ids, so older programs and id-less imports line up with no remapping.
     * A missing or clashing id is minted rather than refused — an import shouldn't fail over it.
     *
     * @param  array<string, true>  $taken  ids already used in this session (updated)
     */
    private function blockId(mixed $id, int $index, array &$taken): string
    {
        $id = is_string($id) || is_int($id) ? strtolower((string) $id) : '';
        if (! preg_match(self::ID, $id) || isset($taken[$id])) {
            $id = isset($taken[(string) $index]) ? 'b'.bin2hex(random_bytes(4)) : (string) $index;
        }
        $taken[$id] = true;

        return $id;
    }

    /** Video links: a label plus a YouTube search or an http(s) url. */
    private function videos(mixed $value, string $path): array
    {
        $out = [];
        foreach ($this->list($value, $path, 8) as $i => $v) {
            $v = $this->obj($v, "$path.$i");
            $p = "$path.$i";
            $video = ['label' => $this->str($v['label'] ?? null, "$p.label", 60)];
            if (! empty($v['url'])) {
                $url = $this->str($v['url'], "$p.url", 500);
                if (! preg_match('#^https?://#i', $url)) $this->fail("$p.url", 'Must start with http:// or https://.');
                $video['url'] = $url;
            } elseif (! empty($v['search'])) {
                $video['search'] = $this->str($v['search'], "$p.search", 200);
            } else {
                $this->fail($p, 'Needs a url or a search.');
            }
            $out[] = $video;
        }

        return $out;
    }
}
