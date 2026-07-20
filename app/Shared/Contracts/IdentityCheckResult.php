<?php

namespace App\Shared\Contracts;

/**
 * Minimized provider outcome for a BVN/NIN check. Deliberately tiny: full
 * provider payloads are not stored unless encrypted, so verifiers must
 * distill responses down to this shape
 * (docs/firstmarket-Database_Schema.md section 5 security note).
 */
final readonly class IdentityCheckResult
{
    public function __construct(
        public bool $passed,
        public string $provider,
        public ?string $providerReference = null,
        public ?string $failureReason = null,
        /** @var array<string, mixed> non-sensitive extras only (e.g. name-match score) */
        public array $metadata = [],
    ) {}
}
