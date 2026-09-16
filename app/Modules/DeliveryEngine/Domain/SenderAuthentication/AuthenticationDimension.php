<?php

namespace App\Modules\DeliveryEngine\Domain\SenderAuthentication;

enum AuthenticationDimension: string
{
    case Spf = 'spf';
    case Dkim = 'dkim';
    case Dmarc = 'dmarc';
    case FromDomainAlignment = 'from_domain_alignment';
    case ForwardDns = 'forward_dns';
    case ReverseDns = 'reverse_dns';
    case Tls = 'tls';
}
