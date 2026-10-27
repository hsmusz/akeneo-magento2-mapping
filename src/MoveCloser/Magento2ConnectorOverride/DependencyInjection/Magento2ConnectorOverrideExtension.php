<?php

declare(strict_types=1);

namespace MoveCloser\Magento2ConnectorOverride\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * Loads the bundle service definitions (the console commands) and exposes the configuration as
 * container parameters. The writer overrides are applied by a compiler pass, not here, so Webkul's
 * existing service arguments are preserved.
 */
class Magento2ConnectorOverrideExtension extends Extension
{
    /**
     * {@inheritdoc}
     *
     * @throws \Exception
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter(
            'magento2_connector_override.localized_media.enabled',
            $config['localized_media']['enabled']
        );

        $container->setParameter(
            'magento2_connector_override.store_view_dedupe.enabled',
            $config['store_view_dedupe']['enabled']
        );

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('cli_commands.yml');
        $loader->load('event_subscribers.yml');
    }
}
