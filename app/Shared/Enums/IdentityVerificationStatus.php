<?php

namespace App\Shared\Enums;

enum IdentityVerificationStatus: string
{
    case Pending = 'pending';
    case Passed = 'passed';
    case Failed = 'failed';
}
