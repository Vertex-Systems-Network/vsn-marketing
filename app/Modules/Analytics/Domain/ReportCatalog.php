<?php

namespace App\Modules\Analytics\Domain;

use InvalidArgumentException;

final class ReportCatalog
{
    public const KINDS = ['counts', 'funnel', 'retention', 'lifecycle', 'performance', 'revenue'];

    public function definition(string $kind): MetricDefinition|BehaviorDefinition|RevenueDefinition
    {
        return match ($kind) {
            'counts' => new MetricDefinition('product.viewed'),
            'funnel' => new BehaviorDefinition('funnel', ['product.viewed', 'order.completed']),
            'retention' => new BehaviorDefinition('retention', ['contact.created', 'product.viewed'], bins: 3),
            'lifecycle' => new BehaviorDefinition('lifecycle', ['product.viewed']),
            'performance' => new BehaviorDefinition('performance', ['message.clicked', 'product.viewed']),
            'revenue' => new RevenueDefinition,
            default => throw new InvalidArgumentException('Unsupported report kind.'),
        };
    }
}
