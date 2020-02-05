<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface;

/**
 * Just some fun Huntress stuff.
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class Identity implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(EventListener::new()
            ->setCallback([self::class, "changeAv"])
            ->setPeriodic(60 * 60 * 24)
        );

        $bot->eventManager->addEventListener(EventListener::new()
            ->setCallback([self::class, "changeStatus"])
            ->setPeriodic(15)
        );
    }

    public static function changeAv(Huntress $bot): ?PromiseInterface
    {
        $bot->log->debug("Updating avatar...");
        return $bot->user->setAvatar("https://syl.ae/avatar.jpg")->then(function () use ($bot) {
            $bot->log->debug("Avatar update complete!");
        });
    }

    public static function changeStatus(Huntress $bot): ?PromiseInterface
    {
        $opts = [
            'with your heart',
            'with fire',
            'with a Cauldron vial',
            'with the server',
            'at being a real person',
            'with a ball of yarn',
            'RWBY: Grimm Eclipse',
        ];
        return $bot->user->setPresence([
            'status' => 'online',
            'game' => ['name' => $opts[array_rand($opts)], 'type' => 0],
        ]);
    }
}
