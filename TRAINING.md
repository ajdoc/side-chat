# Training programs

The Training app (`app_id = training`) runs multi-week programs that a channel follows together.
Each member ticks off their own blocks, and the Team tab adds everyone's progress up.

A program is **one JSON document**. There are three ways to get one into a channel:

1. **A built-in template.** Each template is a file in `backend/resources/training/`. Adding a
   file there adds a template.
2. **Import a file.** Staff choose *New program → Import a file*, or *Program settings →
   Replace from file*. The file can be a program `.json`, or an HTML page that carries the
   program in a data block (see below).
3. **Edit in the app.** Staff click *Edit program* on the program page to edit it in place.
   They can add or delete weeks and days, pick a day's session for one week or a whole phase,
   add, edit, reorder or delete exercises and video links, and edit week notes, phase names
   and reference sections. *Schedule & phases* shows the whole pattern at once. Nothing is
   saved until *Save*.

Whichever way it arrives, the server validates the document in
`App\Support\Training\TrainingContent` and keeps only the fields listed below.

## Importing an HTML page

The page is **never run**. Import reads exactly one thing from it:

```html
<script type="application/json" id="training-program">
{ "weeks": 13, "days": [ … ], "sessions": { … }, "plan": { … } }
</script>
```

A page without this block is refused. Import doesn't try to recover a program from a page's
own JavaScript, because every page builds its program differently. `lib/trainingImport.ts`
does the reading.

### Getting Claude to include the block

Paste this into the conversation that builds or updates the artifact:

> Also embed the whole program as data, so I can import it into my team app. Before
> `</body>`, add `<script type="application/json" id="training-program">` containing one JSON
> object in exactly this shape:
>
> - `meta`: `{ "title", "description", "default_start": "YYYY-MM-DD" (a Monday) }`
> - `weeks`: number of weeks
> - `days`: `[{ "key": "mon", "label": "Mon", "tag": "Light", "offset": 0 }]`, where `offset`
>   is days after Monday
> - `phases`: `[{ "name", "from": firstWeek, "to": lastWeek, "line": one-sentence summary }]`
> - `notes`: `{ "4": "Lighter week…" }`, keyed by week number
> - `warmups`: `{ "standard": { "label": "Warm-up, 10 minutes", "items": ["text", { "text", "videos": [...] }] } }`
> - `sessions`: `{ "session-id": { "title", "time", "hard": bool, "warmup": warmup id or null,
>   "intent", "blocks": [{ "id": short unique id within the session, "name", "dose", "cue", "videos": [{ "label", "search": "youtube
>   search words" } or { "label", "url": "https://…" }] }] } }`
> - `plan`: `{ "mon": "session-id" }` for every week, or `{ "tue": ["id-phase1", "id-phase2",
>   null] }` for one per phase (null means rest). Leave a day out for a rest day.
> - `overrides`: `[{ "weeks": [1, 13], "day": "sat", "session": "test" }]` (`"session": null` = rest those weeks)
> - `reference`: `[{ "title", "body": text or null, "items": ["…"] }]`
>
> Session ids are lower-case letters, numbers, `-` and `_`. Keep the JSON in sync with what the
> page shows, and escape `</` as `<\/` inside the block.

## Limits

| | |
| --- | --- |
| Weeks | 1–52 |
| Days per week | up to 7 |
| Phases | up to 12 |
| Sessions | up to 400 |
| Blocks per session | up to 40 |
| Video links per block | up to 8 (http/https only) |
| One-off swaps | up to 400 |
| Whole document | 1.5 MB |

## Ticks and editing

A tick is stored as `week:day:blockId`.

- **Exercises have permanent ids.** Deleting, reordering or editing exercises never moves
  anyone's ticks. A block without an id gets its position ("0", "1", …), which is also what
  older ticks point at.
- **Weeks are positions.** When the editor adds or deletes weeks, it sends a `week_map` with
  the save, and the server moves every tick to its week's new number. Ticks in a deleted week
  are removed.
- **Deleting a day** removes the ticks on that day.
- **Changing one week only** copies that day's session for that week (named `…-w5-tue`) and
  adds a one-off swap. The copy keeps the exercise ids, so ticks already on it stay put. Copies
  that nothing uses any more are cleaned up automatically.
- **Replacing from a file** keeps ticks on exercises whose ids match the file's.
