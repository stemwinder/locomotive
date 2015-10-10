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
 * @subpackage  Locomotive\Configuration
 */

namespace Locomotive\Configuration;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Yaml\Yaml;
use Locomotive\Configuration\LocomoteConfiguration;

class Configurator
{
    /**
     * @var ConsoleLogger
     **/
    protected $logger;

    /**
     * @var Array
     **/
    protected $defaults;

    /**
     * @var Array
     **/
    protected $user;

    /**
     * @var Array
     **/
    protected $config;

    /**
     * @var Array
     **/
    protected $cli;

    /**
     * Class Constructor.
     *
     * Handles all expected configuration sources and merges them together while
     * giving precedence to user `yml` config files and command line input.
     *
     * This is really only a separate class because it needs to be abstracted.
     * It could be further decouples by transfering duties from the contructor
     * elsewhere, and passing in YAML file locations (and making use of FileLoader)
     *
     * @param InputInterface  $input  An Input instance
     */
    public function __construct(InputInterface $input, ConsoleLogger $logger)
    {
        $this->logger = $logger;

        $this->loadDefaultConfiguration()
             ->loadUserConfiguration()
             ->loadCliConfiguration($input)
             ->processConfigurationValues($input);

        return $this;
    }

    /**
     * Loads default config from an expected location.
     *
     * @return Configurator
     **/
    private function loadDefaultConfiguration()
    {
        $defaultConfigFile = BASEPATH . '/app/default-config.yml';

        if (! file_exists($defaultConfigFile)) {
            $this->logger->error('Default YAML config file not found at: ' . realpath($defaultConfigFile));

            exit(1);
        }

        $this->defaults = Yaml::parse(file_get_contents($defaultConfigFile));
        $this->logger->debug('Default YAML config loaded from: ' . realpath($defaultConfigFile));

        return $this;
    }

    /**
     * Loads user config from root of app.
     *
     * @return Configurator
     **/
    private function loadUserConfiguration()
    {
        $userHomeConfigFile = USERHOME . '/.locomotive';
        $userConfigFile = BASEPATH . '/config.yml';

        if (file_exists($userHomeConfigFile)) {
            $this->user = Yaml::parse($userHomeConfigFile);
        } elseif (file_exists($userConfigFile)) {
            $this->user = Yaml::parse(file_get_contents($userConfigFile));
            $this->logger->debug('User YAML config loaded.');
        } else {
            $this->logger->warning('No user config file was found.');
            $this->user = array();
        }

        return $this;
    }

    /**
     * Loads in options from CLI and filters out any that are set to `null`.
     *
     * @param InputInterface  $input  An Input instance
     * 
     * @return Configurator
     **/
    private function loadCliConfiguration(InputInterface $input)
    {
        $this->cli = array_filter($input->getOptions(), function($item, $key) {
            if (! in_array($key, ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction'])) {
                return $item !== null;
            }
        }, ARRAY_FILTER_USE_BOTH);

        $this->logger->debug('CLI input cleaned for merging with config.');

        return $this;
    }

    /**
     * Validates and merges all expected sources of config variables, giving
     * precedence to user and CLI.
     *
     * @param InputInterface  $input  An Input instance
     * 
     * @return Configurator
     **/
    private function processConfigurationValues(InputInterface $input)
    {
        $configs = array($this->defaults, $this->user, $this->cli);

        $processor = new Processor();
        $configuration = new LocomoteConfiguration();
        $this->config = $processor->processConfiguration(
            $configuration,
            $configs
        );

        $this->logger->debug('Configs validated, merged, and loaded successfully.');

        return $this;
    }

    /**
     * Gets the config variable.
     *
     * @return array
     **/
    public function getConfig()
    {
        return $this->config;
    }
}
