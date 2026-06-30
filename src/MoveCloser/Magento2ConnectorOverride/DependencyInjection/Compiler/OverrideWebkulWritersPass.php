<?php

declare(strict_types=1);

namespace MoveCloser\Magento2ConnectorOverride\DependencyInjection\Compiler;

use MoveCloser\Magento2ConnectorOverride\Connector\Writer\ContextAwareCategoryWriter;
use MoveCloser\Magento2ConnectorOverride\Connector\Writer\ContextAwareProductWriter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Replaces the class of Webkul's category and product writer services with the
 * context-aware subclasses, keeping Webkul's existing argument wiring intact
 * (the overrides extend the originals with the same constructors).
 *
 * Using a compiler pass instead of a service redefinition makes the override
 * order-independent and frees consuming apps from editing config/services.yml —
 * they only register the bundle in config/bundles.php.
 */
class OverrideWebkulWritersPass implements CompilerPassInterface
{
    private const OVERRIDES = [
        'webkul_magento2.writer.category.api' => ContextAwareCategoryWriter::class,
        'webkul_magento2.writer.product.api'  => ContextAwareProductWriter::class,
    ];

    public function process(ContainerBuilder $container): void
    {
        foreach (self::OVERRIDES as $serviceId => $class) {
            if (!$container->hasDefinition($serviceId)) {
                continue;
            }

            $container->getDefinition($serviceId)->setClass($class);
        }
    }
}
