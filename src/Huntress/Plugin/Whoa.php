<?php

/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Yasmin\Models\MessageEmbed;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface;
use function React\Promise\all;

/**
 * temp slowmode goes brrrrrr
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class Whoa implements PluginInterface
{
    const HOWLONG = 60 * 15;
    const DEFAULTWHOA = 15;
    const MAX_WHOA = 60 * 60 * 6;
    const WHOARATE = 2;

    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("whoa")
            ->addCommand("slowmode")
            ->setCallback([self::class, "process"]));
    }

    public static function process(EventData $data): ?PromiseInterface
    {
        $p = new Permission("p.moderation.whoa", $data->huntress, false);
        $p->addMessageContext($data->message);
        if (!$p->resolve()) {
            return $p->sendUnauthorizedMessage($data->message->channel);
        }

        $current = $data->message->channel->slowmode ?? 0;

        $args = self::_split($data->message->content);
        if (count($args) >= 2 && $args[1] == "help") {
            return $data->message->channel->send("usage: `!whoa`\n" .
                "Set a slowmode that expires after a bit.\n\n" .
                "Optional args: `!whoa SLOWNESS DURATION`\n" .
                "SLOWNESS = slowmode amount in seconds. Default: " . self::DEFAULTWHOA . "sec.\n" .
                "DURATION = how long its enabled, in minutes. Default: " . (self::HOWLONG / 60) . "min.\n\n" .
                "Note that Huntress will forget to reset the slowmode if she crashes, because her developer is lazy."
            );
        }

        if (count($args) >= 2 && is_numeric($args[1])) {
            $time = (int)$args[1];
        } else {
            $time = self::getSlowmodeTime($current);
        }

        if (count($args) == 3 && is_numeric($args[2])) {
            $length = (int)$args[2] * 60;
        } else {
            $length = self::HOWLONG;
        }


        $x = [];
        $x[] = $data->message->channel->setSlowmode($time, $data->message->author->tag);

        $data->huntress->loop->addTimer($length, function () use ($data, $current) {
            $data->message->channel->setSlowmode($current, "auto-whoa expired");

            $embed = new MessageEmbed();
            $embed->setTitle("SLOWMODE RETURNED");
            $embed->setColor(0xd10000);
            $embed->setDescription(sprintf("Channel reverted to previous slowmode of %s seconds.", $current));

            $data->message->channel->send("", ['embed' => $embed]);
        });

        $embed = new MessageEmbed();
        $embed->setTitle("SLOWMODE ENABLED");
        $embed->setColor(0xd10000);
        $embed->setDescription(
            sprintf("Channel is on %s second slowmode for the next %s minutes.", $time, $length / 60)
        );
        $embed->setTimestamp(time() + $length);

        $x[] = $data->message->channel->send("", ['embed' => $embed]);

        return all($x);
    }

    private static function getSlowmodeTime(int $current): int
    {
        if ($current == 0) {
            return self::DEFAULTWHOA;
        } elseif ($current * self::WHOARATE <= self::MAX_WHOA) {
            return $current * self::WHOARATE;
        } else {
            return self::MAX_WHOA;
        }
    }

}
