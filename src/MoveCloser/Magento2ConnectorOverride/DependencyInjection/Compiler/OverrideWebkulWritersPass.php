<?php

declare(strict_types=1);

namespace MoveCloser\Magento2ConnectorOverride\DependencyInjection\Compiler;

use MoveCloser\Magento2ConnectorOverride\Connector\Processor\ContextAwareProductMediaProcessor;
use MoveCloser\Magento2ConnectorOverride\Connector\Writer\ContextAwareAttributeOptionWriter;
use MoveCloser\Magento2ConnectorOverride\Connector\Writer\ContextAwareCategoryWriter;
use MoveCloser\Magento2ConnectorOverride\Connector\Writer\ContextAwareProductMediaWriter;
use MoveCloser\Magento2ConnectorOverride\Connector\Writer\ContextAwareProductWriter;
use MoveCloser\Magento2ConnectorOverride\Services\ContextAwareMagento2Connector;
use MoveCloser\Magento2ConnectorOverride\Step\ErrorReportingStep;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Replaces the class of Webkul's category/product/attribute-option writer and media writer/processor
 * services with the context-aware subclasses, keeping Webkul's existing argument
 * wiring intact (the overrides extend the originals with the same constructors).
 *
 * Using a compiler pass instead of a service redefinition makes the override
 * order-independent and frees consuming apps from editing config/services.yml —
 * they only register the bundle in config/bundles.php.
 *
 * The media services additionally receive the localized_media switch, so a PIM exporting into a
 * Magento without MoveCloser_LocalizedMedia can turn the localized layer off in its own config.
 *
 * Every Webkul export step takes its class from `webkul_magento2.export.step`, so that parameter
 * swaps them all to ErrorReportingStep.
 */
class OverrideWebkulWritersPass implements CompilerPassInterface
{
    private const OVERRIDES = [
        'webkul_magento2.writer.category.api'                           => ContextAwareCategoryWriter::class,
        'webkul_magento2.writer.attribute_option.api'                   => ContextAwareAttributeOptionWriter::class,
        'webkul_magento2.writer.product.api'                            => ContextAwareProductWriter::class,
        'webkul_magento2.writer.product_media.api'                      => ContextAwareProductMediaWriter::class,
        'webkul_magento2.processor.magento_normalization.product_media' => ContextAwareProductMediaProcessor::class,
        self::CONNECTOR_SERVICE                                         => ContextAwareMagento2Connector::class,
    ];

    private const CONNECTOR_SERVICE = 'magento2.connector.service';

    private const LOCALIZED_MEDIA_PARAM = 'magento2_connector_override.localized_media.enabled';

    private const STEP_CLASS_PARAM = 'webkul_magento2.export.step';

    private const LOCALIZED_MEDIA_AWARE = [
        'webkul_magento2.writer.product_media.api',
        'webkul_magento2.processor.magento_normalization.product_media',
    ];

    public function process(ContainerBuilder $container): void
    {
        foreach (self::OVERRIDES as $serviceId => $class) {
            if (!$container->hasDefinition($serviceId)) {
                continue;
            }

            $container->getDefinition($serviceId)->setClass($class);
        }

        if ($container->hasParameter(self::STEP_CLASS_PARAM)) {
            $container->setParameter(self::STEP_CLASS_PARAM, ErrorReportingStep::class);
        }

        if ($container->hasDefinition(self::CONNECTOR_SERVICE)) {
            $container->getDefinition(self::CONNECTOR_SERVICE)
                ->addMethodCall('setConnection', [new Reference('doctrine.dbal.default_connection')]);
        }

        if (!$container->hasParameter(self::LOCALIZED_MEDIA_PARAM)) {
            return;
        }

        foreach (self::LOCALIZED_MEDIA_AWARE as $serviceId) {
            if (!$container->hasDefinition($serviceId)) {
                continue;
            }

            // The parameter reference is passed, not its value, so an env-backed flag still resolves
            // at runtime instead of freezing as the placeholder string at compile time.
            $container->getDefinition($serviceId)
                ->addMethodCall('setLocalizedMediaEnabled', ['%' . self::LOCALIZED_MEDIA_PARAM . '%']);
        }
    }
}
