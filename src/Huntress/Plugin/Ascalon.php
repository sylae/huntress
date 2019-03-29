<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use \Huntress\Huntress;
use \React\Promise\ExtendedPromiseInterface as Promise;

/**
 * Deletes "permission denied" messages by Angush's bot.
 *
 * Most often used when Sidekick is around, as /r is a conflict for these two bots.
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class Ascalon implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->on(self::PLUGINEVENT_MESSAGE, [self::class, "process"]);
    }

    public static function process(Huntress $bot, \CharlotteDunois\Yasmin\Models\Message $message): ?Promise
    {
        $asc = [
            450916960318783488, // nh botspam
            370727342076854302, // mast botspam
            368669692665004032, // mast roleplaying
            472050918263750656, // mast lewdfinders
            508882422939779103, // mast lewdfinders-ic
            511055798693134349, // void blossom botspam
            561118174322360322, // nash botspam
        ];
        if ($message->author->id == 198749794523545601 && in_array($message->channel->id, $asc) && stripos($message->content, "you do not have permission to use this command.")) {
            return $message->delete();
        }
        return null;
    }
}
