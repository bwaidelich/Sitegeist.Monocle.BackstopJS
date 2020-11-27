<?php
declare(strict_types=1);

namespace Sitegeist\Monocle\BackstopJS\Command;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Uri;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Mvc\ActionRequest;
use Sitegeist\Monocle\Service\DummyControllerContextTrait;
use Sitegeist\Monocle\Service\PackageKeyTrait;
use Sitegeist\Monocle\Fusion\FusionService;
use Sitegeist\Monocle\Service\ConfigurationService;
use Neos\Flow\Mvc\Routing\UriBuilder;

class BackstopCommandController extends CommandController
{
    use DummyControllerContextTrait, PackageKeyTrait;

    /**
     * @Flow\Inject
     * @var FusionService
     */
    protected $fusionService;

    /**
     * @Flow\Inject
     * @var ConfigurationService
     */
    protected $configurationService;

    /**
     * @Flow\Inject
     * @var UriBuilder
     */
    protected $uriBuilder;

    /**
     * @Flow\InjectConfiguration(path="configurationTemplate")
     * @var array
     */
    protected $configurationTemplate;

    /**
     * @Flow\InjectConfiguration(path="scenarioTemplate")
     * @var array
     */
    protected $scenarioTemplate;

    /**
     * @Flow\InjectConfiguration(path="defaultOptIn")
     * @var bool
     */
    protected $defaultOptIn;

    /**
     * @Flow\InjectConfiguration(path="propSetOptIn")
     * @var bool
     */
    protected $propSetOptIn;

    /**
     * Generate a backstopJS configuration file for the given site-package and baseUri
     *
     * @param string|null $baseUri the base uri, if empty a local flow:server run is assumed
     * @param string|null $packageKey the site-package, if empty the default site package is used
     * @throws \Neos\Flow\Http\Exception
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     * @throws \Neos\Neos\Domain\Exception
     */
    public function configurationCommand(?string $baseUri = 'http://127.0.0.1:8081', ?string $packageKey = null) {
        $this->prepareUriBuilder($baseUri);

        $sitePackageKey = $packageKey ?: $this->getDefaultSitePackageKey();
        $fusionAst = $this->fusionService->getMergedFusionObjectTreeForSitePackage($sitePackageKey);
        $styleguideObjects = $this->fusionService->getStyleguideObjectsFromFusionAst($fusionAst);

        $scenarioConfigurations = [];
        foreach ($styleguideObjects as $prototypeName => $styleguideInformations) {
            $enableDefault = $styleguideInformations['options']['backstop']['default'] ?? !$this->defaultOptIn;
            $enablePropSets = $styleguideInformations['options']['backstop']['propSets'] ?? !$this->propSetOptIn;
            if ($enableDefault) {
                $scenarioConfigurations[] = $this->prepareScenario($sitePackageKey, $prototypeName);
            }
            if ($styleguideInformations['propSets'] && $enablePropSets) {
                foreach ($styleguideInformations['propSets'] as $propSet) {
                    $scenarioConfigurations[] = $this->prepareScenario($sitePackageKey, $prototypeName, $propSet);
                }
            }
        }

        $viewportPresets = $this->configurationService->getSiteConfiguration($sitePackageKey, 'ui.viewportPresets');
        $viewportConfigurations = [];
        foreach ($viewportPresets as $viewportName => $viewportConfiguration) {
            $viewport = [
                'label' => $viewportConfiguration['label'],
                'width' => $viewportConfiguration['width'],
                'height' => $viewportConfiguration['height']
            ];
            $viewportConfigurations[] = $viewport;
        }

        $backstopJsConfiguration = $this->configurationTemplate;
        $backstopJsConfiguration['id'] = $sitePackageKey;
        $backstopJsConfiguration['scenarios'] = $scenarioConfigurations;
        $backstopJsConfiguration['viewports'] = $viewportConfigurations;

        $this->outputLine(json_encode($backstopJsConfiguration, JSON_PRETTY_PRINT));
    }

    /**
     * @param string $baseUri
     */
    protected function prepareUriBuilder(string $baseUri): void
    {
        // mock action request and enable rewriteurl to render
        $httpRequest = new ServerRequest('get', new Uri($baseUri));
        $actionRequest = new ActionRequest($httpRequest);
        putenv('FLOW_REWRITEURLS=1');

        // prepare uri builder
        $this->uriBuilder->reset();
        $this->uriBuilder->setRequest($actionRequest);
        $this->uriBuilder->setCreateAbsoluteUri(true);
    }

    /**
     * @param string|null $sitePackageKey
     * @param string $prototypeName
     * @param string $propSet
     * @return array
     * @throws \Neos\Flow\Http\Exception
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     */
    protected function prepareScenario(?string $sitePackageKey, string $prototypeName, ?string $propSet = null): array
    {
        $propSetScenario = $this->scenarioTemplate;
        $propSetScenario['label'] = $prototypeName . ':' . $propSet;
        $propSetScenario['url'] = $this->uriBuilder->uriFor(
            'index',
            [
                'sitePackageKey' => $sitePackageKey,
                'prototypeName' => $prototypeName,
                'propSet' => $propSet
            ],
            'preview',
            'Sitegeist.Monocle'
        );
        return $propSetScenario;
    }


}
