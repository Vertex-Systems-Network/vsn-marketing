<?php

namespace App\Modules\AI\Domain\Contracts;

interface AiAdapter
{
    /**
     * Never execute a proposed tool inside an adapter.
     *
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $route
     * @return array<string, mixed>
     */
    public function generate(array $request, array $route): array;
}
