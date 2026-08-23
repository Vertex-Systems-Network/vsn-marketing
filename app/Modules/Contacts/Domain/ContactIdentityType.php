<?php

namespace App\Modules\Contacts\Domain;

enum ContactIdentityType: string
{
    case Email = 'email';
    case Phone = 'phone';
    case External = 'external';
}
