<?php

/**
 * Locomotive
 *
 * Copyright (c) 2015 Joshua Smith <josh@stemwinder.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package  Locomotive
 * @subpackage Locomotive\BaseApplication
 */

namespace Locomotive;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Locomotive\Command\Locomote;

class BaseApplication extends Application
{
    /**
     * Class Constructor.
     *
     * Initialize the Locomotive console application.
     *
     * @param string $name The Application Name
     * @param string $version The Application Version
     */
    public function __construct($name = 'Locomotive', $version = '1.5.2')
    {
        // setting script execution time to unlimited just to be safe. This should
        // default to unlimited for PHP CLI on most systems, though.
        set_time_limit(0);

        parent::__construct($name, $version);
    }

    /**
     * Runs the current application.
     *
     * @param InputInterface $input An Input instance
     * @param OutputInterface $output An Output instance
     *
     * @return integer 0 if everything went fine, or an error code
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        // always show the version information except when the user invokes the help
        // command as that already does it
        if (false === $input->hasParameterOption(array('--version', '-V', '--help', '-h'))) {
            $output->writeln($this->getLongVersion());
            $output->writeln('');
        }

        return parent::doRun($input, $output);
    }

    /**
     * Gets the name of the command based on input.
     *
     * @param InputInterface $input The input interface
     *
     * @return string The command name
     */
    protected function getCommandName(InputInterface $input)
    {
        // This should return the name of your command.
        return 'locomote';
    }

    /**
     * Gets the default commands that should always be available.
     *
     * @return array An array of default Command instances
     *
     * @throws \Symfony\Component\Console\Exception\LogicException
     */
    protected function getDefaultCommands()
    {
        // Keep the core default commands to have the HelpCommand
        // which is used when using the --help option
        $defaultCommands = parent::getDefaultCommands();

        $defaultCommands[] = new Locomote();

        return $defaultCommands;
    }

    /**
     * Overridden so that the application doesn't expect the command
     * name to be the first argument.
     */
    public function getDefinition()
    {
        $inputDefinition = parent::getDefinition();
        // clear out the normal first argument, which is the command name
        $inputDefinition->setArguments();

        return $inputDefinition;
    }
}
