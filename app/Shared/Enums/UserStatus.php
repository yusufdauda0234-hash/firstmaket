<?php

namespace App\Shared\Enums;

enum UserStatus: string
{
    case PendingVerification = 'pending_verification';
    case Active = 'active';
    case Suspended = 'suspended';
    case Banned = 'banned';
}
