<?php

namespace App\Shared\Enums;

/**
 * A savings goal is open while the balance is still short of the target,
 * fulfilled once it has been spent on the goods, and cancelled if the
 * customer gives up on it. The money itself never moves on cancel — it
 * stays in savings for whatever they choose next.
 */
enum SavingsGoalStatus: string
{
    case Saving = 'saving';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';
}
