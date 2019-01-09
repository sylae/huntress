<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use Carbon\Carbon;
use CharlotteDunois\Yasmin\Utils\URLHelpers;
use \CharlotteDunois\Yasmin\Utils\Collection;

/**
 * Simple builtin to show user information
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class Cauldron2018 implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;

    public static function register(\Huntress\Bot $bot)
    {
        $bot->client->on(self::PLUGINEVENT_COMMAND_PREFIX . "_c2018Import", [self::class, "process"]);
    }

    public static function process(\Huntress\Bot $bot, \CharlotteDunois\Yasmin\Models\Message $message): \React\Promise\ExtendedPromiseInterface
    {
        if (!in_array($message->author->id, $bot->config['evalUsers'])) {
            return self::unauthorized($message);
        } else {
            try {
                $args = self::_split($message->content);
                $mess = explode("\n", trim(str_replace($args[0], "", $message->content)));

                $iarna = new \Huntress\Library();
                $iarna->loadFanfic();
                $db    = \Huntress\DatabaseFactory::get();

                $x        = $db->executeQuery("select * from cauldron2018.fics where author is not null")->fetchAll();
                $fics     = (new Collection($x))->keyBy("idFic");
                $x        = $db->executeQuery("select * from cauldron2018.contests")->fetchAll();
                $contests = (new Collection($x))->keyBy("idContest");

                $cat = array_shift($mess);

                $cat_id = $contests->first(function ($v, $k) use ($cat) {
                    return $v['title'] == $cat;
                })['idContest'] ?? null;

                $nomatch = [];
                foreach ($mess as $fic) {
                    $fic_id = $fics->first(function ($v, $k) use ($fic) {
                        return $v['title'] == $fic;
                    })['idFic'] ?? null;
                    if (is_null($fic_id)) {
                        $nomatch[] = $fic;
                    } else {
                        $q = $db->prepare('INSERT IGNORE INTO cauldron2018.entries (idContest, idFic) VALUES(?, ?);', ['integer', 'integer']);
                        $q->bindValue(1, $cat_id);
                        $q->bindValue(2, $fic_id);
                        $q->execute();
                        $message->channel->send("`$cat_id / $fic_id` inserted.");
                    }
                }

                foreach ($nomatch as $row) {
                    $key = $iarna->search(function ($v, $k) use ($row) {
                        return (trim(mb_strtolower($row)) == trim(mb_strtolower($v->title)));
                    });
                    if ($key) {
                        $fic = $iarna->get($key);
                        $ins = [];
                        foreach ($fic->links as $link) {
                            $tag       = self::storyURL($link);
                            $ins[$tag] = $link;
                        }
                        $ins['author'] = $fic->author;
                        $qb            = $db->createQueryBuilder()->insert("cauldron2018.fics");
                        $qb->setValue('title', ':title')->setParameter(':title', $row);
                        foreach ($ins as $col => $val) {
                            $qb->setValue($col, ":$col");
                            $qb->setParameter(":$col", $val);
                        }
                        $qb->execute();
                        $message->channel->send("`{$row}` inserted as new fic, please rerun command.");
                    }
                    $message->channel->send("`{$row}` not found. please correct and try again.");
                }
            } catch (\Throwable $e) {
                return self::exceptionHandler($message, $e, true);
            }
        }
    }

    private static function storyURL(string $url): string
    {
        $regex   = "/https?\\:\\/\\/(.+?)\\//i";
        $matches = [];
        if (preg_match($regex, $url, $matches)) {
            switch ($matches[1]) {
                case "forums.spacebattles.com":
                    $tag = "linkSB";
                    break;
                case "forums.sufficientvelocity.com":
                    $tag = "linkSV";
                    break;
                case "archiveofourown.org":
                    $tag = "linkAO3";
                    break;
                case "www.fanfiction.net":
                case "fanfiction.net":
                    $tag = "linkFFN";
                    break;
                case "forum.questionablequesting.com":
                case "questionablequesting.com":
                    $tag = "linkQQ";
                    break;
                default:
                    $tag = "linkOther";
            }
            return $tag;
        }
        return "linkOther";
    }
}
