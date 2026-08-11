<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `reward_tiers.benefits` was seeded as a JSON object (`{"badge": "..."}`)
 * while every reader treats it as a list of benefit lines. The admin growth
 * screen edits it as one benefit per line, so the object shape reached React
 * as `{badge: '...'}` and threw `benefits.join is not a function`, taking the
 * whole page down.
 *
 * Flattening to the list the column was always meant to hold: the values are
 * kept, only the keys are dropped, so the seeded badge wording survives.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('reward_tiers')->select('id', 'benefits')->get() as $tier) {
            $decoded = json_decode((string) $tier->benefits, true);

            if (! is_array($decoded)) {
                $decoded = [];
            }

            // array_values() is the whole fix: it turns {"badge": "x"} into
            // ["x"] and leaves an already-correct list untouched.
            $benefits = array_values(array_map(
                static fn ($benefit): string => (string) $benefit,
                array_filter($decoded, 'is_scalar'),
            ));

            DB::table('reward_tiers')
                ->where('id', $tier->id)
                ->update(['benefits' => json_encode($benefits)]);
        }
    }

    public function down(): void
    {
        // The keys carried no meaning, so there is nothing to restore.
    }
};
