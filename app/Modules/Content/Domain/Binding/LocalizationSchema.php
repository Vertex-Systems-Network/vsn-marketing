<?php

namespace App\Modules\Content\Domain\Binding;

use InvalidArgumentException;

final readonly class LocalizationSchema
{
    /** @var array<string, true> */
    private array $allowedLocales;

    /** @var array<string, list<string>> */
    private array $fallbacks;

    /**
     * @param  list<string>  $allowedLocales
     * @param  array<string, list<string>>  $fallbacks
     */
    public function __construct(
        public string $defaultLocale,
        public VariableSchema $slots,
        array $allowedLocales,
        array $fallbacks = [],
    ) {
        if (trim($this->defaultLocale) === '') {
            throw new InvalidArgumentException('Default locale must not be empty.');
        }

        $allowed = [];
        foreach ($allowedLocales as $locale) {
            if (trim($locale) === '') {
                throw new InvalidArgumentException('Allowed locales must not be empty.');
            }

            if (isset($allowed[$locale])) {
                throw new InvalidArgumentException("Duplicate allowed locale: {$locale}");
            }

            $allowed[$locale] = true;
        }

        if (isset($allowed[$this->defaultLocale]) === false) {
            throw new InvalidArgumentException('Default locale must be included in allowed locales.');
        }

        foreach ($fallbacks as $locale => $chain) {
            if (isset($allowed[$locale]) === false) {
                throw new InvalidArgumentException("Fallback source locale is not allowed: {$locale}");
            }

            $seen = [$locale => true];
            foreach ($chain as $fallbackLocale) {
                if (isset($allowed[$fallbackLocale]) === false) {
                    throw new InvalidArgumentException("Fallback locale is not allowed: {$fallbackLocale}");
                }

                if (isset($seen[$fallbackLocale])) {
                    throw new InvalidArgumentException("Localization fallback cycle or duplicate detected for {$locale}.");
                }

                $seen[$fallbackLocale] = true;
            }
        }

        $this->allowedLocales = $allowed;
        $this->fallbacks = $fallbacks;
    }

    /**
     * @param  array<string, array<string, mixed>>  $translations
     * @return array{values: array<string, mixed>, chain: list<string>}
     */
    public function resolve(string $requestedLocale, array $translations): array
    {
        if (isset($this->allowedLocales[$requestedLocale]) === false) {
            throw new InvalidArgumentException("Requested locale is not allowed: {$requestedLocale}");
        }

        foreach ($translations as $locale => $values) {
            if (isset($this->allowedLocales[$locale]) === false) {
                throw new InvalidArgumentException("Translation locale is not allowed: {$locale}");
            }

            foreach (array_keys($values) as $slot) {
                if (is_string($slot) === false || in_array($slot, $this->slots->names(), true) === false) {
                    throw new InvalidArgumentException('Unknown localization slot: '.(string) $slot);
                }
            }
        }

        $chain = [$requestedLocale];
        foreach ($this->fallbacks[$requestedLocale] ?? [] as $locale) {
            if (in_array($locale, $chain, true) === false) {
                $chain[] = $locale;
            }
        }

        if (in_array($this->defaultLocale, $chain, true) === false) {
            $chain[] = $this->defaultLocale;
        }

        $candidateValues = [];
        foreach ($this->slots->names() as $slot) {
            foreach ($chain as $locale) {
                if (array_key_exists($slot, $translations[$locale] ?? [])) {
                    $candidateValues[$slot] = $translations[$locale][$slot];
                    break;
                }
            }
        }

        return [
            'values' => $this->slots->bind($candidateValues),
            'chain' => $chain,
        ];
    }
}
