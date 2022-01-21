<?php

/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Collect\Collection;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface;
use Throwable;

/**
 * Games server!
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class Nash40k implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $eh2 = EventListener::new()
            ->setPeriodic(60 * 60)
            ->setCallback([self::class, "prideDiceChange"]);
        $bot->eventManager->addEventListener($eh2);

        $eh3 = EventListener::new()
            ->addCommand("icon")
            ->addGuild(933268211594584116)
            ->setCallback([self::class, "prideDice"]);
        $bot->eventManager->addEventListener($eh3);
    }

    public static function prideDice(EventData $data)
    {
        try {
            $p = new Permission("p.nash40k.changeicon", $data->huntress, false);
            $p->addMessageContext($data->message);
            if (!$p->resolve()) {
                return $p->sendUnauthorizedMessage($data->message->channel);
            }
            return self::prideDiceChange($data->huntress)->then(function ($guild) use ($data) {
                return $data->message->react("😤");
            });
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e);
        }
    }

    public static function prideDiceChange(Huntress $bot): ?PromiseInterface
    {
        try {
            $tracks = new Collection(glob("data/gayhammer/*.png"));
            $track = $tracks->random(1)->all();
            $track = mb_strtolower(array_pop($track));
            return $bot->guilds->get(933268211594584116)->setIcon($track, "owo trigger");
        } catch (Throwable $e) {
            $bot->log->warning($e->getMessage(), ['exception' => $e]);
        }
    }
}
