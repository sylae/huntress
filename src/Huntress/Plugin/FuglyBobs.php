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
use Doctrine\DBAL\Schema\Schema;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface;
use Sylae\Wordcount;

class FuglyBobs implements PluginInterface
{
    use PluginHelperTrait;

    const MODROLE = 786740721310367845;
    const CHANNEL = 786733453218414612;

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("fb")
            ->addGuild(786722067922944020)
            ->setCallback([self::class, "fb"])
        );

        $bot->eventManager->addEventListener(EventListener::new()
            ->addEvent("dbSchema")
            ->setCallback([self::class, "db"])
        );

        $bot->eventManager->addEventListener(EventListener::new()
            ->addChannel(self::CHANNEL)
            ->addEvent('message')
            ->setCallback([self::class, "messageListener"])
        );
    }

    public static function db(Schema $schema): void
    {
        $t = $schema->createTable("fuglybobs_posts");
        $t->addColumn("idMessage", "bigint", ["unsigned" => true]);
        $t->addColumn("idMember", "bigint", ["unsigned" => true]);
        $t->addColumn("time", "datetime");
        $t->addColumn("wordcount", "integer", ["unsigned" => true]);
        $t->setPrimaryKey(["idMessage"]);
        $t->addIndex(['idMember']);
    }

    public static function messageListener(EventData $data): ?PromiseInterface
    {
        $query = $data->huntress->db->prepare('replace into fuglybobs_posts (idMessage, idMember, time, wordcount) values (?, ?, ?, ?)');
        $wc = Wordcount::count($data->message->content);

        $query->bindValue(1, $data->message->id, 'integer');
        $query->bindValue(2, $data->message->author->id, 'integer');
        $query->bindValue(3, $data->message->createdAt, 'datetime');
        $query->bindValue(4, $wc, 'integer');
        if (!$query->execute()) {
            $data->huntress->log->warning("FuglyBob scanner failed to insert message id {$data->message->id}");
        }

    }

    public static function fb(EventData $data): ?PromiseInterface
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
                    $p = new Permission("p.fuglybobs.recount", $data->huntress, true);
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
        $stats = self::getUserStats($data->huntress);

        uasort($stats, function ($b, $a) {
            $x = $a['posts'] <=> $b['posts'];
            $y = $a['wc'] <=> $b['wc'];

            if ($x == 0) {
                return $y;
            }
            return $x;
        });

        $mwidth = [];

        $cols = ["User", "Posts", "Words"];
        foreach ($cols as $k => $v) {
            $mwidth[$k] = mb_strwidth($v);
        }

        $fmt = array_map(function ($v, $k) use ($data, &$mwidth) {
            $x = [];

            $mem = $data->huntress->users->get($k);
            if (!$mem) {
                $x[0] = $k;
            } else {
                $x[0] = $mem->tag;
            }

            $x[1] = number_format($v['posts'], 0);
            $x[2] = number_format($v['wc'], 0);

            foreach ($x as $kk => $vv) {
                $mwidth[$kk] = max($mwidth[$kk], mb_strwidth($vv));
            }
            return $x;
        }, $stats, array_keys($stats));

        $x = [];
        $x[] = "```";
        $x[] = sprintf("%s  %s  %s",
            str_pad($cols[0], $mwidth[0], " ", STR_PAD_RIGHT),
            str_pad($cols[1], $mwidth[1], " ", STR_PAD_LEFT),
            str_pad($cols[2], $mwidth[2], " ", STR_PAD_LEFT)
        );
        foreach ($fmt as $v) {
            $x[] = sprintf("%s  %s  %s",
                str_pad($v[0], $mwidth[0], " ", STR_PAD_RIGHT),
                str_pad($v[1], $mwidth[1], " ", STR_PAD_LEFT),
                str_pad($v[2], $mwidth[2], " ", STR_PAD_LEFT)
            );
        }
        $x[] = "```";

        return $data->message->channel->send(implode(PHP_EOL, $x),
            ['split' => ['before' => '```json' . PHP_EOL, 'after' => '```']]);
    }

    private static function getUserStats(Huntress $bot): array
    {
        $members = [];
        $res = $bot->db->executeQuery("SELECT * FROM fuglybobs_posts order by time asc")->fetchAllAssociative();
        foreach ($res as $row) {
            if (!array_key_exists($row['idMember'], $members)) {
                $members[$row['idMember']] = [
                    'posts' => 0,
                    'wc' => 0,
                    'lastPost' => Carbon::createFromTimestamp(0),
                ];
            }

            $members[$row['idMember']]['wc'] += $row['wordcount'];

            $t = new Carbon($row['time']);
            $cutoff = $members[$row['idMember']]['lastPost']->clone()->addMinutes(60);
            if ($t >= $cutoff) {
                $members[$row['idMember']]['posts']++;
            }

            $members[$row['idMember']]['lastPost'] = max($members[$row['idMember']]['lastPost'], $t);
        }

        return $members;
    }

    public static function _archive(TextChannel $ch, EventData $data, Collection $carry = null)
    {
        try {
            if (is_null($carry)) {
                $args = ['limit' => 100];
            } else {
                $args = ['before' => $carry->min('id'), 'limit' => 100];
            }

            return $ch->fetchMessages($args)->then(function ($msgs) use ($ch, $data, $carry) {
                try {
                    if ($msgs->count() == 0) {

                        $query = $data->huntress->db->prepare('replace into fuglybobs_posts (idMessage, idMember, time, wordcount) values (?, ?, ?, ?)');
                        /** @var Message $msg */
                        foreach ($carry as $msg) {
                            $wc = Wordcount::count($msg->content);

                            $query->bindValue(1, $msg->id, 'integer');
                            $query->bindValue(2, $msg->author->id, 'integer');
                            $query->bindValue(3, $msg->createdAt, 'datetime');
                            $query->bindValue(4, $wc, 'integer');
                            if (!$query->execute()) {
                                $data->huntress->log->warning("FuglyBob scanner failed to insert message id $msg->id");
                            }
                        }

                        return $data->message->channel->send("Done! {$carry->count()} messages on record.");
                    } else {
                        if (is_null($carry)) {
                            $carry = $msgs;
                        } else {
                            $carry = $carry->merge($msgs);
                            if ($carry->count() % 1000 == 0) {
                                $rate = $carry->count() / (time() - $data->message->createdTimestamp);
                                $data->message->channel->send(sprintf("Progress: %s messages (%s/sec)", $carry->count(),
                                    number_format($rate)));
                            }
                        }
                        return call_user_func([self::class, "_archive"], $ch, $data, $carry);
                    }
                } catch (\Throwable $e) {
                    return self::exceptionHandler($data->message, $e);
                }
            });
        } catch (\Throwable $e) {
            return self::exceptionHandler($data->message, $e);
        }
    }
}
