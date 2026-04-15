<?php

declare(strict_types=1);

namespace Supseven\ThemeDev\Command;

use ApacheSolrForTypo3\Solr\ConnectionManager;
use ApacheSolrForTypo3\Solr\Domain\Index\Queue\QueueInitializationService;
use ApacheSolrForTypo3\Solr\Domain\Site\SiteRepository;
use ApacheSolrForTypo3\Solr\IndexQueue\Indexer;
use ApacheSolrForTypo3\Solr\IndexQueue\IndexingService;
use ApacheSolrForTypo3\Solr\IndexQueue\Item;
use ApacheSolrForTypo3\Solr\System\Logging\SolrLogManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Log\Logger;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Run the solr indexer for all or some sites and types
 *
 * Does not use DI or additional services by intention. All
 * functions are implemented with a single method to limit
 * external influences and make it easier to show errors and
 * traces in case of missing extensions, configs or packages
 *
 * @author Georg Großberger <g.grossberger@supseven.at>
 */
#[AsCommand('solr:index', 'Create solr index-queue and process it')]
class SolrIndexCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('site', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit to given sites (TYPO3 site identifier)');
        $this->addOption('type', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit to given types (=index configuration name, not table)');
        $this->addOption('ignore-errors', 'i', InputOption::VALUE_NONE, 'Continue after an indexing error');
        $this->addOption('field', 'f', InputOption::VALUE_REQUIRED, 'solr field that saves the type info', 'type_stringS');

        $this->setHelp(
            <<<HELP
                Reset and fill the index queue and run the indexer

                Examples:

                <comment># Index everything</comment>
                <code>typo3 solr:index</code>

                <comment># Index all types only site main and microsite</comment>
                <code>typo3 solr:index -s main -s microsite</code>

                <comment># Index only news on all sites</comment>
                <code>typo3 solr:index -t news</code>

                <comment># Index only pages and news on site main and microsite</comment>
                <code>typo3 solr:index -s main -s microsite -t pages -t news</code>
                HELP
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!ExtensionManagementUtility::isLoaded('solr')) {
            $io->error('EXT:solr not installed');

            return self::INVALID;
        }

        $log = new ConsoleLogger($output);
        $solrLogger = new class ($log) extends SolrLogManager implements SingletonInterface {
            public string $prefix = '';

            public function __construct(protected ConsoleLogger $consoleLogger)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ($this->prefix) {
                    $message = $this->prefix . ': ' . $message;
                }

                $this->consoleLogger->log($level, $message, $context);
            }

            protected function getLogger(): Logger
            {
                return new class ($this->consoleLogger) extends Logger {
                    private LoggerInterface $logger;

                    public function __construct(LoggerInterface $logger)
                    {
                        $this->logger = $logger;
                    }

                    public function log($level, \Stringable|string $message, array $data = []): void
                    {
                        $this->logger->log($level, $message, $data);
                    }
                };
            }
        };

        GeneralUtility::setSingletonInstance(SolrLogManager::class, $solrLogger);

        $siteRepository = GeneralUtility::makeInstance(SiteRepository::class);
        $allSites = $siteRepository->getAvailableSites(true);
        $sites = [];
        $selectedSites = $input->getOption('site');

        if ($selectedSites) {
            foreach ($selectedSites as $selectedSite) {
                foreach ($allSites as $site) {
                    $id = $site->getTypo3SiteObject()->getIdentifier();

                    if ($id === $selectedSite) {
                        $log->debug('Add site {id}', compact('id'));
                        $sites[] = $site;
                        continue 2;
                    }
                }

                $io->error('Site `' . $selectedSite . '` is not available for indexing');

                return self::INVALID;
            }
        } else {
            $log->debug('Use all sites');
            $sites = $allSites;
        }

        if (empty($sites)) {
            $io->error('No sites available for indexing');

            return self::INVALID;
        }

        $typeKeys = [];

        foreach ($sites as $site) {
            $siteTypes = $site->getSolrConfiguration()->getEnabledIndexQueueConfigurationNames();

            foreach ($siteTypes as $siteType) {
                $identifier = $site->getTypo3SiteObject()->getIdentifier();
                $log->debug('Site `{identifier}` has type `{siteType}`', compact('identifier', 'siteType'));
                $typeKeys[$siteType] = true;
            }
        }

        $selectedTypes = $input->getOption('type');
        $types = [];

        if ($selectedTypes) {
            foreach ($selectedTypes as $selectedType) {
                if (empty($typeKeys[$selectedType])) {
                    $io->error('Type `' . $selectedType . '` is not available in the used sites');

                    return self::INVALID;
                }

                $types[] = $selectedType;
            }
        } else {
            $log->debug('Use all types');
            $types = array_keys($typeKeys);
        }

        $io->title('Initializing queue');

        $cnxMgr = GeneralUtility::makeInstance(ConnectionManager::class);

        // Delete existing documents
        foreach ($sites as $site) {
            $servers = $cnxMgr->getConnectionsBySite($site);

            foreach ($types as $type) {
                $query = '(siteHash:"' . $site->getSiteHash() . '") AND (' . $input->getOption('field') . ':"' . $type . '")';
                $log->debug('Run solr delete query `' . $query . '`');

                foreach ($servers as $server) {
                    $server->getWriteService()->deleteByQuery($query);
                    $server->getWriteService()->commit(true);
                }
            }
        }

        // Initialize
        $total = 0;
        /** @var QueueInitializationService $initializer */
        $initializer = GeneralUtility::makeInstance(QueueInitializationService::class);
        $initializer->setClearQueueOnInitialization(true);

        foreach ($sites as $site) {
            foreach ($types as $type) {
                if ($site->getSolrConfiguration()->getIndexQueueConfigurationIsEnabled($type)) {
                    $identifier = $site->getTypo3SiteObject()->getIdentifier();
                    $log->debug('Initialize queue for site {identifier} and type {type}', compact('identifier', 'type'));
                    $status = $initializer->initializeBySiteAndIndexConfigurations($site, [$type]);

                    if ($status) {
                        $indexQueueClass = $site->getSolrConfiguration()->getIndexQueueClassByConfigurationName($type);
                        $indexQueue = GeneralUtility::makeInstance($indexQueueClass);
                        $count = $indexQueue->getStatisticsBySite($site, $type)->getTotalCount();
                        $log->debug('Queue count for site {identifier} and type {type} is {count}', compact('identifier', 'type', 'count'));
                        $total += $count;
                    } else {
                        $io->error(sprintf('Unable to initialize queue %s for site %s ', $type, $site->getTypo3SiteObject()->getIdentifier()));

                        return self::FAILURE;
                    }
                }
            }
        }

        $io->success(sprintf('Put %d items into the queue', $total));

        // Run queue
        $io->title('Start indexing');

        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_solr_indexqueue_item');
        $qb->select('*');
        $qb->from('tx_solr_indexqueue_item');
        $constraints = [];

        foreach ($sites as $site) {
            foreach ($types as $type) {
                $constraints[] = $qb->expr()->and(
                    $qb->expr()->eq('root', $site->getRootPageId()),
                    $qb->expr()->eq('indexing_configuration', $qb->quote($type)),
                );
            }
        }

        $qb->where($qb->expr()->or(...$constraints));

        $itemRows = $qb->executeQuery()->fetchAllAssociative();

        $log->debug('Found {count} matching items in indexqueue table', ['count' => count($itemRows)]);

        if ($output->isDebug()) {
            $progressBar = new ProgressBar(new NullOutput(), count($itemRows), 1);
        } else {
            $progressBar = $io->createProgressBar(count($itemRows));
        }

        $progressBar->start();

        $ignoreErrors = $input->hasOption('ignore-errors') && $input->getOption('ignore-errors');
        /** @var IndexingService $indexService */
        $indexService = GeneralUtility::makeInstance(IndexingService::class);

        foreach ($itemRows as $itemRow) {
            $item = GeneralUtility::makeInstance(Item::class, $itemRow);
            $solrLogger->prefix = $item->getSite()->getTypo3SiteObject()->getIdentifier() .
                ':' . $item->getIndexingConfigurationName() .
                ':' . $item->getRecordUid();

            $indexQueueClass = $item->getSite()->getSolrConfiguration()->getIndexQueueClassByConfigurationName($item->getIndexingConfigurationName());
            $indexQueue = GeneralUtility::makeInstance($indexQueueClass);

            try {
                $log->debug('Start indexing item {site}:{type}:{uid}', [
                    'site' => $item->getSite()->getTypo3SiteObject()->getIdentifier(),
                    'type' => $item->getIndexingConfigurationName(),
                    'uid'  => $item->getRecordUid(),
                ]);

                $itemIndexed = $indexService->indexItems([$item]);

                // update IQ item so that the IQ can determine what's been indexed already
                if ($itemIndexed) {
                    $indexQueue->updateIndexTimeByItem($item);
                    $itemChangedDateAfterIndex = $item->getChanged();

                    if ($itemChangedDateAfterIndex > $item->getChanged() && $itemChangedDateAfterIndex > time()) {
                        $indexQueue->setForcedChangeTimeByItem($item, $itemChangedDateAfterIndex);
                    }
                }

                $progressBar->advance();
            } catch (\Throwable $e) {
                $progressBar->clear();

                $skipLength = strlen(Environment::getProjectPath()) + 1;
                $lines = [
                    substr($e->getFile(), $skipLength) . ': ' . $e->getLine(),
                ];

                foreach ($e->getTrace() as $trace) {
                    if (!empty($trace['file'])) {
                        $lines[] = substr($trace['file'], $skipLength) . ': ' . $trace['line'];
                    } else {
                        $lines[] = '<PHP internal>';
                    }
                }

                $io->error([
                    'Error when indexing ' . $solrLogger->prefix,
                    $e->getMessage(),
                    implode(PHP_EOL, $lines),
                ]);

                $indexQueue->markItemAsFailed($item, $e->getCode() . ': ' . $e->__toString());

                if (!$ignoreErrors) {
                    return self::FAILURE;
                }

                $io->newLine();
                $progressBar->display();
                $progressBar->advance();
            }

            $solrLogger->prefix = '';
        }

        $progressBar->clear();

        $io->success('Indexed all items');

        return self::SUCCESS;
    }
}
