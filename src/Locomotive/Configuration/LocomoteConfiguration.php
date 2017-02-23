<?php

/**
 * Locomotive
 *
 * Copyright (c) 2015 Joshua Smith <josh@stemwinder.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package     Locomotive
 * @subpackage  Locomotive\Configuration
 */

namespace Locomotive\Configuration;

use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class LocomoteConfiguration implements ConfigurationInterface
{
    /**
     * Defines configuration options for the `locomote` default command.
     *
     * @return TreeBuilder
     *
     * @throws \RuntimeException
     **/
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('locomote');

        // config definitions
        $rootNode
            ->normalizeKeys(false)
            ->children()
                ->scalarNode('lftp-path')->end()
                ->scalarNode('public-keyfile')->end()
                ->scalarNode('private-keyfile')->end()
                ->scalarNode('username')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('password')->end()
                ->scalarNode('port')
                    ->defaultValue(22)
                ->end()
                ->scalarNode('working-dir')->end()
                ->scalarNode('speed-limit')
                    ->defaultValue(0)
                ->end()
                ->scalarNode('connection-limit')
                    ->defaultValue(25)
                ->end()
                ->scalarNode('transfer-limit')
                    ->defaultValue(3)
                ->end()
                ->scalarNode('max-retries')
                    ->defaultValue(5)
                ->end()
                ->scalarNode('newer-than')->end()
                ->booleanNode('zip-sources')
                    ->isRequired()
                    ->defaultFalse()
                ->end()
                ->arrayNode('speed-schedule')
                    ->useAttributeAsKey(true)
                    ->normalizeKeys(false)
                    ->performNoDeepMerging()
                    ->prototype('scalar')->end()
                ->end()
                ->arrayNode('source-target-map')
                    ->useAttributeAsKey(true)
                    ->normalizeKeys(false)
                    ->performNoDeepMerging()
                    ->prototype('scalar')->end()
                ->end()
                ->arrayNode('post-processors')
                    ->performNoDeepMerging()
                    ->prototype('scalar')->end()
                ->end()
                ->append($this->addNotificationsNode())
            ->end();

        return $treeBuilder;
    }

    private function addNotificationsNode()
    {
        $treeBuilder = new TreeBuilder();
        $node = $treeBuilder->root('notifications');

        $node
            ->performNoDeepMerging()
            ->normalizeKeys(false)
            ->children()

                // Prowl
                ->arrayNode('prowl')
                    ->treatFalseLike(['enable' => false])
                    ->treatTrueLike(['enable' => false])
                    ->treatNullLike(['enable' => false])
                    ->normalizeKeys(false)
                    ->children()
                        ->booleanNode('enable')
                            ->defaultFalse()
                        ->end()
                        ->arrayNode('events')
                            ->performNoDeepMerging()
                            ->prototype('scalar')
                                ->validate()
                                    ->ifNotInArray(['transferStarted', 'transferComplete', 'transferFailed'])
                                    ->thenInvalid('%s')
                                ->end()
                            ->end()
                        ->end()
                        ->scalarNode('api-key')
                            ->isRequired()
                        ->end()
                    ->end()
                ->end()

                // Pushover
                ->arrayNode('pushover')
                    ->treatFalseLike(['enable' => false])
                    ->treatTrueLike(['enable' => false])
                    ->treatNullLike(['enable' => false])
                    ->normalizeKeys(false)
                    ->children()
                        ->booleanNode('enable')
                            ->defaultFalse()
                        ->end()
                        ->arrayNode('events')
                            ->performNoDeepMerging()
                            ->prototype('scalar')
                                ->validate()
                                    ->ifNotInArray(['transferStarted', 'transferComplete', 'transferFailed'])
                                    ->thenInvalid('%s')
                                ->end()
                            ->end()
                        ->end()
                        ->scalarNode('api-token')
                            ->isRequired()
                        ->end()
                        ->scalarNode('user-key')
                            ->isRequired()
                        ->end()
                    ->end()
                ->end()

            ->end();

        return $node;
    }
}