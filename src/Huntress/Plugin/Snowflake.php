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
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface;

/**
 * Give the user a snowflake!
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class Snowflake implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $eh = EventListener::new()
            ->addCommand("snowflake")
            ->setCallback([self::class, "snow"]);
        $bot->eventManager->addEventListener($eh);
    }

    public static function snow(EventData $data): PromiseInterface
    {
        $snow = \Huntress\Snowflake::generate();
        $fmt = \Huntress\Snowflake::format($snow);
        return $data->message->channel->send(sprintf("`%s` (`%s`)", $fmt, $snow));
    }
}
