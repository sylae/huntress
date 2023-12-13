<?php

/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use Carbon\Carbon;
use CharlotteDunois\Collect\Collection;
use CharlotteDunois\Yasmin\Models\Message;
use CharlotteDunois\Yasmin\Models\TextChannel;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface;
use function React\Promise\all;

/**
 * Rules-read verification for the Masturbatorium.
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class Masturbatorium implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addCommand("verify")
                ->addGuild(349058708304822273)
                ->setCallback([self::class, "process"])
        );

        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addCommand("edge")
                ->addUser(297969955356540929)
                ->setCallback([self::class, "edge"])
        );
    }

    public static function process(EventData $data): ?PromiseInterface
    {
        $p = new Permission("p.masturbatorium.verify.enabled", $data->huntress, false);
        $p->addMessageContext($data->message);

        $promises = [];
        $promises[] = $data->message->delete();

        if ($p->resolve() && !$data->message->member->roles->has(674525922895855636)) {
            // check that they have the right code.
            $code = "2bnb00";
            if (mb_strtolower(self::arg_substr($data->message->content, 1)) == $code) {
                $promises[] = $data->message->member->addRole(674525922895855636);
                $promises[] = $data->message->channel->send(
                    "{$data->user}, thank you for reading the rules! You may now access keira's masturbatorium."
                );
            } else {
                $promises[] = $data->message->channel->send(
                    "{$data->user}, please try again. Have you read the rules?"
                );
            }
        }
        return all($promises);
    }

    public static function edge(EventData $data): ?PromiseInterface
    {
        try {
            $lastMonth = Carbon::now()->addMonths(-1);
            $data->message->delete();
            return self::_archive($data->huntress->channels->get(926829651370864650), $data, $lastMonth);
        } catch (\Throwable $e) {
            return self::exceptionHandler($data->message, $e);
        }
    }

    public static function _archive(
        TextChannel $ch,
        EventData $data,
        Carbon $lastMonth,
        Collection $carry = null
    ): PromiseInterface {
        try {
            if (is_null($carry)) {
                $args = ['limit' => 100];
            } else {
                $args = ['before' => $carry->min('id'), 'limit' => 100];
            }

            $ppl = [];
            $lastMonthB = $lastMonth->clone()->startOfMonth();
            $lastMonthE = $lastMonth->clone()->endOfMonth();

            return $ch->fetchMessages($args)->then(
                function ($msgs) use ($ch, $data, $carry, $ppl, $lastMonth, $lastMonthB, $lastMonthE) {
                    try {
                        if ($msgs->count() == 0) {
                            /** @var Message $msg */
                            foreach ($carry as $msg) {
                                if ($msg->createdTimestamp >= $lastMonthB->timestamp
                                    && $msg->createdTimestamp <= $lastMonthE->timestamp
                                    && is_numeric($msg->content)
                                ) {
                                    if (!array_key_exists($msg->author->id, $ppl)) {
                                        $ppl[$msg->author->id] = 0;
                                    }
                                    $ppl[$msg->author->id] += $msg->content;
                                }
                            }

                            arsort($ppl, SORT_NUMERIC);

                            $x = [];
                            $x[] = sprintf("**%s**", mb_strtoupper($lastMonth->format("F Y")));
                            foreach ($ppl as $k => $v) {
                                $x[] = sprintf("<@%s>: %s", $k, number_format($v, 0));
                            }

                            return $data->message->channel->send(implode(PHP_EOL, $x));
                        } else {
                            if (is_null($carry)) {
                                $carry = $msgs;
                            } else {
                                $carry = $carry->merge($msgs);
                                if ($carry->count() % 1000 == 0) {
                                    $rate = $carry->count() / (time() - $data->message->createdTimestamp);
                                }
                            }
                            return call_user_func([self::class, "_archive"], $ch, $data, $lastMonth, $carry);
                        }
                    } catch (\Throwable $e) {
                        return self::exceptionHandler($data->message, $e);
                    }
                }
            );
        } catch (\Throwable $e) {
            return self::exceptionHandler($data->message, $e);
        }
    }
}
