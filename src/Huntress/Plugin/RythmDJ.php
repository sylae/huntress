<?php

/**
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;

/**
 * The Rythm bot does not permit @everyone to have DJ features. This plugin hunts for a @DJ role and adds it upon voice
 * joining
 */
class RythmDJ implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $eh = EventListener::new()
            ->addEvent("voiceStateUpdate") // todo: split this into add and leave events?
            ->setCallback([self::class, "process"]);
        $bot->eventManager->addEventListener($eh);
    }

    public static function process(EventData $data)
    {
        $p = new Permission("p.rythmdj.enabled", $data->huntress, false);
        $p->addChannelContext($data->channel);
        if ($p->resolve()) {
            $match = $data->guild->roles->filter(function ($v) {
                return $v->name == "DJ";
            });
            if ($match->count() != 1) {
                $data->huntress->log->notice(sprintf("RythmDJ enabled for guild %s but no @DJ role found!",
                    $data->guild->name));
                return;
            }
            $role = $match->first();

            if (!$data->user->roles->has($role->id)) {
                $data->huntress->log->info(sprintf("Giving user %s the DJ role in guild %s", $data->user->displayName,
                    $data->guild->name));
                return $data->user->addRole($role);
            }
        }
    }
}
