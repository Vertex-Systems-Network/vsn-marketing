<?php

namespace App\Modules\AI\Domain;

/** Conservative boundary for text before it enters a quarantined, untrusted context field. */
final class AiContextSanitizer
{
    public function safe(string $content): bool
    {
        if ($content === '' || strlen($content) > 4096 || ! mb_check_encoding($content, 'UTF-8')) {
            return false;
        }

        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]|[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}|\+?\d[\d .()\-]{8,}\d|-----BEGIN [A-Z ]+PRIVATE KEY-----|\b(?:bearer|api[_ -]?key|secret|password|token)\s*[:=]\s*\S+/iu', $content) !== 1;
    }
}
