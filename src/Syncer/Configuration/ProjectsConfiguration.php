<?php declare(strict_types=1);

namespace Syncer\Configuration;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class ProjectsConfiguration implements ConfigurationInterface {
    public function getConfigTreeBuilder() {
        $treeBuilder = new TreeBuilder();
        $rootNode    = $treeBuilder->root('projects');

        $rootNode
            ->useAttributeAsKey('toggl_id')
            ->arrayPrototype()
            ->children()
                    ->integerNode('client_id')->isRequired()->end()
                    ->integerNode('project_id')->isRequired()->end()
            ->end();

        return $treeBuilder;
    }
}