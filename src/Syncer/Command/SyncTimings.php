<?php declare(strict_types=1);

namespace Syncer\Command;

use Carbon\Carbon;
use JMS\Serializer\Serializer;
use JMS\Serializer\SerializerInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Syncer\Configuration\ProjectsConfiguration;
use Syncer\Dto\InvoiceNinja\Task;
use Syncer\Dto\Toggl\TimeEntry;
use Syncer\InvoiceNinja\Client as InvoiceNinjaClient;
use Syncer\Toggl\ReportsClient;
use Syncer\Toggl\TogglClient;

/**
 * Class SyncTimings
 *
 * @package Syncer\Command
 *
 * @author  Matthieu Calie <matthieu@calie.be>
 */
class SyncTimings extends Command {
    /**
     * @var SymfonyStyle
     */
    private $io;

    /**
     * @var TogglClient
     */
    private $togglClient;

    /**
     * @var ReportsClient
     */
    private $reportsClient;

    /**
     * @var InvoiceNinjaClient
     */
    private $invoiceNinjaClient;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var array
     */
    private $projects;

    /**
     * SyncTimings constructor.
     *
     * @param TogglClient        $togglClient
     * @param ReportsClient      $reportsClient
     * @param InvoiceNinjaClient $invoiceNinjaClient
     * @param Serializer         $serializer
     * @param array              $projects
     */
    public function __construct(
        TogglClient $togglClient,
        ReportsClient $reportsClient,
        InvoiceNinjaClient $invoiceNinjaClient,
        Serializer $serializer,
        $projects
    ) {
        $this->togglClient        = $togglClient;
        $this->reportsClient      = $reportsClient;
        $this->invoiceNinjaClient = $invoiceNinjaClient;
        $this->serializer         = $serializer;
        $this->projects           = $projects;

        parent::__construct();
    }

    /**
     * Configure the command
     */
    protected function configure() {
        $this
            ->setName('sync:timings')
            ->setDescription('Syncs timings from toggl to invoiceninja')
            ->addOption('since', null, InputOption::VALUE_OPTIONAL, 'Since when to import', 'yesterday')
            ->addOption('until', null, InputOption::VALUE_OPTIONAL, 'Until when to import', 'today')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dry run: do not import into InvoiceNinja');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output) {
        $this->io = new SymfonyStyle($input, $output);

        $since  = Carbon::parse($input->getOption('since'));
        $until = Carbon::parse($input->getOption('until'));
        $dryRun = $input->getOption('dry-run');

        $processor = new Processor();

        $projectsConfiguration  = new ProjectsConfiguration();
        $processedConfiguration = $processor->processConfiguration($projectsConfiguration, [$this->projects]);

        $this->projects = $processedConfiguration;

        $workspaces = $this->togglClient->getWorkspaces();

        if (!is_array($workspaces) || count($workspaces) === 0) {
            $this->io->error('No workspaces to sync.');

            return;
        }

        foreach ($workspaces as $workspace) {
            $detailedReport = $this->reportsClient->getDetailedReport($workspace->getId(), $since, $until);

            foreach ($detailedReport->getData() as $timeEntry) {
                $timeEntrySent = false;

                // Log the entry if the project key exists
                if ($this->timeEntryCanBeLoggedByConfig($this->projects, $timeEntry->getPid(), $timeEntrySent)) {
                    $this->logTask($timeEntry, $this->projects, $timeEntry->getPid(), $dryRun);

                    $timeEntrySent = true;
                }

                if ($timeEntrySent) {
                    $this->io->success(sprintf(
                        'TimeEntry (%s/%s - %s) sent to InvoiceNinja',
                        $timeEntry->getClient(),
                        $timeEntry->getProject(),
                        $timeEntry->getDescription()
                    ));
                }
            }
        }
    }

    /**
     * @param array $config
     * @param int   $entryKey
     * @param bool  $hasAlreadyBeenSent
     *
     * @return bool
     */
    private function timeEntryCanBeLoggedByConfig(array $config, int $entryKey, bool $hasAlreadyBeenSent): bool {
        if ($hasAlreadyBeenSent) {
            return false;
        }

        return (is_array($config) && array_key_exists($entryKey, $config));
    }

    /**
     * @param TimeEntry $entry
     * @param array     $config
     * @param int       $key
     * @param bool      $dryRun
     *
     * @return void
     */
    private function logTask(TimeEntry $entry, array $config, int $key, bool $dryRun = false) {
        $info = $config[$key];

        $task = new Task();

        $task->setDescription($entry->getDescription());
        $task->setTimeLog($this->buildTimeLog($entry));
        $task->setClientId($info['client_id']);
        $task->setProjectId($info['project_id']);

        if ($dryRun) {
            $this->io->writeln('Would have sent ' . $this->serializer->serialize($task, 'json') . ' to InvoiceNinja');
            return;
        }

        $this->invoiceNinjaClient->saveNewTask($task);
    }

    /**
     * @param TimeEntry $entry
     *
     * @return string
     */
    private function buildTimeLog(TimeEntry $entry): string {
        $timeLog = [
            [
                $entry->getStart()->getTimestamp(),
                $entry->getEnd()->getTimestamp(),
            ],
        ];

        return \GuzzleHttp\json_encode($timeLog);
    }
}
