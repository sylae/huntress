<?php

/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Collect\Collection;
use CharlotteDunois\Yasmin\Models\Guild;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Huntress\ServerActivityMonitor;
use React\Promise\PromiseInterface;

/**
 * Use rrdtool to track some server statistics
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class ServerActivity implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(EventListener::new()
            ->addEvent("message")
            ->setCallback([self::class, "messageRX"])
        );

        $bot->eventManager->addEventListener(EventListener::new()
            ->addEvent("guildCreate")
            ->setCallback([self::class, "guildCreate"])
        );

        $bot->eventManager->addEventListener(EventListener::new()
            ->setPeriodic(6)
            ->setCallback([self::class, "updateRRD"])
        );
    }

    public static function updateRRD(Huntress $bot): ?PromiseInterface
    {
        static $counter = 0;
        $counter++;

        foreach ($bot->guilds as $guild) {
            if ($guild->id % 10 != $counter % 10) { // load balance
                continue;
            }
            $sam = self::getSAM($guild);
            $sam->commit();
        }

        return null;
    }

    private static function getSAM(Guild $guild): ServerActivityMonitor
    {
        static $sams;
        if (is_null($sams)) {
            $sams = new Collection();
        }

        if (!$sams->has($guild->id)) {
            $sams->set($guild->id, new ServerActivityMonitor($guild));
        }
        return $sams->get($guild->id);
    }

    public static function messageRX(EventData $data): ?PromiseInterface
    {
        $sam = self::getSAM($data->guild);
        $sam->addMessage($data->message);

        return null;
    }

    public static function guildCreate(EventData $data): ?PromiseInterface
    {
        $p = new Permission("p.serveractivity.track", $data->huntress, true);
        $p->addMessageContext($data->message);
        if (!$p->resolve()) {
            return null;
        }

        self::getSAM($data->guild);

        return null;
    }
}
