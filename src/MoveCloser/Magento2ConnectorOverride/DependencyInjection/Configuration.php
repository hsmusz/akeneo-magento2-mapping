<?php

declare(strict_types=1);

namespace MoveCloser\Magento2ConnectorOverride\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Bundle configuration.
 *
 * localized_media.enabled=false takes the media export back to one gallery entry per attribute in
 * the global scope: no per-locale entries and no marker push to MoveCloser_LocalizedMedia. The
 * mapping-driven, non-destructive reconciliation of the gallery stays on.
 *
 * store_view_dedupe.enabled=true skips a store view equal to allStoreView in the product export.
 * Off by default: in a multi-brand Magento the default scope is shared, so each store view keeps
 * its own values.
 */
class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('magento2_connector_override');

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('localized_media')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('store_view_dedupe')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
