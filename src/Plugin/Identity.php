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
class Identity implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;

    public static function register(\Huntress\Bot $bot)
    {
        $bot->client->on(self::PLUGINEVENT_READY, [self::class, "process"]);
    }

    public static function process(\Huntress\Bot $bot)
    {
        $bot->loop->addPeriodicTimer(60 * 60 * 24, function () use ($bot) {
            $bot->log->debug("Updating avatar...");
            $bot->client->user->setAvatar("https://syl.ae/avatar.jpg")->then(function () use ($bot) {
                $bot->log->debug("Avatar update complete!");
            });
        });
    }
}
