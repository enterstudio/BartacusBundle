<?php

/*
 * This file is part of the BartacusBundle.
 *
 * The BartacusBundle is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * The BartacusBundle is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with the BartacusBundle. If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Bartacus\Bundle\BartacusBundle\ContentElement;

use JMS\DiExtraBundle\Annotation as DI;
use Symfony\Component\Config\ConfigCacheFactory;
use Symfony\Component\Config\ConfigCacheFactoryInterface;
use Symfony\Component\Config\ConfigCacheInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * @DI\Service("bartacus.content_element.config_loader", public=false)
 */
class ContentElementConfigLoader implements WarmableInterface
{
    /**
     * @var RenderDefinitionCollection|null
     */
    protected $collection;

    /**
     * @var string[]
     */
    protected $bundles = [];

    /**
     * @var array
     */
    protected $options = [];

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var bool
     */
    private $typoScriptLoaded = false;

    /**
     * @var ConfigCacheFactoryInterface
     */
    private $configCacheFactory;

    /**
     * @DI\InjectParams(params={
     *      "container" = @DI\Inject("service_container"),
     *      "bundles" = @DI\Inject("%jms_di_extra.bundles%"),
     *      "cacheDir" = @DI\Inject("%kernel.cache_dir%"),
     *      "debug" = @DI\Inject("%kernel.debug%")
     * })
     */
    public function __construct(ContainerInterface $container, array $bundles = [], string $cacheDir = null, bool $debug = false)
    {
        $this->container = $container;
        $this->bundles = $bundles;
        $this->setOptions([
            'cache_dir' => $cacheDir,
            'debug' => $debug,
        ]);
    }

    /**
     * Sets options.
     *
     * Available options:
     *
     *   * cache_dir:     The cache directory (or null to disable caching)
     *   * debug:         Whether to enable debugging or not (false by default)
     *
     * @param array $options An array of options
     *
     * @throws \InvalidArgumentException When unsupported option is provided
     */
    public function setOptions(array $options)
    {
        $this->options = [
            'cache_dir' => null,
            'debug' => false,
        ];

        // check option names and live merge, if errors are encountered Exception will be thrown
        $invalid = [];
        foreach ($options as $key => $value) {
            if (array_key_exists($key, $this->options)) {
                $this->options[$key] = $value;
            } else {
                $invalid[] = $key;
            }
        }

        if ($invalid) {
            throw new \InvalidArgumentException(sprintf(
                'The Content Element loader does not support the following options: "%s".',
                implode('", "', $invalid)
            ));
        }
    }

    /**
     * Sets an option.
     *
     * @param string $key   The key
     * @param mixed  $value The value
     *
     * @throws \InvalidArgumentException
     */
    public function setOption($key, $value)
    {
        if (!array_key_exists($key, $this->options)) {
            throw new \InvalidArgumentException(sprintf('The Router does not support the "%s" option.', $key));
        }

        $this->options[$key] = $value;
    }

    /**
     * Gets an option value.
     *
     * @param string $key The key
     *
     * @throws \InvalidArgumentException
     *
     * @return mixed The value
     */
    public function getOption($key)
    {
        if (!array_key_exists($key, $this->options)) {
            throw new \InvalidArgumentException(sprintf('The Router does not support the "%s" option.', $key));
        }

        return $this->options[$key];
    }

    /**
     * {@inheritdoc}
     */
    public function warmUp($cacheDir)
    {
        $currentDir = $this->getOption('cache_dir');

        // force cache generation
        $this->setOption('cache_dir', $cacheDir);
        $this->loadTypoScript();

        $this->setOption('cache_dir', $currentDir);
    }

    public function load()
    {
        if (true === $this->typoScriptLoaded) {
            return;
        }

        ExtensionManagementUtility::addTypoScript(
            'Bartacus',
            'setup',
            $this->loadTypoScript()
        );

        $this->typoScriptLoaded = true;
    }

    /**
     * Load the TypoScript code for the content element render definitions
     * itself without adding them to the template.
     *
     * @throws \Exception
     *
     * @return string
     */
    protected function loadTypoScript(): string
    {
        $startingConfig = /* @lang TYPO3_TypoScript */ '
# Clear out any constants in this reserved room!
bartacus.content >

# Get content
bartacus.content.get = CONTENT
bartacus.content.get {
    table = tt_content
    select.orderBy = sorting
    select.where = colPos=0
}

# tt_content is started
tt_content >
tt_content = CASE
tt_content.key.field = CType

';

        if (null === $this->options['cache_dir']) {
            $renderDefinitions = $this->getRenderDefinitionCollection();

            $typoScripts = [];
            foreach ($renderDefinitions as $renderDefinition) {
                $typoScripts[] = $this->renderPluginContent($renderDefinition);
            }

            return $startingConfig.implode("\n\n", $typoScripts);
        }

        $cache = $this->getConfigCacheFactory()
            ->cache($this->options['cache_dir'].'/content_elements.ts',
                function (ConfigCacheInterface $cache) use ($startingConfig) {
                    $renderDefinitions = $this->getRenderDefinitionCollection();

                    $typoScripts = [];
                    foreach ($renderDefinitions as $renderDefinition) {
                        $typoScripts[] = $this->renderPluginContent($renderDefinition);
                    }

                    $output = $startingConfig.implode("\n\n", $typoScripts);
                    $cache->write($output, $renderDefinitions->getResources());
                }
            )
        ;

        return file_get_contents($cache->getPath());
    }

    /**
     * @throws \Exception
     *
     * @return RenderDefinitionCollection
     */
    private function getRenderDefinitionCollection(): RenderDefinitionCollection
    {
        if (null === $this->collection) {
            $this->collection = $this->container
                ->get('bartacus.content_element.loader')
                ->load($this->bundles, 'annotation')
            ;
        }

        return $this->collection;
    }

    /**
     * @param RenderDefinition $renderDefinition
     *
     * @return string
     */
    private function renderPluginContent(RenderDefinition $renderDefinition): string
    {
        $pluginSignature = $renderDefinition->getName();
        $cached = $renderDefinition->isCached();
        $controller = $renderDefinition->getController();

        $pluginContent = trim('
# Setting '.$pluginSignature.' content element
tt_content.'.$pluginSignature.' = USER'.($cached ? '' : '_INT').'
tt_content.'.$pluginSignature.' {
    userFunc = '.Renderer::class.'->handle
    controller = '.$controller.'
}');

        return $pluginContent;
    }

    /**
     * Provides the ConfigCache factory implementation, falling back to a
     * default implementation if necessary.
     *
     * @return ConfigCacheFactoryInterface $configCacheFactory
     */
    private function getConfigCacheFactory(): ConfigCacheFactoryInterface
    {
        if (null === $this->configCacheFactory) {
            $this->configCacheFactory = new ConfigCacheFactory($this->options['debug']);
        }

        return $this->configCacheFactory;
    }
}
