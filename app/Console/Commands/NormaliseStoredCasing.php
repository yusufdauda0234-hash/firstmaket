<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\Order;
use App\Modules\Vendor\Models\VendorProfile;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Brings rows written before the Uppercase cast into line with it.
 *
 * The cast only affects new writes, so a catalogue built earlier stays in mixed
 * case and the two look inconsistent side by side. This walks the same fields
 * the cast covers — and only those — re-saving each so the cast does the work.
 * There is no second definition of "which fields" to drift out of step.
 *
 * Writes a rollback file before touching anything, because a mass update to
 * live rows has no undo otherwise.
 */
class NormaliseStoredCasing extends Command
{
    protected $signature = 'firstmaket:normalise-casing {--dry-run : Report what would change without writing}';

    protected $description = 'Upper-case existing names and addresses to match the Uppercase cast';

    /**
     * Exactly the fields carrying the cast. Email, slug, description and
     * order state are deliberately absent — see App\Shared\Casts\Uppercase.
     *
     * @var array<class-string<Model>, list<string>>
     */
    private const FIELDS = [
        User::class => ['name'],
        Product::class => ['name'],
        Category::class => ['name'],
        VendorProfile::class => ['business_name', 'contact_name'],
        Order::class => ['delivery_address', 'lga', 'recipient_name'],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $backup = [];
        $changed = 0;

        foreach (self::FIELDS as $class => $fields) {
            $modelChanged = 0;

            $class::query()->chunkById(200, function ($rows) use ($fields, $class, $dryRun, &$backup, &$modelChanged) {
                foreach ($rows as $row) {
                    $original = [];

                    foreach ($fields as $field) {
                        $before = $row->getRawOriginal($field);

                        if ($before === null || $before === '') {
                            continue;
                        }

                        // Push it back through the cast rather than upper-casing
                        // here, so this can never diverge from what a new write
                        // would produce.
                        $row->setAttribute($field, $before);

                        if ($row->getAttributes()[$field] !== $before) {
                            $original[$field] = $before;
                        }
                    }

                    if ($original === []) {
                        continue;
                    }

                    $backup[] = ['model' => $class, 'id' => $row->getKey(), 'original' => $original];
                    $modelChanged++;

                    if (! $dryRun) {
                        // Quietly: re-saving must not fire events that notify
                        // anyone about a change that is purely cosmetic.
                        $row->saveQuietly();
                    }
                }
            });

            $this->line(sprintf('  %-22s %d row(s)', class_basename($class), $modelChanged));
            $changed += $modelChanged;
        }

        if ($changed === 0) {
            $this->info('Nothing to change — everything already matches the cast.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn("Dry run: {$changed} row(s) would change. Re-run without --dry-run to apply.");

            return self::SUCCESS;
        }

        $path = 'casing-rollback-'.now()->format('Y-m-d-His').'.json';
        Storage::disk('local')->put($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info("{$changed} row(s) updated.");
        $this->line('  Rollback file: '.Storage::disk('local')->path($path));

        return self::SUCCESS;
    }
}
