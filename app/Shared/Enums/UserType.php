<?php

namespace App\Shared\Enums;

enum UserType: string
{
    case Customer = 'customer';
    case Vendor = 'vendor';
    case Staff = 'staff';
}
