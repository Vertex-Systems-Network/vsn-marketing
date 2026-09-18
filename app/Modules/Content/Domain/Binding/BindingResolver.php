<?php

namespace App\Modules\Content\Domain\Binding;

final class BindingResolver
{
    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, array<string, mixed>>  $translations
     */
    public function resolve(
        VariableSchema $variableSchema,
        array $variables,
        LocalizationSchema $localizationSchema,
        string $requestedLocale,
        array $translations,
    ): ResolvedBindings {
        $localized = $localizationSchema->resolve($requestedLocale, $translations);

        return new ResolvedBindings(
            variables: $variableSchema->bind($variables),
            localized: $localized['values'],
            requestedLocale: $requestedLocale,
            localeChain: $localized['chain'],
        );
    }
}
