<?php

namespace App\Shared\Contracts;

use Illuminate\Database\Eloquent\Model;

interface AuditLoggerContract
{
    /**
     * Record a business-action audit entry. Every money, plan, listing,
     * vendor, order, and admin state change must go through this rather
     * than being written directly, so audit coverage cannot be forgotten
     * on a case-by-case basis (docs/FirstMaket_Security_Compliance.md
     * section 8).
     */
    public function log(
        ?Model $actor,
        Model $subject,
        string $action,
        array $oldValues = [],
        array $newValues = [],
    ): void;
}
