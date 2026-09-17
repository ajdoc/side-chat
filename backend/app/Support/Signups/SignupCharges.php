<?php

namespace App\Support\Signups;

use App\Models\SignupSheet;

/**
 * Keeps a sheet's charges in step with its names and fees.
 *
 * The rule: everyone in an *attending* column owes every fee on the sheet, once per sheet —
 * a name written into two attending columns is still one person at one session.
 *
 * Only unpaid charges move. A paid charge is a record of money that changed hands, so it
 * survives the name being removed or the fee being edited; the payables view shows it as-is.
 */
final class SignupCharges
{
    public static function sync(SignupSheet $sheet): void
    {
        $fees = collect($sheet->fees)->filter(fn ($f) => (float) ($f['amount'] ?? 0) > 0)->keyBy('key');

        $people = $sheet->entries()
            ->whereIn('column_key', $sheet->attendingKeys())
            ->distinct()
            ->pluck('person_id');

        $existing = $sheet->charges()->get();

        // Drop unpaid charges nobody owes any more.
        $existing
            ->filter(fn ($c) => $c->paid_at === null
                && (! $people->contains($c->person_id) || ! $fees->has($c->fee_key)))
            ->each->delete();

        $billedOn = $sheet->billedOn();

        foreach ($people as $personId) {
            foreach ($fees as $key => $fee) {
                $charge = $existing->first(fn ($c) => $c->person_id === $personId && $c->fee_key === $key);

                if ($charge?->paid_at !== null) {
                    continue;
                }

                $values = [
                    'label' => $fee['label'],
                    'amount' => $fee['amount'],
                    'billed_on' => $billedOn,
                ];

                if ($charge) {
                    $charge->fill($values)->save();
                } else {
                    $sheet->charges()->create([
                        ...$values,
                        'channel_id' => $sheet->channel_id,
                        'person_id' => $personId,
                        'fee_key' => $key,
                    ]);
                }
            }
        }
    }
}
