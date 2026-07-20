<?php

namespace App\Shared\Enums;

enum DocumentType: string
{
    case Cac = 'cac';
    case Identity = 'identity';
    case AddressProof = 'address_proof';
    case Other = 'other';
}
