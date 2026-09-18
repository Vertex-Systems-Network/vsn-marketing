<?php

namespace App\Modules\Content\Domain\Binding;

final readonly class ResolvedBindings
{
    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $localized
     * @param  list<string>  $localeChain
     */
    public function __construct(
        public array $variables,
        public array $localized,
        public string $requestedLocale,
        public array $localeChain,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'variables' => $this->variables,
            'localization' => [
                'requested_locale' => $this->requestedLocale,
                'fallback_chain' => $this->localeChain,
                'values' => $this->localized,
            ],
        ];
    }
}
