<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

/**
 * Simple builtin to show user information
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class PCT implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;

    public static function register(\Huntress\Bot $bot)
    {
        $bot->client->on(self::PLUGINEVENT_DB_SCHEMA, [self::class, "db"]);
        $bot->client->on(self::PLUGINEVENT_READY, [self::class, "sbHell"]);
    }

    public static function db(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        $t = $schema->createTable("pct_sbhell");
        $t->addColumn("idTopic", "integer");
        $t->addColumn("timeTopicPost", "datetime");
        $t->addColumn("timeLastReply", "datetime");
        $t->setPrimaryKey(["idTopic"]);
    }

    public static function sbHell(\Huntress\Bot $bot)
    {
        $bot->loop->addPeriodicTimer(60, function() use ($bot) {
            if (php_uname('s') == "Windows NT") {
                return null; // don't run on testing because oof
            }
            return \CharlotteDunois\Yasmin\Utils\URLHelpers::resolveURLToData("https://forums.spacebattles.com/forums/worm.115/")->then(function(string $string) use ($bot) {
                $data	 = \html5qp($string);
                $items	= $data->find('li.discussionListItem');
                foreach ($items as $item) {
                    $x = (object) [
                        'id' => (int) str_replace("thread-", "", $item->attr("id")),
                        'title' => trim($item->find('h3')->text()),
                        'threadTime' => self::unfuckDates($item->find(".startDate .DateTime")),
                        'replyTime' => self::unfuckDates($item->find(".lastPost .DateTime")),
                        'author' => [
                            'name' => $item->find('.posterDate a.username')->text(),
                            'av' => "https://forums.spacebattles.com/" . $item->find('.posterAvatar img')->attr("src"),
                            'url' => "https://forums.spacebattles.com/" . $item->find('.posterDate a.username')->attr("href"),
                        ],
                        'replier' => [
                            'name' => $item->find('.lastPost a.username')->text(),
                            'av' => null,
                        ],
                        'numReplies' => (int) trim($item->find('.stats .major dd')->text()),
                        'numViews' => (int) str_replace(",", "", $item->find('.stats .minor dd')->text()),
                        'wordcount' => str_replace("Word Count: ", "", $item->find(".posterDate a.OverlayTrigger")->text()),
                    ];
                    $embed = new \CharlotteDunois\Yasmin\Models\MessageEmbed();
                    $embed->setTitle($x->title)->setColor(0x00ff00)
                    ->setURL("https://forums.spacebattles.com/threads/{$x->id}/")
                    ->setAuthor($x->author['name'], $x->author['av'], $x->author['url'])
                    ->addField("Created", $x->threadTime->toFormattedDateString(), true)
                    ->addField("Replies", sprintf("%s (%s pages)", number_format($x->numReplies), number_format(ceil($x->numReplies/25))), true)
                    ->addField("Views", number_format($x->numViews), true)
                    ->setFooter("Last reply")
                    ->setTimestamp($x->replyTime->timestamp);
                    
                    if (mb_strlen($x->wordcount) > 0) {
                        $embed->addField("Wordcount", $x->wordcount, true);
                    }

                    if (!self::alreadyPosted($x)) {
                        $bot->client->channels->get("514258427258601474")->send("", ['embed' => $embed])->then(function ($message) use ($x) {
                            $query = \Huntress\DatabaseFactory::get()->prepare('INSERT INTO pct_sbhell (`idTopic`, `timeTopicPost`, `timeLastReply`) VALUES(?, ?, ?) '
                            . 'ON DUPLICATE KEY UPDATE `timeLastReply`=VALUES(`timeLastReply`);', ['string', 'datetime', 'datetime']);
                            $query->bindValue(1, $x->id);
                            $query->bindValue(2, $x->threadTime);
                            $query->bindValue(3, $x->replyTime);
                            $query->execute();
                        });
                    }
                }
            });
        });
    }

    /**
     * Spacebattles sends us dates in...weird formats. Standardize and parse them.
     * @param \QueryPath\DOMQuery $qp
     * @return \Carbon\Carbon
     * @throws \LogicException
     */
     private static function unfuckDates(\QueryPath\DOMQuery $qp): \Carbon\Carbon
     {
     	if ($qp->is("span")) {
     		$obj = new \Carbon\Carbon(str_replace(" at", "", $qp->text()), "America/New_York");
     	} elseif ($qp->is("abbr")) {
     		$obj = new \Carbon\Carbon(date('c', $qp->attr("data-time")));
     	} else {
     		throw new \LogicException("what the fuck");
     	}
     	$obj->setTimezone("UTC");
     	return $obj;
     }

    private static function alreadyPosted(\stdClass $post): bool
    {
        $qb = \Huntress\DatabaseFactory::get()->createQueryBuilder();
        $qb->select("*")->from("pct_sbhell")->where('`idTopic` = ?')->setParameter(0, $post->id, "integer");
        $res = $qb->execute()->fetchAll();
        foreach ($res as $data) {
            return true;
        }
        return false;
    }
}
