<?php

declare(strict_types=1);

namespace MoveCloser\Magento2ConnectorOverride\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * The bundle exposes no configuration; an empty tree keeps Symfony happy.
 */
class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        return new TreeBuilder('magento2_connector_override');
    }
}
