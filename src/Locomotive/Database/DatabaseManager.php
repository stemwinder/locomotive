<?php

/**
 * Locomotive
 *
 * Copyright (c) 2015 Joshua Smith
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
use Symfony\Component\Finder\Finder;

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
     * @param Logger          $logger Console Logger
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
            'driver'    => 'sqlite',
            'database'  => BASEPATH . '/app/storage/locomotive.sqlite',
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
     **/
    public function doMaintenance()
    {
        $this->logger->debug('Beginning database maintenance.');

        // Check if database exists. If not, run migrations.
        if (! $this->dbDoesExist()) {
            $this->logger->debug('No database found. Attempting to migrate.');

            $this->phinxCall('migrate');

            $this->logger->debug('Migration succeeded.');
        }

        // Gets current version of database
        $currentMigration = $this->getCurrentMigration();

        // Get latest migration available
        $latestMigration = $this->getLatestMigration();

        // Migrate if DB version is < `migrations` dir version
        if ($latestMigration > $currentMigration) {
            $this->logger->debug('Database schema is old. Attempting to migrate.');

            $this->phinxCall('migrate');

            $this->logger->debug('Migration succeeded.');
        } else {
            $this->logger->debug('Database schema is at most current version.');
        }
        
        $this->logger->debug('Database maintenance completed.');

        return $this;
    }

    /**
     * Instatiates a new instance of Phinx and its TextWrapper helper.
     **/
    private function bootPhinx()
    {
        // Setup Phinx instances.
        $phinxApp = new PhinxApplication();
        $phinxWrapper = new TextWrapper($phinxApp);

        // Set some base config options
        $phinxWrapper->setOption('configuration', BASEPATH . '/phinx.yml')
                     ->setOption('parser', 'YAML')
                     ->setOption('environment', 'production');

        $this->phinx = $phinxWrapper;
    }

    /**
     * Provides a wrapping method to facilitate calls to Phinx and handle any
     * errors or exceptions provided by its exit code.
     *
     * @return string The ouput from Phinx command
     */
    private function phinxCall($command)
    {
        if (! isset($this->phinx)) {
            $this->bootPhinx();
        }

        // Build a command string that meets TextWrapper expectation
        $builtCommand = 'get' . ucfirst($command);
        $commandResult = $this->phinx->$builtCommand();

        if ($this->phinx->getExitCode() != 0) {
            $this->logger->error('There was a problem with database maintenance.');

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
     * Gets the current migration version by parsing output from the  Phinx
     * `status` command and casting it to an integer.
     *
     * @return int The current migration version
     **/
    private function getCurrentMigration()
    {
        $matches = array();
        $databaseStatus = preg_match("/\\d{14}/um", $this->phinxCall('status'), $matches);
        $currentMigration = $matches[0];

        return (int) $currentMigration;
    }

    /**
     * Gets the latest migration version available in the migrations directory,=.
     *
     * @return int The latest migration version
     **/
    private function getLatestMigration()
    {
        $finder = new Finder();

        // Only return PHP files in the migrations directory, sorted by name ascending
        $finder->files()
               ->in(BASEPATH . '/app/migrations')
               ->depth('== 0')
               ->name('*.php')
               ->sortByName();

        $latestMigrationFileName = last(iterator_to_array($finder))->getRelativePathName();

        return (int) strtok($latestMigrationFileName, '_');
    }

    /**
     * Set the database file path and name.
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
     * @return DatabaseManager
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
