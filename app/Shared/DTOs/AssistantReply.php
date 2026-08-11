<?php

namespace App\Shared\DTOs;

/**
 * What a driver hands back: something to say, what it was worked out from,
 * and any suggestions the customer could choose to act on.
 *
 * Suggestions travel as plain arrays rather than saved rows, so a driver
 * cannot persist anything by itself — the service decides what is written
 * down, which keeps "the assistant wrote a recommendation into my account"
 * from being something a driver can do on its own.
 */
final class AssistantReply
{
    /**
     * @param  array<string, mixed>  $evidence
     * @param  list<array{action: string, title: string, body: string, goalId?: int|null, payload?: array<string, mixed>, evidence?: array<string, mixed>}>  $suggestions
     */
    public function __construct(
        public readonly string $body,
        public readonly array $evidence = [],
        public readonly array $suggestions = [],
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
        public readonly int $costKobo = 0,
    ) {}
}
