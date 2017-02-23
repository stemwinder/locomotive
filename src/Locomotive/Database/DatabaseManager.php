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
 * @subpackage  Locomotive\Database
 */

namespace Locomotive\Database;

use Monolog\Logger;
use Symfony\Component\Console\Output\OutputInterface;
use Illuminate\Database\Capsule\Manager as Capsule;
use Phinx\Console\PhinxApplication;
use Phinx\Wrapper\TextWrapper;

class DatabaseManager
{
    /**
     * @var String
     **/
    public $database;

    /**
     * @var \Illuminate\Database\Capsule\Manager
     **/
    public $capsule;

    /**
     * Phinx TextWrapper instance.
     *
     * @var TextWrapper
     **/
    protected $phinx;

    /**
     * @var Logger
     **/
    protected $logger;

    /**
     * @var OutputInterface
     **/
    protected $output;

    /**
     * Class Constructor.
     *
     * Manages all maintenance, migrations, and connection duties for the database.
     *
     * @param OutputInterface $output An Output instance
     * @param Logger $logger Console Logger
     */
    public function __construct(OutputInterface $output, Logger $logger)
    {
        $this->output = $output;
        $this->logger = $logger;

        $this->setDatabase(BASEPATH . '/app/storage/locomotive.sqlite');
    }

    /**
     * Makes a connection to the database using Illuminate\Database.
     *
     * @return DatabaseManager
     **/
    public function connect()
    {
        // Boot Eloquent and return Capsule/Manager instance for injection
        $this->capsule = new Capsule;
        $this->capsule->addConnection([
            'driver' => 'sqlite',
            'database' => BASEPATH . '/app/storage/locomotive.sqlite',
        ]);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        try {
            $this->capsule->schema()->hasTable('queue');
            $this->logger->debug('Database connected successfully.');
        } catch (\InvalidArgumentException $e) {
            $this->logger->error('There was a problem connecting to the database.');
            $this->logger->error($e->getMessage());

            exit(1);
        }

        return $this;
    }

    /**
     * Performs any necessary mainteance on the database, to include initial
     * setup, version detection, and migrations.
     *
     * Makes use of the Phinx TextWrapper.
     *
     * @return DatabaseManager
     *
     * @throws \InvalidArgumentException
     **/
    public function doMaintenance()
    {
        $this->logger->debug('Beginning database maintenance.');

        // Check if database exists. If not, run migrations.
        if ($this->dbDoesExist() === false) {
            $this->logger->notice('No database found. Attempting DB setup.');

            $this->phinxCall('migrate');

            $this->logger->notice('Database installed at: ' . $this->getDatabase());
        }

        if ($this->dbNeedsMigration() === true) {
            $this->logger->notice('Database schema is old. Attempting to migrate.');

            $this->phinxCall('migrate');

            $this->logger->notice('Migration succeeded.');
        } else {
            $this->logger->debug('Database schema is at most current version.');
        }

        $this->logger->debug('Database maintenance completed.');

        return $this;
    }

    /**
     * Instatiates a new instance of Phinx and its TextWrapper helper.
     *
     * @see PhinxApplication
     * @see TextWrapper
     *
     * @return void
     **/
    private function bootPhinx()
    {
        // Setup Phinx instances.
        $phinxApp = new PhinxApplication();
        $phinxWrapper = new TextWrapper($phinxApp);

        // Set some base config options
        $phinxWrapper->setOption('configuration', BASEPATH . '/app/config/phinx.yml')
                     ->setOption('parser', 'YAML')
                     ->setOption('environment', 'production');

        $this->phinx = $phinxWrapper;
    }

    /**
     * Provides a wrapping method to facilitate calls to Phinx and handle any
     * errors or exceptions provided by its exit code.
     *
     * @param string $command The Phinx command to run
     *
     * @return string The ouput from Phinx command
     */
    private function phinxCall($command)
    {
        if (null === $this->phinx) {
            $this->bootPhinx();
        }

        // Build a command string that meets TextWrapper expectation
        $builtCommand = 'get' . ucfirst($command);
        $commandResult = $this->phinx->$builtCommand();

        if (
            ($command === 'status' && (int)$this->phinx->getExitCode() === 2)
            || ($command !== 'status' && (int)$this->phinx->getExitCode() !== 0)
        ) {
            $this->logger->error('There was a problem with database maintenance. If this error reoccurs, consider deleting `locomotive.sqlite` and running Locomotive again.');

            exit(1);
        }

        return $commandResult;
    }

    /**
     * Checks for existence of SQLite file.
     *
     * @return bool
     **/
    private function dbDoesExist()
    {
        return file_exists($this->database);
    }


    /**
     * Checks to see if the database needs to be migrated.
     *
     * @see http://docs.phinx.org/en/latest/commands.html#the-status-command Status command exit codes
     *
     * @return bool
     */
    private function dbNeedsMigration()
    {
        $this->phinxCall('status');

        return (int)$this->phinx->getExitCode() === 1;
    }

    /**
     * Set the database file path and name.
     *
     * @param string $database Location of the SQLite DB file
     *
     * @return DatabaseManager
     **/
    private function setDatabase($database)
    {
        $this->database = $database;

        return $this;
    }

    /**
     * Gets the database file path and name.
     *
     * @return string
     **/
    public function getDatabase()
    {
        return $this->database;
    }

    /**
     * Gets the database connections.
     *
     * @return \Illuminate\Database\Capsule\Manager
     **/
    public function getConnection()
    {
        return $this->capsule;
    }
}
