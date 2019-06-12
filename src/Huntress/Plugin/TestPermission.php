<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Throwable;

/**
 * Deletes "permission denied" messages by Angush's bot.
 *
 * Most often used when Sidekick is around, as /r is a conflict for these two bots.
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class TestPermission implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $eh = EventListener::new()
            ->addCommand("permissions")
            ->setCallback([self::class, "process"]);
        // @todo switch channel config over to HPM

        $bot->eventManager->addEventListener($eh);
    }

    public static function process(EventData $data)
    {
        try {
            $perm = new Permission("p.huntress.test", $data->message->client, false);
            $perm->addMessageContext($data->message);

            $debug = [];
            $can = $perm->resolve($debug);
            self::dump($data->channel, $debug);
            self::dump($data->channel, $can);
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e);
        }
    }
}
