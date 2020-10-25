<?php
/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;


use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\PermissionOverwrite;
use CharlotteDunois\Yasmin\Models\Permissions;
use CharlotteDunois\Yasmin\Models\TextChannel;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Huntress\RSSProcessor;
use React\Promise\PromiseInterface;
use Throwable;
use function React\Promise\all;

class Ironreach implements PluginInterface
{
    use PluginHelperTrait;

    const COMREP_ROLE = 766785052163571734;
    const JURY_CHANNEL = 769771967606947840;
    const JURY_ROLE = 769772066371010590;

    public static function register(Huntress $bot)
    {
        if (self::isTestingClient()) {
            $bot->log->debug("Not adding RSS event on testing.");
        } else {
            new RSSProcessor($bot, 'ironreachTwitter', "https://queryfeed.net/tw?q=%40ironreach", 30,
                [755783497850814557]);
        }


        $eh = EventListener::new()
            ->addCommand("jury")
            ->setCallback([self::class, "jury"]);
        $bot->eventManager->addEventListener($eh);
    }

    public static function jury(EventData $data): ?PromiseInterface
    {
        try {
            $p = new Permission("p.ironreach.jury.summon", $data->huntress, false);
            $p->addMessageContext($data->message);
            if (!$p->resolve()) {
                return $p->sendUnauthorizedMessage($data->message->channel);
            }

            if (count(self::_split($data->message->content)) < 2) {
                return $data->message->channel->send("Usage: !jury (number|name)\n- specify a number to summon that many random people\n- specify a name (nick, Tag#1234, or @) to summon that particular member\nuse `!jury reset` to reset");
            }

            $arg = self::arg_substr($data->message->content, 1);

            /** @var TextChannel $channel */
            $channel = $data->guild->channels->get(self::JURY_CHANNEL);

            if ($arg == "reset") {
                return all($channel->permissionOverwrites->map(function (PermissionOverwrite $v) {
                    if ($v->type == "member") {
                        return $v->delete();
                    } else {
                        return null;
                    }
                })->all())->then(function () use ($data) {
                    return $data->message->channel->send("Permission overrides wiped.");
                });
            } elseif (is_numeric($arg)) {
                // summon that many users
                $jurists = $data->guild->members->filter(fn(GuildMember $v) => $v->roles->has(self::JURY_ROLE));
                $summon = $jurists->shuffle()->random((int)$arg);
            } else {
                // summon a guildmember
                $summon = self::parseGuildUser($data->message->guild, $arg);
                if (is_null($summon)) {
                    return $data->message->channel->send("I don't know who `$arg` is.");
                }

                // convert $summon to a collection in the jankiest possible way
                $summon = $data->guild->members->filter(fn($v) => $v->id == $summon->id);
            }

            $p = [];
            /** @var GuildMember $s */
            foreach ($summon as $s) {
                $p[] = $channel->overwritePermissions($s, Permissions::PERMISSIONS['VIEW_CHANNEL'], 0)->then(function (
                    $overwrites
                ) use ($channel, $s) {
                    return $channel->send("$s come here.");
                });
            }

            return all($p);

        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true);
        }
    }

}
