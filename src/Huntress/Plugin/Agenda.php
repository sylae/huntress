<?php

/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use Carbon\Carbon;
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

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("agenda")
            ->addGuild(118981144464195584)
            ->setCallback([self::class, "agendaTallyHandler"])
        );
    }

    public static function agendaTallyHandler(EventData $data): ?PromiseInterface
    {
        try {
            return self::fetchMessage($data->huntress,
                self::arg_substr($data->message->content, 1, 1))->then(function (Message $importMsg) use ($data) {
                try {
                    return all($importMsg->reactions->map(function (
                        MessageReaction $mr
                    ) {
                        return $mr->fetchUsers();
                    })->all())->then(function (array $reactUsers) use ($data, $importMsg) {

                        $x = $data->guild->members->filter(function (GuildMember $v) {
                            return $v->roles->has(456321111945248779);
                        })->map(function ($v) {
                            return null;
                        });
                        foreach ($reactUsers as $reactID => $users) {
                            foreach ($users as $user) {
                                $x->set($user->id, $reactID);
                            }
                        }

                        $voteTypes = [
                            "For" => 394653535863570442,
                            "Against" => 394653616050405376,
                            "Abstain" => "👀",
                            "Absent" => null,
                        ];
                        $total = $x->count();
                        $present = $x->filter(function ($v) {
                            return !is_null($v);
                        })->count();

                        $resp = [];
                        $resp[] = "__**WormRP - Staff Motion Results**__";
                        $resp[] = sprintf("*Motion date:* `%s`, *tabulated:* `%s`",
                            Carbon::createFromTimestamp($importMsg->createdTimestamp)->toDateTimeString(),
                            Carbon::now()->toDateTimeString());
                        $resp[] = sprintf("*Motion proposed by:* `%s` (`%s`)", $importMsg->member->displayName,
                            $importMsg->author->tag);
                        $resp[] = "";

                        $qstr = sprintf("%s/%s staff voting (%s)", $present, $total,
                            number_format($present / $total * 100, 1) . "%");
                        if ($present >= $total * (2 / 3)) {
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
                            $rep = $data->guild->members->filter(function ($v) {
                                return $v->roles->has(492912331857199115);
                            })->first();
                            switch ($x->get($rep->id)) {
                                case $voteTypes['For']:
                                    $resp[] = "**Motion passes**";
                                    $copyres = "Passed";
                                case $voteTypes['Against']:
                                    $resp[] = "**Motion fails**";
                                    $copyres = "Failed";
                                default:
                                    $resp[] = "**Unbroken tie**";
                                    $copyres = "Failed (unbroken tie)";
                            }
                        } else {
                            // failed
                            $resp[] = "**Motion fails**";
                            $copyres = "Failed";
                        }
                        $copycount = sprintf("[%s for, %s against, %s abstained]", $totals['For'], $totals['Against'],
                            $totals['Abstain']);

                        $resp[] = "";
                        $resp[] = "Copyable version:";
                        $resp[] = "```markdown";
                        $resp[] = "WormRP - Staff Motion Results **$copyres** $copycount";
                        $resp[] = $importMsg->cleanContent;
                        $resp[] = "```";


                        $data->message->channel->send(implode(PHP_EOL, $resp), ['split' => true]);
                    });
                } catch (Throwable $e) {
                    return self::exceptionHandler($data->message, $e, true);
                }
            });
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true);
        }
    }
}
