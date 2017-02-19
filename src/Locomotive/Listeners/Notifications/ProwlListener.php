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
 * @subpackage  Locomotive\Listeners\Notifications
 */

namespace Locomotive\Listeners\Notifications;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use League\Event\AbstractListener;
use League\Event\EventInterface;
use Monolog\Logger;

class ProwlListener extends AbstractListener
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
     * @var string
     */
    protected $endpoint;

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
        $this->endpoint = 'https://api.prowlapp.com/publicapi/add';
    }

    /**
     * @param EventInterface $event
     * @param null|string $param Expects the item name as a string
     */
    public function handle(EventInterface $event, $param = null)
    {
        $logger = $this->logger;
        $prowlConfig = $this->config['notifications']['prowl'];
        $eventName = $this->config['app']['language']['events'][explode('.', $event->getName())[1]];

        try {
            $client = new Client(['base_uri' => $this->endpoint]);
            $client->post($this->endpoint, [
                'form_params' => [
                    'apikey' => $prowlConfig['api-key'],
                    'application' => 'Locomotive',
                    'event' => $eventName,
                    'description' => $param
                ]
            ]);

            $logger->debug("Prowl API request succeeded for: $param");
        } catch (RequestException $e) {
            $logger->error($e->getMessage() . " [{$e->getRequest()->getMethod()}]");
        }
    }

}