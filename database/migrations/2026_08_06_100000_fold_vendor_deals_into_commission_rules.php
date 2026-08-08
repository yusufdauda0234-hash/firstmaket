<?php

use App\Modules\Orders\Models\CommissionRule;
use App\Modules\Vendor\Models\VendorProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A rate negotiated with a vendor is just a vendor-scoped rule.
 *
 * Keeping it on the vendor profile meant commission lived in two places, set
 * from two screens, with a rule about which beat the other. That rule was
 * itself a thing to learn, and the commissions page had to carry a warning
 * that some vendors ignored everything on it.
 *
 * Folding them in removes the concept entirely: there is one table, one page,
 * and "most specific wins" already covers it — a vendor rule outranks a
 * category rule because it always did.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (VendorProfile::query()->whereNotNull('commission_rate_percent')->get() as $vendor) {
            CommissionRule::query()->create([
                'scope_type' => 'vendor',
                'scope_id' => $vendor->id,
                'rate_percent' => $vendor->commission_rate_percent,
                'is_active' => true,
                'note' => $vendor->commission_note ?? 'Carried over from the vendor’s negotiated rate.',
            ]);
        }

        Schema::table('vendor_profiles', function (Blueprint $table) {
            $table->dropColumn(['commission_rate_percent', 'commission_note']);
        });
    }

    public function down(): void
    {
        Schema::table('vendor_profiles', function (Blueprint $table) {
            $table->decimal('commission_rate_percent', 5, 2)->nullable()->after('status');
            $table->string('commission_note', 200)->nullable()->after('commission_rate_percent');
        });
    }
};
