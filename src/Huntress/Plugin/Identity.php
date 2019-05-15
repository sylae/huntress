<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;

/**
 * Simple builtin to show user information
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class Identity implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->on(self::PLUGINEVENT_READY, [self::class, "process"]);
    }

    public static function process(Huntress $bot)
    {
        $bot->loop->addPeriodicTimer(60 * 60 * 24, function () use ($bot) {
            $bot->log->debug("Updating avatar...");
            $bot->user->setAvatar("https://syl.ae/avatar.jpg")->then(function () use ($bot) {
                $bot->log->debug("Avatar update complete!");
            });
        });
        $bot->loop->addPeriodicTimer(15, function () use ($bot) {
            $opts = [
                'with your heart',
                'with fire',
                'with a Cauldron vial',
                'with the server',
                'at being a real person',
                'with a ball of yarn',
                'RWBY: Grimm Eclipse',
            ];
            $bot->user->setPresence([
                'status' => 'online',
                'game' => ['name' => $opts[array_rand($opts)], 'type' => 0],
            ]);
        });
    }
}
