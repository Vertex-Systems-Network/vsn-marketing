<?php

namespace App\Modules\Providers\Domain;

enum AuthFamily: string
{
    case ApiKey = 'api_key';
    case OAuth2 = 'oauth2';
    case AwsIam = 'aws_iam';
    case Smtp = 'smtp';
    case ServiceAccount = 'service_account';
    case BearerToken = 'bearer_token';
    case Custom = 'custom';
}
