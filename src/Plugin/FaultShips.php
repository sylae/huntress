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
class FaultShips implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;

    public static function register(\Huntress\Bot $bot)
    {
        $bot->client->on(self::PLUGINEVENT_COMMAND_PREFIX . "ship", [self::class, "process"]);
    }

    public static function process(\Huntress\Bot $bot, \CharlotteDunois\Yasmin\Models\Message $message): ?\React\Promise\ExtendedPromiseInterface
    {
        try {
            if ($message->guild->id == "342212902595592192") {
                $t = self::_split($message->content);
                if (array_key_exists(1, $t) && $t[1] == "me") {
                    return self::send($message->channel, sprintf("%s x %s OTP", $message->member->displayName, self::getCape()));
                } else {
                    return self::send($message->channel, sprintf("%s x %s OTP", self::getCape(), self::getCape()));
                }
            } else {
                return null;
            }
        } catch (\Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }

    public static function getCape(): string
    {
        $capes = [
            'Astroglide',
            'Basilisk',
            'Black Witch',
            'Blink',
            'Jessica Hernandez',
            'Bloodletter',
            'Branwen',
            'Audun Grovsmed',
            'Iona Grovsmed',
            'Bunker',
            'Chozo',
            'Cupid',
            'Director Mayer',
            'Eimyrja',
            'Encore',
            'Epione',
            'Flashstep',
            'Icarus',
            'Imperium',
            'Jade',
            'Kaboom',
            'Jennifer Mitchell',
            'Papercut',
            'Potion',
            'Recollect',
            'Red Light',
            'Rewind',
            'Spellbound',
            'Starving Artist',
            'Starving Artist',
            'Tank Buster',
            'Treant',
            'Tundra',
            'Umbra',
            'Woodwind',
            'Dr. Larry',
            'Stan',
            'Grandiose',
            'Torque',
            'The Simurgh',
        ];
        return $capes[array_rand($capes)];
    }
}
