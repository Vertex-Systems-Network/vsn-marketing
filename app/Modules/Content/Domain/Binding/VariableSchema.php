<?php

namespace App\Modules\Content\Domain\Binding;

use InvalidArgumentException;

final readonly class VariableSchema
{
    /** @var array<string, VariableDefinition> */
    private array $definitions;

    /** @param  list<VariableDefinition>  $definitions */
    public function __construct(array $definitions)
    {
        $indexed = [];

        foreach ($definitions as $definition) {
            if (isset($indexed[$definition->name])) {
                throw new InvalidArgumentException("Duplicate variable definition: {$definition->name}");
            }

            $indexed[$definition->name] = $definition;
        }

        ksort($indexed, SORT_STRING);
        $this->definitions = $indexed;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function bind(array $values): array
    {
        foreach (array_keys($values) as $name) {
            if (is_string($name) === false || isset($this->definitions[$name]) === false) {
                throw new InvalidArgumentException('Unknown variable input: '.(string) $name);
            }
        }

        $resolved = [];

        foreach ($this->definitions as $name => $definition) {
            if (array_key_exists($name, $values)) {
                $definition->validate($values[$name]);
                $resolved[$name] = $values[$name];

                continue;
            }

            if ($definition->hasDefault) {
                $resolved[$name] = $definition->defaultValue;

                continue;
            }

            if ($definition->required) {
                throw new InvalidArgumentException("Required variable is unresolved: {$name}");
            }
        }

        return $resolved;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->definitions);
    }
}
