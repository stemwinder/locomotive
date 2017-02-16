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
     * @param Logger          $logger A Monolog instance
     */
    public function __construct(InputInterface $input, Logger $logger)
    {
        $this->logger = $logger;

        $this->loadDefaultConfiguration()
             ->loadUserConfiguration()
             ->loadCliConfiguration($input)
             ->processConfigurationValues($input);
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
            $this->user = Yaml::parse(file_get_contents($userHomeConfigFile));
            $this->logger->debug('User YAML config loaded from: ' . $userHomeConfigFile);
        } elseif (file_exists($userConfigFile)) {
            $this->user = Yaml::parse(file_get_contents($userConfigFile));
            $this->logger->debug('User YAML config loaded from: ' . $userConfigFile);
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
            if (! in_array($key, ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction'], true)) {
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

        // protects `remove-source` from being set as `true` if null
        foreach ($configs as $configType => &$values) {
            if (
                isset($values['remove-sources'])
                && (
                    ! array_key_exists('remove', $values['remove-sources'])
                    || null === $values['remove-sources']['remove']
                )
            ) {
               $values['remove-sources']['remove'] = false;
            }
        }
        unset($values);

        $processor = new Processor();
        $configuration = new LocomoteConfiguration();
        $this->config = $processor->processConfiguration(
            $configuration,
            $configs
        );

        $this->parseSpeedSchedule();

        $this->logger->debug('Configs validated, merged, and loaded successfully.');

        return $this;
    }

    /**
     * Overides the speed limit based on scheduled values in the config file.
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
