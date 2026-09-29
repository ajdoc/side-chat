<?php

namespace App\Support\Training;

/**
 * The built-in programs, one JSON file each in resources/training.
 *
 * Files rather than a table so a program ships with the code that renders it and is reviewed
 * like code. Creating a program *copies* the file into the row, so changing a template never
 * reshuffles the ticks of anyone already following it.
 */
final class TrainingTemplates
{
    private static function dir(): string
    {
        return resource_path('training');
    }

    /** @return array<int, array{id: string, title: string, description: string, default_start: ?string, weeks: int}> */
    public static function all(): array
    {
        $out = [];
        foreach (glob(self::dir().'/*.json') ?: [] as $path) {
            $id = basename($path, '.json');
            $content = self::load($id);
            $out[] = [
                'id' => $id,
                'title' => $content['meta']['title'] ?? $id,
                'description' => $content['meta']['description'] ?? '',
                'default_start' => $content['meta']['default_start'] ?? null,
                'weeks' => (int) ($content['weeks'] ?? 0),
            ];
        }

        return $out;
    }

    public static function exists(string $id): bool
    {
        return preg_match('/^[a-z0-9-]+$/', $id) === 1 && is_file(self::dir()."/{$id}.json");
    }

    /** @return array<string, mixed> */
    public static function load(string $id): array
    {
        abort_unless(self::exists($id), 404, 'No such template.');

        return json_decode((string) file_get_contents(self::dir()."/{$id}.json"), true, 512, JSON_THROW_ON_ERROR);
    }
}
