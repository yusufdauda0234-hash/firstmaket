<?php

namespace App\Shared\Enums;

enum OtpPurpose: string
{
    case Registration = 'registration';
    case Login = 'login';
    case PasswordReset = 'password_reset';
    case IdentityVerification = 'identity_verification';
}
