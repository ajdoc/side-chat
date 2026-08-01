<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Models\BotCommand;
use App\Services\Commands\SlashCommandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The slash commands a bot answers to, declared by the bot itself.
 *
 * A whole-set PUT rather than add/remove: a bot announces what this *version* of it can do,
 * usually on boot, and any other shape leaves it responsible for cleaning up commands its
 * previous version registered — which it will forget to do, and the stale ones then sit in
 * everybody's autocomplete pointing at handlers that no longer exist.
 */
class BotCommandController extends Controller
{
    public function __construct(private readonly SlashCommandService $slash) {}

    public function index(Request $request): JsonResponse
    {
        $bot = $request->attributes->get('bot');

        return response()->json(['data' => $bot->commands()->get(['name', 'description', 'usage'])]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var Bot $bot */
        $bot = $request->attributes->get('bot');

        $validated = $request->validate([
            'commands' => ['present', 'array', 'max:50'],
            'commands.*.name' => [
                'required', 'string', 'max:32',
                // The same shape the parser will accept, minus the slash — a command that
                // can be registered but never typed would be a trap.
                'regex:/^(?=[a-z0-9-]*[a-z])[a-z0-9][a-z0-9-]*$/',
                Rule::notIn($this->slash->reservedNames()),
            ],
            'commands.*.description' => ['nullable', 'string', 'max:255'],
            'commands.*.usage' => ['nullable', 'string', 'max:255'],
        ]);

        $names = array_column($validated['commands'], 'name');

        if (count($names) !== count(array_unique($names))) {
            throw ValidationException::withMessages(['commands' => 'Each command can only be registered once.']);
        }

        // Claimed by a *different* bot in this server. Refused here rather than resolved at
        // call time, where one of the two would silently never fire and neither author
        // would have been told. See the bot_commands migration.
        $taken = BotCommand::where('server_id', $bot->server_id)
            ->where('bot_id', '!=', $bot->id)
            ->whereIn('name', $names)
            ->pluck('name');

        if ($taken->isNotEmpty()) {
            throw ValidationException::withMessages([
                'commands' => 'Already taken by another bot here: /'.$taken->implode(', /').'.',
            ]);
        }

        DB::transaction(function () use ($bot, $validated): void {
            $bot->commands()->delete();

            foreach ($validated['commands'] as $command) {
                $bot->commands()->create([
                    'server_id' => $bot->server_id,
                    'name' => $command['name'],
                    'description' => $command['description'] ?? null,
                    'usage' => $command['usage'] ?? null,
                ]);
            }
        });

        return response()->json(['data' => $bot->commands()->get(['name', 'description', 'usage'])]);
    }
}
