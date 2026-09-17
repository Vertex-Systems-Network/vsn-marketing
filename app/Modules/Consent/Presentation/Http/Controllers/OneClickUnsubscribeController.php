<?php

namespace App\Modules\Consent\Presentation\Http\Controllers;

use App\Modules\Consent\Application\Unsubscribe\ProcessOneClickUnsubscribe;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

final class OneClickUnsubscribeController
{
    public function store(Request $request, ProcessOneClickUnsubscribe $process): Response
    {
        try {
            $process->handle(
                rawToken: (string) $request->query('token', ''),
                method: $request->method(),
                body: $request->getContent(),
                acceptedAt: new DateTimeImmutable,
            );
        } catch (InvalidArgumentException) {
            return response()->noContent(400);
        }

        return response()->noContent();
    }
}
