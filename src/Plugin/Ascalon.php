<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

/**
 * Simple builtin to show user information
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class Ascalon implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;

    public static function register(\Huntress\Bot $bot)
    {
        $bot->client->on(self::PLUGINEVENT_MESSAGE, [self::class, "process"]);
    }

    public static function process(\Huntress\Bot $bot, \CharlotteDunois\Yasmin\Models\Message $message): ?\React\Promise\ExtendedPromiseInterface
    {
        $asc = [
            "450916960318783488", // nh botspam
            "370727342076854302", // mast botspam
        ];
        if ($message->author->id == "198749794523545601" && in_array($message->channel->id, $asc) && stripos($message->content, "you do not have permission to use this command.")) {
            return $message->delete();
        }
        return null;
    }
}
