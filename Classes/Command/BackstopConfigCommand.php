<?php

declare(strict_types=1);

namespace Supseven\ThemeDev\Command;

use Psr\Http\Message\UriInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Create a backstop.js config from pages in the sitemap
 *
 * @author Georg Großberger <g.grossberger@supseven.at>
 */
#[AsCommand('backstop:config', 'Create a backstop.js config template')]
class BackstopConfigCommand extends Command
{
    public function __construct(
        protected readonly SiteFinder $siteFinder,
        protected readonly RequestFactory $requestFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::REQUIRED, 'Site identifier');
        $this->addArgument('liveUrl', InputArgument::REQUIRED, 'Live base URL of the site');
        $this->addArgument('perType', InputArgument::OPTIONAL, 'URLs per sitemap to add to the config', 20);
        $this->addArgument('targetFile', InputArgument::OPTIONAL, 'File to save the config in. Must already exist.', 'tests/backstop/backstop.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $targetFile = $input->getArgument('targetFile');

        $config = json_decode(file_get_contents($targetFile), true, 512, JSON_THROW_ON_ERROR);
        $config['scenarios'] = [];

        $site = $this->siteFinder->getSiteByIdentifier($input->getArgument('site'));
        $liveBase = new Uri($input->getArgument('liveUrl'));

        if (!$liveBase->getScheme() || !$liveBase->getHost()) {
            $io->error('Invalid live URL');

            return self::INVALID;
        }

        $max = (int)$input->getArgument('perType');

        if ($max < 1) {
            $io->error('Invalid count per type');

            return self::INVALID;
        }

        foreach ($site->getLanguages() as $language) {
            $io->info('Collect of language ' . $language->getFlagIdentifier());
            $sitemapUrl = $site->getRouter()->generateUri($site->getRootPageId(), [
                '_language' => $language->getLanguageId(),
                'type'      => 1533906435,
            ]);

            $baseSitemap = $this->requestXml($sitemapUrl);

            foreach ($baseSitemap->sitemap as $sitemap) {
                $io->info('Collect of sitemap ' . $sitemap->loc);
                $sitemap = $this->requestXml($sitemap->loc);
                $i = 1;

                foreach ($sitemap->url as $sitemapUrl) {
                    $url = new Uri((string)$sitemapUrl->loc);
                    $liveUrl = $url->withScheme($liveBase->getScheme())->withHost($liveBase->getHost());

                    $config['scenarios'][] = [
                        'label'                   => $site->getIdentifier() . ' - ' . $language->getFlagIdentifier() . ' - ' . $i,
                          'cookiePath'            => 'backstop_data/engine_scripts/cookies.json',
                          'url'                   => (string)$url,
                          'referenceUrl'          => (string)$liveUrl,
                          'readyEvent'            => '',
                          'readySelector'         => '',
                          'delay'                 => 0,
                          'hideSelectors'         => [],
                          'removeSelectors'       => [],
                          'hoverSelector'         => '',
                          'clickSelector'         => '',
                          'postInteractionWait'   => 0,
                          'selectors'             => [],
                          'selectorExpansion'     => true,
                          'expect'                => 0,
                          'misMatchThreshold'     => 0.4,
                          'requireSameDimensions' => true,
                    ];

                    $i++;

                    if ($i > $max) {
                        break;
                    }
                }
            }
        }

        $io->success('Collected ' . count($config['scenarios']) . ' backstop scenarios');

        $json = json_encode($config, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($targetFile, $json);

        return self::SUCCESS;
    }

    private function requestXml(string|UriInterface|\SimpleXMLElement $url): \SimpleXMLElement
    {
        $xml = $this->requestFactory->request((string)$url)->getBody();

        return simplexml_load_string((string)$xml);
    }
}
