<?php

namespace App\Modules\Content\Domain\Binding;

enum VariableType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Number = 'number';
    case Boolean = 'boolean';
    case Object = 'object';
    case List = 'list';

    public function accepts(mixed $value): bool
    {
        return match ($this) {
            self::String => is_string($value),
            self::Integer => is_int($value),
            self::Number => is_int($value) || is_float($value),
            self::Boolean => is_bool($value),
            self::Object => is_array($value) && array_is_list($value) === false,
            self::List => is_array($value) && array_is_list($value),
        };
    }
}
