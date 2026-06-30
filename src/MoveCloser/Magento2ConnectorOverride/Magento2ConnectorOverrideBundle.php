<?php

declare(strict_types=1);

namespace MoveCloser\Magento2ConnectorOverride;

use MoveCloser\Magento2ConnectorOverride\DependencyInjection\Compiler\OverrideWebkulWritersPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Bundle that hardens the Webkul Magento2 connector:
 *  - registers the mapping-reconciliation console commands (via the extension);
 *  - swaps Webkul's category/product writers for the context-aware overrides
 *    through a compiler pass (see {@see \MoveCloser\Magento2ConnectorOverride\DependencyInjection\Compiler\OverrideWebkulWritersPass}).
 */
class Magento2ConnectorOverrideBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new OverrideWebkulWritersPass());
    }
}
