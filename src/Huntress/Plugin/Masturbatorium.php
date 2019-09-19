<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Collect\Collection;
use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\Message;
use CharlotteDunois\Yasmin\Models\VoiceChannel;
use CharlotteDunois\Yasmin\Utils\URLHelpers;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Huntress\RSSProcessor;
use React\Promise\ExtendedPromiseInterface as Promise;
use Throwable;
use function html5qp;

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
        $rss = new RSSProcessor($bot, 'WebtoonsBodies',
            'https://www.webtoons.com/en/challenge/bodies/rss?title_no=313877', 300,
            [465340599906729984]);
        $eh = EventListener::new()
            ->addEvent("message")
            ->addGuild(349058708304822273)
            ->setCallback([self::class, "owo"]);
        $bot->eventManager->addEventListener($eh);
    }

    public static function owo(EventData $data)
    {
        $is_manual = $data->message->content == "%name change" && $data->message->author->id == 297969955356540929;
        if (random_int(1, 1000) == 1 || $is_manual) {
            return URLHelpers::resolveURLToData("https://wormrp.syl.ae/wiki/OwO_Godrays")->then(function ($string) use ($data) {
                try {
                    $tracks = new Collection(html5qp($string, "table.tracklist td:nth-of-type(2)")->toArray());
                    $track = $tracks->map(function ($v) {
                        return $v->textContent;
                    })->filter(function ($v) {
                        return (trim($v) != "Untitled");
                    })->random(1)->all();
                    $track = mb_strtolower(trim(array_pop($track), "\""));
                    return $data->message->guild->setName($track, "owo trigger")->then(function ($guild) use ($data) {
                        return $data->message->react("😤");
                    });
                } catch (Throwable $e) {
                    return self::exceptionHandler($data->message, $e, true);
                }
            });
        }
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
