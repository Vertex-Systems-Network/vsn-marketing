<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors;

use PHPUnit\Framework\TestCase;

class ConnectorContractsTest extends TestCase
{
    public function testInterfacesExist()
    {
        $this->assertTrue(interface_exists('App\\Connectors\\Contracts\\ConnectorInterface'));
        $this->assertTrue(interface_exists('App\\Connectors\\Contracts\\ErrorNormalizerInterface'));
        $this->assertTrue(interface_exists('App\\Connectors\\Contracts\\WebhookVerifierInterface'));
        $this->assertTrue(interface_exists('App\\Connectors\\Contracts\\QuotaSignalInterface'));
        $this->assertTrue(interface_exists('App\\Connectors\\Contracts\\ReconciliationInterface'));
    }
}
