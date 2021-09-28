<?php
/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;


use CharlotteDunois\Collect\Collection;
use CharlotteDunois\Yasmin\Models\Message;
use CharlotteDunois\Yasmin\Models\TextChannel;
use Doctrine\DBAL\Schema\Schema;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface;
use React\Promise\PromiseInterface as Promise;

class WormRPScoreboard implements PluginInterface
{
    use PluginHelperTrait;

    const CHANNEL = 890392395064172554;
    const SCOREMSG = "https://canary.discord.com/channels/118981144464195584/890392512466919484/892338038749929493";

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addCommand("score")
                ->addGuild(118981144464195584)
                ->setCallback([self::class, "score"])
        );

        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addEvent("dbSchema")
                ->setCallback([self::class, "db"])
        );

        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addChannel(self::CHANNEL)
                ->addEvent('message')
                ->setCallback([self::class, "messageListener"])
        );


        $bot->eventManager->addEventListener(
            EventListener::new()
                ->setCallback([self::class, "scoreboardUpdate",])
                ->setPeriodic(60)
        );
    }

    public static function db(Schema $schema): void
    {
        $t = $schema->createTable("wormrp_scoreboard");
        $t->addColumn("idMessage", "bigint", ["unsigned" => true]);
        $t->addColumn("idMember", "bigint", ["unsigned" => true]);
        $t->addColumn("time", "datetime");
        $t->setPrimaryKey(["idMessage"]);
        $t->addIndex(['idMember']);
    }

    public static function messageListener(EventData $data)
    {
        $query = $data->huntress->db->prepare(
            'replace into wormrp_scoreboard (idMessage, idMember, time) values (?, ?, ?)'
        );

        $query->bindValue(1, $data->message->id, 'integer');
        $query->bindValue(2, $data->message->author->id, 'integer');
        $query->bindValue(3, $data->message->createdAt, 'datetime');
        if (!$query->execute()) {
            $data->huntress->log->warning("WormRPScoreboard scanner failed to insert message id {$data->message->id}");
        }
        self::scoreboardUpdate($data->huntress);
    }

    public static function scoreboardUpdate(Huntress $bot): ?Promise
    {
        return self::fetchMessage($bot, self::SCOREMSG)->then(function (Message $msg) use ($bot) {
            return $msg->edit(self::formatStars($bot));
        });
    }

    public static function formatStars(Huntress $bot): string
    {
        $stats = self::getUserStats($bot);

        uasort(
            $stats,
            function ($b, $a) {
                return $a['posts'] <=> $b['posts'];
            }
        );

        $mwidth = [];

        $cols = ["User", "Posts"];
        foreach ($cols as $k => $v) {
            $mwidth[$k] = mb_strwidth($v);
        }

        $fmt = array_map(
            function ($v, $k) use ($bot, &$mwidth) {
                $x = [];

                $mem = $bot->users->get($k);
                if (!$mem) {
                    $x[0] = $k;
                } else {
                    $x[0] = $mem->tag;
                }

                $x[1] = str_repeat("⭐", $v['posts']);

                foreach ($x as $kk => $vv) {
                    $mwidth[$kk] = max($mwidth[$kk], mb_strwidth($vv));
                }
                return $x;
            },
            $stats,
            array_keys($stats)
        );

        $x = [];
        $x[] = "```";
        foreach ($fmt as $v) {
            $x[] = sprintf(
                "%s  %s",
                self::mb_str_pad($v[0], $mwidth[0], " ", STR_PAD_RIGHT),
                $v[1]
            );
        }
        $x[] = "```";
        return implode(PHP_EOL, $x);
    }

    private static function getUserStats(Huntress $bot): array
    {
        $members = [];
        $res = $bot->db->executeQuery("SELECT * FROM wormrp_scoreboard order by time asc")->fetchAllAssociative();
        foreach ($res as $row) {
            if (!array_key_exists($row['idMember'], $members)) {
                $members[$row['idMember']] = [
                    'posts' => 0,
                ];
            }
            $members[$row['idMember']]['posts']++;
        }

        return $members;
    }

    /**
     * Multibyte String Pad
     *
     * Functionally, the equivalent of the standard str_pad function, but is capable of successfully padding multibyte
     * strings.
     *
     * @link    https://gist.github.com/rquadling/c9ff12755fc412a6f0d38f6ac0d24fb1
     * @license unknown
     *
     * @param string $input    The string to be padded.
     * @param int    $length   The length of the resultant padded string.
     * @param string $padding  The string to use as padding. Defaults to space.
     * @param int    $padType  The type of padding. Defaults to STR_PAD_RIGHT.
     * @param string $encoding The encoding to use, defaults to UTF-8.
     *
     * @return string A padded multibyte string.
     */
    public static function mb_str_pad(
        string $input,
        int $length,
        string $padding = ' ',
        int $padType = STR_PAD_RIGHT,
        string $encoding = 'UTF-8'
    ) {
        $result = $input;
        if (($paddingRequired = $length - mb_strlen($input, $encoding)) > 0) {
            switch ($padType) {
                case STR_PAD_LEFT:
                    $result =
                        mb_substr(str_repeat($padding, $paddingRequired), 0, $paddingRequired, $encoding) .
                        $input;
                    break;
                case STR_PAD_RIGHT:
                    $result =
                        $input .
                        mb_substr(str_repeat($padding, $paddingRequired), 0, $paddingRequired, $encoding);
                    break;
                case STR_PAD_BOTH:
                    $leftPaddingLength = floor($paddingRequired / 2);
                    $rightPaddingLength = $paddingRequired - $leftPaddingLength;
                    $result =
                        mb_substr(str_repeat($padding, $leftPaddingLength), 0, $leftPaddingLength, $encoding) .
                        $input .
                        mb_substr(str_repeat($padding, $rightPaddingLength), 0, $rightPaddingLength, $encoding);
                    break;
            }
        }

        return $result;
    }

    public static function score(EventData $data): ?PromiseInterface
    {
        try {
            $arg = self::_split($data->message->content)[1] ?? "list";

            switch ($arg) {
                case "list":
                case "ls":
                case "users":
                    return self::listUsers($data);
                case "reset":
                case "wipe":
                case "recount":
                case "rescan":
                    $p = new Permission("p.wormrp.scoreboard.recount", $data->huntress, true);
                    $p->addMessageContext($data->message);
                    if (!$p->resolve()) {
                        return $p->sendUnauthorizedMessage($data->message->channel);
                    }

                    $ch = $data->guild->channels->get(self::CHANNEL);
                    $data->message->channel->send("Beginning $ch (`#{$ch->name}`) rescan...");
                    return self::_archive($ch, $data);
            }
            return $data->message->channel->send($arg);
        } catch (\Throwable $e) {
            return self::exceptionHandler($data->message, $e);
        }
    }

    public static function listUsers(EventData $data): ?PromiseInterface
    {
        return $data->message->reply(
            self::formatStars($data->huntress),
            ['split' => ['before' => '```json' . PHP_EOL, 'after' => '```']]
        );
    }

    public static function _archive(TextChannel $ch, EventData $data, Collection $carry = null)
    {
        try {
            if (is_null($carry)) {
                $args = ['limit' => 100];
            } else {
                $args = ['before' => $carry->min('id'), 'limit' => 100];
            }

            return $ch->fetchMessages($args)->then(
                function ($msgs) use ($ch, $data, $carry) {
                    try {
                        if ($msgs->count() == 0) {
                            $query = $data->huntress->db->prepare(
                                'replace into wormrp_scoreboard (idMessage, idMember, time) values (?, ?, ?)'
                            );
                            /** @var Message $msg */
                            foreach ($carry as $msg) {
                                $data->huntress->log->warning($msg->id);
                                if (in_array($msg->id, [890393716093779968, 890396609072992297])) {
                                    continue;
                                }
                                $query->bindValue(1, $msg->id, 'integer');
                                $query->bindValue(2, $msg->author->id, 'integer');
                                $query->bindValue(3, $msg->createdAt, 'datetime');
                                if (!$query->execute()) {
                                    $data->huntress->log->warning(
                                        "FuglyBob scanner failed to insert message id $msg->id"
                                    );
                                }
                            }

                            self::scoreboardUpdate($data->huntress);
                            return $data->message->channel->send("Done! {$carry->count()} messages on record.");
                        } else {
                            if (is_null($carry)) {
                                $carry = $msgs;
                            } else {
                                $carry = $carry->merge($msgs);
                                if ($carry->count() % 1000 == 0) {
                                    $rate = $carry->count() / (time() - $data->message->createdTimestamp);
                                    $data->message->channel->send(
                                        sprintf(
                                            "Progress: %s messages (%s/sec)",
                                            $carry->count(),
                                            number_format($rate)
                                        )
                                    );
                                }
                            }
                            return call_user_func([self::class, "_archive"], $ch, $data, $carry);
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
