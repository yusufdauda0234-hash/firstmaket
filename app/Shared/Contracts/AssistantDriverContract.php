<?php

namespace App\Shared\Contracts;

use App\Models\User;
use App\Shared\DTOs\AssistantReply;

/**
 * How the savings assistant produces an answer.
 *
 * An interface rather than a direct call to any provider, because the choice
 * of engine is a business decision that should not require a rewrite. The
 * shipped implementation is deterministic and reads only the customer's own
 * records; a hosted-model driver can be added later without anything that
 * consumes this changing.
 *
 * Two obligations on every implementation, and they are not negotiable:
 *
 *  1. **It may propose; it may not act.** A driver returns text and
 *     suggestions. Nothing it returns moves money, edits a plan, or takes a
 *     decision. Acting requires a customer confirmation recorded elsewhere.
 *  2. **It sees one customer.** Whatever context a driver assembles must
 *     come from the user passed to it. There is no path for one customer's
 *     figures to reach another's answer.
 */
interface AssistantDriverContract
{
    /**
     * Answer one question for one customer.
     *
     * @param  array<int, array{role: string, body: string}>  $history  Prior turns, oldest first.
     */
    public function reply(User $user, string $question, array $history = []): AssistantReply;

    /** Identifier written onto messages and cost logs, e.g. "rules". */
    public function name(): string;
}
