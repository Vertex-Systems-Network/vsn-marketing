<?php

namespace App\Modules\Providers\Infrastructure\Connectors\Gmail;

final class GmailMessageEncoder
{
    public function encodeRawMime(string $mimeMessage): string
    {
        return rtrim(strtr(base64_encode($mimeMessage), '+/', '-_'), '=');
    }
}
