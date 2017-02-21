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
 */

namespace Locomotive;

use Monolog\Logger;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

class Lftp
{
    /**
     * @var array
     **/
    protected $options = array();

    /**
     * @var Logger
     **/
    protected $logger;

    /**
     * The current command.
     *
     * @var String
     **/
    protected $command;

    /**
     * A list of all commands that have been executed.
     *
     * @var array
     **/
    protected $commandLog = array();

    /**
     * The current commands array.
     *
     * @var array
     **/
    protected $commands = array();

    /**
     * The connection command.
     *
     * @var String
     **/
    protected $connection;

    /**
     * The Filesystem instance
     *
     * @var Filesystem
     **/
    protected $fileSystem;

    /**
     * The file in which to record fetch commands
     *
     * @var String
     **/
    protected $sourcingFile;

    /**
     * The current sourcing commands array.
     *
     * @var array
     **/
    protected $sourcingCommands;

    /**
     * Class Constructor.
     *
     * @param array $options A place to pass through some settings
     * @param Logger $logger A Monolog instance
     */
    public function __construct($options, Logger $logger)
    {
        $this->options = $options;
        $this->logger = $logger;
        $this->fileSystem = new Filesystem();
        $this->sourcingFile = BASEPATH . '/app/storage/working/' . uniqid('lftp-source.', true);
    }

    public function connect()
    {
        if ($this->options['private-keyfile']) {
            // handle connections w/ ssh key file
            $cmd = "set sftp:connect-program \"ssh -a -x -i " . $this->options['private-keyfile'] . "\";"
                . ' connect -p ' . $this->options['port'] . ' -u '
                . $this->options['username'] . ',' . $this->options['password']
                . ' sftp://' . $this->options['host'];
        } else {
            // try with username and password
            $cmd = 'connect -p ' . $this->options['port'] . ' -u '
                . $this->options['username'] . ',' . $this->options['password']
                . ' sftp://' . $this->options['host'];
        }

        $this->connection = $cmd;

        return $this;
    }

    public function setSpeedLimit($limit)
    {
        $cmd = "set net:limit-total-rate $limit";

        $this->addCommand($cmd);

        $this->logger->debug("Speed limit set to $limit Bps.");

        return $this;
    }

    public function setQueueTransferLimit($limit)
    {
        $cmd = "set cmd:queue-parallel $limit";

        $this->addCommand($cmd);

        $this->logger->debug("Parallel transfer limit set to $limit item(s).");

        return $this;
    }

    public function listDir($path, $cls = true)
    {
        $cmd = ($cls === true) ? "cls $path" : "ls $path";

        $this->addCommand($cmd);

        return $this;
    }

    public function mirrorDir($path, $pget = false, $parallel = false, $queue = false)
    {
        $cmd = 'mirror -c';

        if ($pget) {
            $cmd .= " --use-pget-n=$pget";
        }

        if ($parallel) {
            $cmd .= " --parallel=$parallel";
        }

        $cmd .= " \"$path\" " . $this->options['working-dir'];

        if ($queue === true) {
            $cmd = "queue $cmd";
        }

        $this->addSourcingCommand($cmd);

        return $this;
    }

    public function pgetFile($path, $conn = false, $queue = false)
    {
        $cmd = 'pget -c';

        if ($conn) {
            $cmd .= " -n $conn";
        }

        $cmd .= " \"$path\" -o " . $this->options['working-dir'];

        if ($queue === true) {
            $cmd = "queue $cmd";
        }

        $this->addSourcingCommand($cmd);

        return $this;
    }

    public function execute($detach = false, $attach = false, $terminalId = null)
    {
        $hasSourcingFile = false;

        // write sourcing commands to temporary file
        if (count($this->sourcingCommands) > 0) {
            try {
                $sourceCommands = implode(";\n", $this->sourcingCommands) . ';';
                $this->fileSystem->dumpFile($this->sourcingFile, $sourceCommands);
                $hasSourcingFile = true;
            } catch (IOExceptionInterface $e) {
                $this->logger->critical($e->getMessage());

                exit(1);
            }
        }

        // set connection command
        $this->connect();

        // concatenate all loaded commands
        $this->command = "$this->connection; ";

        if ($attach === true) {
            $this->command .= 'echo "';
        }

        foreach ($this->commands as $cmd) {
            $this->command .= "$cmd; ";
        }

        if ($hasSourcingFile === true) {
            $this->command .= "source {$this->sourcingFile}; ";
        }

        $this->command = rtrim($this->command);

        if ($attach === true) {
            $this->command .= '" | lftp -c attach';

            if (null !== $terminalId) {
                $this->command .= " $terminalId";
            }
        }

        // execute command
        $this->logger->debug("Executing lftp commands: $this->command");

        if ($detach === true) {
            $this->command .= ' exit parent;';
            exec("lftp -c '$this->command' > /dev/null 2>&1 & echo $!", $output);

            // assuming an OK exit
            $exitCode = 0;
        } else {
            exec("lftp -c '$this->command' 2>&1", $output, $exitCode);
        }

        // deal with exit code failures
        if ((int)$exitCode !== 0) {
            $this->logger->error(implode(' ', $output));

            exit(1);
        }

        // add command to log
        $this->commandLog[] = $this->command;

        // clear command variables and sourcing file
        unset($this->command, $this->commands);
        if ($hasSourcingFile) {
            unlink($this->sourcingFile);
        }

        return $output;
    }

    public function addCommand($command)
    {
        $this->commands[] = $command;

        return $this;
    }

    public function addSourcingCommand($command)
    {
        $this->sourcingCommands[] = $command;

        return $this;
    }

    public function getCommand()
    {
        return $this->command;
    }

    public function getCommandLog()
    {
        return $this->commandLog;
    }

    public function lastCommand()
    {
        return end($this->getCommandLog());
    }
}
