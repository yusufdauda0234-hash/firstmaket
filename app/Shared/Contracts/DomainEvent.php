<?php

namespace App\Shared\Contracts;

/**
 * Marker interface for events one module dispatches so other modules can
 * react without depending on each other's models/services directly. This is
 * how the modular monolith stays modular (docs/FirstMaket_Implementation_Plan.md
 * section 1.1, docs/FirstMaket_Developer_Guidelines.md golden rules).
 *
 * Example: App\Modules\Savings\Events\PlanCompleted implements DomainEvent,
 * and App\Modules\Rewards\Listeners\RecalculateRewardTier subscribes to it,
 * without the Savings module knowing Rewards exists.
 */
interface DomainEvent {}
