<?php

namespace App\Support\Signups;

use Illuminate\Support\Str;

final class SignupKeys
{
    /**
     * Give each column/fee a stable key: kept if it already has one, minted from the label if not.
     * A key is never rewritten on rename, so entries and charges keep pointing at the right thing.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function normalise(array $items, string $fallback): array
    {
        $used = [];
        $out = [];

        foreach (array_values($items) as $i => $item) {
            $key = Str::slug((string) ($item['key'] ?? ''), '_') ?: (Str::slug((string) $item['label'], '_') ?: $fallback.'_'.($i + 1));
            $key = Str::limit($key, 32, '');
            $base = $key;
            $n = 2;
            while (isset($used[$key])) {
                $key = $base.'_'.$n++;
            }
            $used[$key] = true;

            $out[] = match ($fallback) {
                'column' => [
                    'key' => $key,
                    'label' => trim($item['label']),
                    'color' => $item['color'] ?? 'slate',
                    'kind' => $item['kind'],
                ],
                default => [
                    'key' => $key,
                    'label' => trim($item['label']),
                    'amount' => round((float) $item['amount'], 2),
                    'note' => isset($item['note']) ? trim((string) $item['note']) ?: null : null,
                ],
            };
        }

        return $out;
    }
}
