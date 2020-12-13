<?php

/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
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
class Choose implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $eh = EventListener::new()
            ->addCommand("choose")
            ->setCallback([self::class, "choose"]);
        $bot->eventManager->addEventListener($eh);
    }

    public static function choose(EventData $data): PromiseInterface
    {
        $stuff = self::arg_substr($data->message->content, 1) ?? "";
        $choices = explode(",", $stuff);
        if (count($choices) < 2) {
            return $data->message->channel->send("you must give me at least two choices separated by commas! `!choose for example, like, this`");
        }
        $opt = $choices[array_rand($choices)];
        return $data->message->channel->send(sprintf("I choose `%s`!", $opt));
    }
}
