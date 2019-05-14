<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use \Huntress\Huntress;

/**
 * Deletes "permission denied" messages by Angush's bot.
 *
 * Most often used when Sidekick is around, as /r is a conflict for these two bots.
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class Ascalon implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $eh = EventListener::new()
            ->addEvent("message")
            ->addUser(198749794523545601)
            ->addChannel(370727342076854302)// mast botspam
            ->addChannel(368669692665004032)// mast roleplaying
            ->addChannel(561118174322360322)// nash botspam
            ->addChannel(570692417049591818)// wd portland gn-ideas
            ->setCallback([self::class, "process"]);
        // @todo switch channel config over to HPM

        $bot->eventManager->addEventListener($eh);
    }

    public static function process(\Huntress\EventData $data)
    {
        if (stripos($data->message->content, "you do not have permission to use this command.")) {
            return $data->message->delete();
        }
    }
}
