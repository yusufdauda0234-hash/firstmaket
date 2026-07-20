<?php

namespace App\Shared\Enums;

enum OtpChannel: string
{
    case Sms = 'sms';
    case Email = 'email';
}
