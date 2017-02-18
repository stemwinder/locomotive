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
 * @subpackage  Locomotive\Listeners
 */

namespace Locomotive\Listeners;

use League\Event\AbstractListener;
use League\Event\EventInterface;
use Monolog\Logger;

class UserHookListener extends AbstractListener
{

    /**
     * @var array
     */
    protected $config;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var array
     */
    protected $processors;

    /**
     * UserHookListener constructor.
     *
     * @param array $config Locomotive config options
     * @param Logger $logger
     */
    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->processors = null;

        if (count($config['post-processors']) > 0) {
            $this->processors = $config['post-processors'];
        }
    }

    /**
     * @param EventInterface $event
     * @param null|string $param Expects the item name as a string
     */
    public function handle(EventInterface $event, $param = null)
    {
        if (null !== $this->processors) {
            foreach ($this->processors as $processor) {
                exec("nohup $processor \"$param\" > /dev/null 2>&1 & echo $!", $output, $exitCode);

                if ((int)$exitCode !== 0) {
                    $this->logger->warning('User script error: ' . implode(' ', $output));
                } else {
                    $this->logger->debug("Executed user script: [$output[0]] `$processor \"$param\"`");
                }
            }
        }
    }

}