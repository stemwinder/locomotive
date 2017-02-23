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
 * @subpackage  Locomotive\Configuration
 */

namespace Locomotive\Configuration;

use Monolog\Logger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Yaml\Yaml;
use Carbon\Carbon;

class Configurator
{
    /**
     * @var Logger
     **/
    protected $logger;

    /**
     * @var array
     **/
    protected $defaults;

    /**
     * @var array
     **/
    protected $user;

    /**
     * @var array
     **/
    protected $cli;

    /**
     * @var array
     **/
    protected $config;

    /**
     * @var array
     */
    protected $app;

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
     * @param InputInterface $input An Input instance
     * @param Logger $logger A Monolog instance
     *
     * @throws \Symfony\Component\Yaml\Exception\ParseException
     */
    public function __construct(InputInterface $input, Logger $logger)
    {
        $this->logger = $logger;

        $this->loadDefaultConfiguration()
             ->loadUserConfiguration()
             ->loadCliConfiguration($input)
             ->loadAppConfiguration()
             ->processConfigurationValues();
    }

    /**
     * Loads default config from an expected location.
     *
     * @return Configurator
     *
     * @throws \Symfony\Component\Yaml\Exception\ParseException
     **/
    private function loadDefaultConfiguration()
    {
        $defaultConfigFile = BASEPATH . '/app/config/default-config.yml';

        if (!file_exists($defaultConfigFile)) {
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
     *
     * @throws \Symfony\Component\Yaml\Exception\ParseException
     **/
    private function loadUserConfiguration()
    {
        $configLocations = [
            USERHOME . '/.config/locomotive/config.yml',
            USERHOME . '/.locomotive',
            BASEPATH . '/config.yml'
        ];

        $userConfigFound = false;
        foreach ($configLocations as $configFile) {
            if (file_exists($configFile)) {
                $this->user = Yaml::parse(file_get_contents($configFile));
                $this->logger->debug('User YAML config loaded from: ' . $configFile);
                $userConfigFound = true;
            }
        }

        if ($userConfigFound !== true) {
            $this->logger->warning('No user config file was found.');
            $this->user = array();
        }

        return $this;
    }

    /**
     * Loads in options from CLI and filters out any that are set to `null`.
     *
     * @param InputInterface $input An Input instance
     *
     * @return Configurator
     **/
    private function loadCliConfiguration(InputInterface $input)
    {
        $this->cli = array_filter($input->getOptions(), function ($item, $key) {
            if (!in_array($key, ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction'], true)) {
                return $item !== null;
            }
        }, ARRAY_FILTER_USE_BOTH);

        $this->logger->debug('CLI input cleaned for merging with config.');

        return $this;
    }

    private function loadAppConfiguration()
    {
        $appConfigFile = BASEPATH . '/app/config/locomotive.yml';

        if (file_exists($appConfigFile)) {
            $this->app = Yaml::parse(file_get_contents($appConfigFile));
            $this->logger->debug('App YAML config loaded from: ' . realpath($appConfigFile));
        } else {
            $this->logger->critical('App YAML config file not found at: ' . realpath($appConfigFile));

            exit(1);
        }

        return $this;
    }

    /**
     * Validates and merges all expected sources of config variables, giving
     * precedence to user and CLI.
     *
     * @return Configurator
     **/
    private function processConfigurationValues()
    {
        $configs = array($this->defaults, $this->user, $this->cli);

        $processor = new Processor();
        $configuration = new LocomoteConfiguration();
        $this->config = $processor->processConfiguration(
            $configuration,
            $configs
        );

        $this->parseSpeedSchedule();

        // merges the application config file
        $this->config = array_merge($this->config, ['app' => $this->app]);

        $this->logger->debug('Configs validated, merged, and loaded successfully.');

        return $this;
    }

    /**
     * Overrides the speed limit based on scheduled values in the config file.
     *
     * @return Configurator
     **/
    private function parseSpeedSchedule()
    {
        // defer to speed limit set on command line
        if (array_key_exists('speed-limit', $this->cli)) {
            return $this;
        }

        if (count($this->config['speed-schedule']) > 0) {
            foreach ($this->config['speed-schedule'] as $schedule => $limit) {
                $schedule = explode('-', $schedule);
                $begin = Carbon::parse($schedule[0]);
                $end = Carbon::parse($schedule[1]);

                if (Carbon::now()->between($begin, $end)) {
                    $this->config['speed-limit'] = $limit;
                    $this->logger->info("The speed limit is being set from a schedule: $limit Bps.");
                }
            }
        }

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
