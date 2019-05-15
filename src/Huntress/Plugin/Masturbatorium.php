<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\Message;
use CharlotteDunois\Yasmin\Models\VoiceChannel;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\ExtendedPromiseInterface as Promise;
use Throwable;

/**
 * Simple builtin to show user information
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class Masturbatorium implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->on("voiceStateUpdate", [self::class, "voiceStateHandler"]);
        $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "modlog", [self::class, "modlog"]);
        $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "zoe", [self::class, "honk"]);
    }

    public static function voiceStateHandler(
        GuildMember $new,
        ?GuildMember $old
    ) {
        if ($new->guild->id == 349058708304822273 && $new->voiceChannel instanceof VoiceChannel) {
            $role = $new->guild->roles->get(455005371208302603);
            if (is_null($new->roles->get(455005371208302603))) {
                $new->addRole($role)->then(function () use ($new) {
                    self::send($new->guild->channels->get(455013336833327104),
                        "<@{$new->id}>, I'm going to give you the DJ role, since you're joining a voice chat.");
                });
            }
        }
    }

    public static function modlog(Huntress $bot, Message $message): ?Promise
    {
        if (is_null($message->member->roles->get(446317817604603904))) {
            return self::unauthorized($message);
        } else {
            try {
                $args = self::_split($message->content);
                $msg = str_replace($args[0], "", $message->content);

                return self::send($message->channels->get(446320118784589826), $msg);
            } catch (Throwable $e) {
                return self::exceptionHandler($message, $e, true);
            }
        }
    }

    public static function honk(Huntress $bot, Message $message): ?Promise
    {
        try {
            return self::send($message->channel, "https://www.youtube.com/watch?v=hb3lnUx0xO0");
        } catch (Throwable $e) {
            return self::exceptionHandler($message, $e, true);
        }
    }
}
