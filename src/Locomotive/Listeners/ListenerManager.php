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
 * @subpackage  Locomotive\Listeners
 */

namespace Locomotive\Listeners;

use Illuminate\Support\Collection;
use League\Event\Emitter;
use Monolog\Logger;

class ListenerManager
{

    /**
     * Sets up all emitter listeners.
     *
     * @param Emitter $emitter
     * @param array $config
     * @param Logger $logger
     */
    public static function setup(Emitter $emitter, array $config, Logger $logger)
    {
        // user post-process script(s) listener
        $emitter->addListener('event.transferComplete', new UserHookListener($config, $logger));

        // gather all enabled notification services and bind configured event listeners
        $notificationServices = Collection::make($config['notifications'])->whereStrict('enable', true);
        foreach ($notificationServices as $service => $params) {
            $listener = '\Locomotive\Listeners\Notifications\\' . ucfirst(strtolower($service)) . 'Listener';

            foreach ($params['events'] as $event) {
                $emitter->addListener("event.$event", new $listener($config, $logger));
            }
        }
    }

}