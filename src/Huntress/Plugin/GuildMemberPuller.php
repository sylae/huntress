<?php
/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;


use CharlotteDunois\Yasmin\Models\Guild;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface;
use function React\Promise\all;

class GuildMemberPuller implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(EventListener::new()
            ->setCallback([self::class, "updateGuilds"])
            ->setPeriodic(60)
        );
    }

    public static function updateGuilds(Huntress $bot): ?PromiseInterface
    {
        return all($bot->guilds->map(function (Guild $v) use ($bot) {
            if ($v->available && $v->memberCount != $v->members->count()) {
                $bot->log->debug(sprintf("Guild %s (%s) has %s members unfetched, fetching...", $v->id, $v->name,
                    $v->memberCount - $v->members->count()));
                return $v->fetchMembers()->then(function (Guild $g) use ($bot) {
                    $bot->log->debug(sprintf("Guild %s (%s) members fetched!", $g->id, $g->name));
                }, function ($e) use ($bot) {
                    $bot->log->warning($e->getMessage() ?? $e, ['exception' => $e]);
                });
            }
            return null;
        }));
    }

}
