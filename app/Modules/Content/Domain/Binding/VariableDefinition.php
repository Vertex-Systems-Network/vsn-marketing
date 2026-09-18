<?php

namespace App\Modules\Content\Domain\Binding;

use InvalidArgumentException;

final readonly class VariableDefinition
{
    public function __construct(
        public string $name,
        public VariableType $type,
        public bool $required = true,
        public bool $hasDefault = false,
        public mixed $defaultValue = null,
    ) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,190}$/', $this->name) !== 1) {
            throw new InvalidArgumentException('Variable names must use a stable alphanumeric path identifier.');
        }

        if ($this->hasDefault && $this->type->accepts($this->defaultValue) === false) {
            throw new InvalidArgumentException("Default value for variable {$this->name} does not match {$this->type->value}.");
        }

        if ($this->hasDefault) {
            self::assertJsonSafe($this->defaultValue, "default.{$this->name}");
        }
    }

    public function validate(mixed $value): void
    {
        if ($this->type->accepts($value) === false) {
            throw new InvalidArgumentException("Variable {$this->name} does not match {$this->type->value}.");
        }

        self::assertJsonSafe($value, "variable.{$this->name}");
    }

    private static function assertJsonSafe(mixed $value, string $path): void
    {
        if ($value === null || is_string($value) || is_int($value) || is_bool($value)) {
            return;
        }

        if (is_float($value)) {
            if (is_finite($value) === false) {
                throw new InvalidArgumentException("Variable data must contain finite numbers: {$path}");
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                if (is_int($key) === false && is_string($key) === false) {
                    throw new InvalidArgumentException("Variable data contains an unsupported key: {$path}");
                }

                self::assertJsonSafe($nested, $path.'.'.(string) $key);
            }

            return;
        }

        throw new InvalidArgumentException("Variable data must be JSON-compatible and non-executable: {$path}");
    }
}
