<?php

/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use Carbon\Carbon;
use CharlotteDunois\Collect\Collection;
use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\Message;
use CharlotteDunois\Yasmin\Models\MessageReaction;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface;
use Throwable;
use function React\Promise\all;

/**
 * Simple builtin to show user information
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class Agenda implements PluginInterface
{
    use PluginHelperTrait;

    const CONF_TPL = [
        'staffRole' => 0,
        'tiebreakerRole' => 0,
        'quorum' => (2 / 3),
        'voteTypes' => [
            "For" => 394653535863570442,
            "Against" => 394653616050405376,
            "Abstain" => "👀",
            "Absent" => null,
        ],
    ];

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("agenda")
            ->setCallback([self::class, "agendaTallyHandler"])
        );
    }

    public static function agendaTallyHandler(EventData $data): ?PromiseInterface
    {
        try {
            $configs = new Collection();
            return $data->huntress->eventManager->fire("agendaPluginConf", $configs)->then(function () use (
                $data,
                $configs
            ) {
                if (!$configs->has($data->guild->id)) {
                    return null;
                }
                $conf = $configs->get($data->guild->id);
                return self::fetchMessage($data->huntress,
                    self::arg_substr($data->message->content, 1, 1))->then(function (Message $importMsg) use (
                    $data,
                    $conf
                ) {
                    try {
                        return all($importMsg->reactions->map(function (
                            MessageReaction $mr
                        ) {
                            return $mr->fetchUsers();
                        })->all())->then(function (array $reactUsers) use ($data, $importMsg, $conf) {

                            $x = $data->guild->members->filter(function (GuildMember $v) use ($conf) {
                                return $v->roles->has($conf['staffRole']);
                            })->map(function ($v) {
                                return null;
                            });
                            foreach ($reactUsers as $reactID => $users) {
                                foreach ($users as $user) {
                                    $x->set($user->id, $reactID);
                                }
                            }

                            $voteTypes = $conf['voteTypes'];

                            $total = $x->count();
                            $present = $x->filter(function ($v) {
                                return !is_null($v);
                            })->count();

                            $resp = [];
                            $resp[] = "__**" . $data->guild->name . " - Staff Motion Results**__";
                            $resp[] = sprintf("*Motion date:* `%s`, *tabulated:* `%s`",
                                Carbon::createFromTimestamp($importMsg->createdTimestamp)->toDateTimeString(),
                                Carbon::now()->toDateTimeString());
                            $resp[] = sprintf("*Motion proposed by:* `%s` (`%s`)", $importMsg->member->displayName,
                                $importMsg->author->tag);
                            $resp[] = "";

                            $qstr = sprintf("%s/%s staff voting (%s)", $present, $total,
                                number_format($present / $total * 100, 1) . "%");
                            if ($present >= $total * $conf['quorum']) {
                                $resp[] = "Quorum is present with " . $qstr;
                            } else {
                                $resp[] = "Quorum not present with " . $qstr;
                            }
                            $resp[] = "";
                            $resp[] = "Motion text:";
                            $resp[] = "> " . $importMsg->cleanContent;
                            $resp[] = "";

                            $totals = [];
                            foreach ($voteTypes as $type => $ident) {
                                $count = $x->filter(function ($v) use ($ident) {
                                    return $v == $ident;
                                });
                                $totals[$type] = $count->count();
                                $whomst = $count->map(function ($v, $k) use ($data) {
                                    return $data->guild->members->get($k)->user;
                                })->implode("tag", ", ");
                                $resp[] = sprintf("*%s*: %s (%s)", $type, $count->count(), $whomst);
                            }

                            $copyres = "";
                            if ($totals['For'] > $totals['Against']) {
                                // passed!
                                $resp[] = "**Motion passes**";
                                $copyres = "Passed";
                            } elseif ($totals['For'] == $totals['Against']) {
                                // tie - comm rep breaks
                                $rep = $data->guild->members->filter(function ($v) use ($conf) {
                                    return $v->roles->has($conf['tiebreakerRole']);
                                })->first();
                                switch ($x->get($rep->id)) {
                                    case $voteTypes['For']:
                                        $resp[] = "**Motion passes**";
                                        $copyres = "Passed";
                                        break;
                                    case $voteTypes['Against']:
                                        $resp[] = "**Motion fails**";
                                        $copyres = "Failed";
                                        break;
                                    default:
                                        $resp[] = "**Unbroken tie**";
                                        $copyres = "Failed (unbroken tie)";
                                }
                            } else {
                                // failed
                                $resp[] = "**Motion fails**";
                                $copyres = "Failed";
                            }
                            $copycount = sprintf("[%s for, %s against, %s abstained, %s absent]", $totals['For'],
                                $totals['Against'],
                                $totals['Abstain'],
                                $total - $present);

                            $resp[] = "";
                            $resp[] = "Copyable version:";
                            $resp[] = "```markdown";
                            $resp[] = $data->guild->name . " - Staff Motion Results **$copyres** $copycount";
                            $resp[] = $importMsg->cleanContent;
                            $resp[] = "```";


                            $data->message->channel->send(implode(PHP_EOL, $resp), ['split' => true]);
                        });
                    } catch (Throwable $e) {
                        return self::exceptionHandler($data->message, $e, true);
                    }
                });
            });
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true);
        }
    }
}
