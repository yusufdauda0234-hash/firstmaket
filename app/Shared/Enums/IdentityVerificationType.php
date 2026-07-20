<?php

namespace App\Shared\Enums;

enum IdentityVerificationType: string
{
    case Bvn = 'bvn';
    case Nin = 'nin';
    case Cac = 'cac';
}
