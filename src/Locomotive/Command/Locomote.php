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
 * @subpackage  Locomotive\Command
 */

namespace Locomotive\Command;

use Bramus\Monolog\Formatter\ColoredLineFormatter;
use Illuminate\Database\Capsule\Manager as Capsule;
use League\Event\Emitter;
use Locomotive\Configuration\Configurator;
use Locomotive\Listeners\ListenerManager;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\LockHandler;
use Locomotive\Database\DatabaseManager;
use Locomotive\Locomotive;

class Locomote extends Command
{

    /**
     * @var Logger
     **/
    protected $logger;

    /**
     * @var Emitter
     */
    protected $emitter;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var Capsule
     */
    protected $dbCapsule;

    /**
     * Sets command options and validates input.
     *
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     **/
    protected function configure()
    {
        $this->setName('locomote');

        $this
            ->addArgument(
                'host',
                InputArgument::REQUIRED,
                'A URL or resolvable Hostname for the remote source.'
            )
            ->addArgument(
                'source',
                InputArgument::OPTIONAL,
                'The full path to the SOURCE directory. May also be a colon-delimited source list.'
            )
            ->addArgument(
                'target',
                InputArgument::OPTIONAL,
                'The full path to the TARGET directory. May also be a colon-delimited target list map.'
            );

        $this
            ->addOption(
                'public-keyfile',
                null,
                InputOption::VALUE_REQUIRED,
                'A public key file to use for SSH authentication'
            )
            ->addOption(
                'private-keyfile',
                null,
                InputOption::VALUE_REQUIRED,
                'A private key file to use for SSH authentication'
            )
            ->addOption(
                'username',
                'u',
                InputOption::VALUE_REQUIRED,
                'Username for SSH login'
            )
            ->addOption(
                'password',
                'p',
                InputOption::VALUE_REQUIRED,
                'Password for SSH login'
            )
            ->addOption(
                'port',
                'o',
                InputOption::VALUE_REQUIRED,
                'The port number if the source server is listening for SSH on a non-standard port.'
            )
            ->addOption(
                'working-dir',
                'w',
                InputOption::VALUE_REQUIRED,
                'A full path to overide the working directory for Locomotive'
            )
            ->addOption(
                'speed-limit',
                's',
                InputOption::VALUE_REQUIRED,
                'Global speed limit in bytes (defaults to unlimited)'
            )
            ->addOption(
                'connection-limit',
                'c',
                InputOption::VALUE_REQUIRED,
                'Transfer connection limit (defaults to 25)'
            )
            ->addOption(
                'transfer-limit',
                't',
                InputOption::VALUE_REQUIRED,
                'Global concurrent item transfer limit (defaults to 5)'
            )
            ->addOption(
                'max-retries',
                'm',
                InputOption::VALUE_REQUIRED,
                'Maximum retry attempts for a failed or interrupted transfer'
            );
    }

    /**
     * Initial settings for the the command.
     *
     * @param InputInterface $input An Input instance
     * @param OutputInterface $output An Output instance
     *
     * @throws \Exception
     **/
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->setupLogging($input);

        try {
            // load and merge default and user config values with CLI input
            $config = new Configurator($input, $this->logger);
            $this->config = $config->getConfig();
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            exit(0);
        }

        // instantiate event emitter
        $this->emitter = new Emitter;
        ListenerManager::setup($this->emitter, $this->config, $this->logger);

        // setup database connection and perform any necessary maintenance
        $dbm = new DatabaseManager($output, $this->logger);
        $dbm->doMaintenance()
            ->connect();
        $this->dbCapsule = $dbm->getConnection();
    }

    /**
     * Executes the command.
     *
     * @param InputInterface $input An Input instance
     * @param OutputInterface $output An Output instance
     *
     * @return mixed
     *
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     * @throws \InvalidArgumentException
     * @throws IOException
     **/
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // creating a unique lock id to enable running Locomotive as multiple,
        // concurrent instances.
        $lockid = md5(serialize([$input->getArguments(), $input->getOptions()]));

        // create a lock
        $lock = new LockHandler("locomotive-$lockid");
        if (!$lock->lock()) {
            $this->logger->notice('Locomotive is already running with these arguments in another process.');

            return 0;
        }

        // instantiate Locomotive
        $locomotive = new Locomotive(
            $input,
            $output,
            $this->config,
            $this->logger,
            $this->emitter,
            $this->dbCapsule
        );
        $locomotive->dependencyCheck()
                   ->bootstrap()
                   ->setPaths()
                   ->validatePaths();

        // initial probing for general LFTP state
        $lftpQueue = $locomotive->getLftpStatus();

        if ($locomotive->isLftpBackgrounded) {
            // parse the LFTP queue and set $locomotive class variables
            // for LFTP queued items and available slots
            $locomotive->parseLftpQueue($lftpQueue);
        }

        // run Locomotive queue updates, transfers, and file handling
        $locomotive->setLimits()
                   ->updateLocalQueue()
                   ->initiateTransfers()
                   ->moveFinished();

        // write main status to output: new transfers
        if ($locomotive->newTransfers) {
            $thisLogger = &$this->logger;
            $locomotive->newTransfers->each(function ($item) use ($thisLogger) {
                $thisLogger->info('New transfer started: ' . $item->getBasename());
            });
        } else {
            $this->logger->info('Locomotive did not start any new transfers.');
        }

        // write main status to output: moved items
        if ($locomotive->movedItems->count() > 0) {
            $thisLogger = &$this->logger;
            $locomotive->movedItems->each(function ($item) use ($thisLogger) {
                $thisLogger->info('Finished item moved: ' . $item->name);
            });
        } else {
            $this->logger->info('Locomotive did not move any transfered items.');
        }

        // manually releasing lock
        $lock->release();

        return true;
    }


    /**
     * Configure and instantiate logging.
     *
     * @param InputInterface $input An Input instance
     *
     * @return void
     *
     * @throws \Exception
     */
    private function setupLogging(InputInterface $input)
    {
        if ($input->hasParameterOption('-vvv')) {
            $consoleLogLevel = Logger::DEBUG;
        } elseif ($input->hasParameterOption('-vv')) {
            $consoleLogLevel = Logger::INFO;
        } elseif ($input->hasParameterOption('-v')) {
            $consoleLogLevel = Logger::NOTICE;
        } elseif ($input->hasParameterOption('-q')) {
            $consoleLogLevel = Logger::EMERGENCY;
        } else {
            $consoleLogLevel = Logger::ERROR;
        }

        $stdoutLogFormat = "%message%\n";
        $stdoutHandler = new StreamHandler('php://stdout', $consoleLogLevel);
        $stdoutHandler->setFormatter(new ColoredLineFormatter(null, $stdoutLogFormat));

        $rotatingFileFormat = "[%datetime%] %channel%.%level_name%: %message%\n";
        $rotatingFileHandler = new RotatingFileHandler(BASEPATH . '/app/storage/logs/locomotive.log', 0, Logger::DEBUG);
        $rotatingFileHandler->setFormatter(new LineFormatter($rotatingFileFormat));

        $syslogFormat = new LineFormatter('%level_name%: %message%');
        $syslogHandler = new SyslogHandler('Locomotive', 'local6', Logger::WARNING);
        $syslogHandler->setFormatter($syslogFormat);

        $this->logger = new Logger('locomotive');
        $this->logger->pushHandler($stdoutHandler);
        $this->logger->pushHandler($rotatingFileHandler);
        $this->logger->pushHandler($syslogHandler);
    }

}
