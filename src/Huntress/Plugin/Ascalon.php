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

/**
 * Deletes "permission denied" messages by Angush's bot.
 *
 * Most often used when Sidekick is around, as /r is a conflict for these two bots.
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class Ascalon implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $eh = EventListener::new()
            ->addEvent("message")
            ->addUser(198749794523545601)
            ->setCallback([self::class, "process"]);
        // @todo switch channel config over to HPM
        $bot->eventManager->addEventListener($eh);
    }

    public static function process(EventData $data)
    {
        $p = new Permission("p.ascalon.enabled", $data->huntress, false);
        $p->addChannelContext($data->channel);
        if ($p->resolve() && stripos($data->message->content, "you do not have permission to use this command.")) {
            return $data->message->delete();
        }
    }
}
