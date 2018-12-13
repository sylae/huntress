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
        $bot->client->on(self::PLUGINEVENT_READY, [self::class, "theVolcano"]);
        $bot->client->on(self::PLUGINEVENT_READY, [self::class, "sbHell"]);
        $bot->client->on(self::PLUGINEVENT_COMMAND_PREFIX . "_PCTInternalSetWelcomeMessage", [self::class, "setWelcome"]);
        $bot->client->on("guildMemberAdd", [self::class, "guildMemberAddHandler"]);
    }

    public static function db(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        $t = $schema->createTable("pct_sbhell");
        $t->addColumn("idTopic", "integer");
        $t->addColumn("timeTopicPost", "datetime");
        $t->addColumn("timeLastReply", "datetime");
        $t->setPrimaryKey(["idTopic"]);

        $t2 = $schema->createTable("pct_config");
        $t2->addColumn("key", "string", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t2->addColumn("value", "text", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t2->setPrimaryKey(["key"]);
    }

    public static function setWelcome(\Huntress\Bot $bot, \CharlotteDunois\Yasmin\Models\Message $message): \React\Promise\ExtendedPromiseInterface
    {
        if (is_null($message->member->roles->get(406698099143213066))) {
            return self::unauthorized($message);
        } else {
            try {
                $args = self::_split($message->content);
                if (count($args) < 2) {
                    return self::error($message, "You dipshit :open_mouth:", "!_PCTInternalSetWelcomeMessage This is where you put the message\n%s = username");
                }
                $welcomeMsg = trim(str_replace($args[0], "", $message->content));
                $query      = \Huntress\DatabaseFactory::get()->prepare('INSERT INTO pct_config (`key`, `value`) VALUES(?, ?) '
                . 'ON DUPLICATE KEY UPDATE `value`=VALUES(`value`);', ['string', 'string']);
                $query->bindValue(1, "serverWelcomeMessage");
                $query->bindValue(2, $welcomeMsg);
                $query->execute();
                return self::send($message->channel, self::formatWelcomeMessage($message->author));
            } catch (\Throwable $e) {
                return self::exceptionHandler($message, $e, true);
            }
        }
    }

    public static function guildMemberAddHandler(\CharlotteDunois\Yasmin\Models\GuildMember $member): ?\React\Promise\ExtendedPromiseInterface
    {
        if ($member->guild->id != 397462075418607618) {
            return null;
        }
        return $member->addRole(463601286260981763, "new user setup")->then(function(\CharlotteDunois\Yasmin\Models\GuildMember $member) {
            return $member->setNickname("Recruit {$member->displayName}", "new user setup")->then(function(\CharlotteDunois\Yasmin\Models\GuildMember $member) {
                return self::send($member->guild->channels->get(397462075896627221), self::formatWelcomeMessage($member->user));
            });
        });
    }

    private static function formatWelcomeMessage(\CharlotteDunois\Yasmin\Models\User $member)
    {
        return sprintf(self::getWelcomeMessage(), (string) $member);
    }

    private static function getWelcomeMessage(): string
    {
        $qb  = \Huntress\DatabaseFactory::get()->createQueryBuilder();
        $qb->select("*")->from("pct_config")->where('`key` = ?')->setParameter(0, 'serverWelcomeMessage', "string");
        $res = $qb->execute()->fetchAll();
        foreach ($res as $data) {
            return $data['value'];
        }
        return "Welcome to PCT, %s!";
    }

    public static function sbHell(\Huntress\Bot $bot)
    {
        $bot->loop->addPeriodicTimer(60, function () use ($bot) {
            if (php_uname('s') == "Windows NT") {
                return null; // don't run on testing because oof
            }
            return \CharlotteDunois\Yasmin\Utils\URLHelpers::resolveURLToData("https://forums.spacebattles.com/forums/worm.115/")->then(function (string $string) use ($bot) {
                $data  = \html5qp($string);
                $items = $data->find('li.discussionListItem');
                foreach ($items as $item) {
                    $x     = (object) [
                        'id'         => (int) str_replace("thread-", "", $item->attr("id")),
                        'title'      => trim($item->find('h3')->text()),
                        'threadTime' => self::unfuckDates($item->find(".startDate .DateTime")),
                        'replyTime'  => self::unfuckDates($item->find(".lastPost .DateTime")),
                        'author'     => [
                            'name' => $item->find('.posterDate a.username')->text(),
                            'av'   => "https://forums.spacebattles.com/" . $item->find('.posterAvatar img')->attr("src"),
                            'url'  => "https://forums.spacebattles.com/" . $item->find('.posterDate a.username')->attr("href"),
                        ],
                        'replier'    => [
                            'name' => $item->find('.lastPost a.username')->text(),
                            'av'   => null,
                        ],
                        'numReplies' => (int) trim($item->find('.stats .major dd')->text()),
                        'numViews'   => (int) str_replace(",", "", $item->find('.stats .minor dd')->text()),
                        'wordcount'  => str_replace("Word Count: ", "", $item->find(".posterDate a.OverlayTrigger")->text()),
                    ];
                    $embed = new \CharlotteDunois\Yasmin\Models\MessageEmbed();
                    $embed->setTitle($x->title)->setColor(0x00ff00)
                    ->setURL("https://forums.spacebattles.com/threads/{$x->id}/")
                    ->setAuthor($x->author['name'], $x->author['av'], $x->author['url'])
                    ->addField("Created", $x->threadTime->toFormattedDateString(), true)
                    ->addField("Replies", sprintf("%s (%s pages)", number_format($x->numReplies), number_format(ceil($x->numReplies / 25))), true)
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

    public static function theVolcano(\Huntress\Bot $bot)
    {
        if (self::isTestingClient()) {
            return;
        }
        $bot->loop->addPeriodicTimer(60, function () use ($bot) {
            return \CharlotteDunois\Yasmin\Utils\URLHelpers::resolveURLToData("https://www.reddit.com/r/wormfanfic/new.json")->then(function (string $string) use ($bot) {
                try {
                    $items    = json_decode($string)->data->children;
                    $lastPub  = self::getLastRSS();
                    $newest   = $lastPub;
                    $newItems = [];
                    foreach ($items as $item) {
                        $published = (int) $item->data->created_utc;
                        if ($published <= $lastPub) {
                            continue;
                        }
                        $newest     = max($newest, $published);
                        $newItems[] = (object) [
                            'title'    => $item->data->title,
                            'link'     => "https://reddit.com" . $item->data->permalink,
                            'date'     => \Carbon\Carbon::createFromTimestamp($item->data->created_utc),
                            'category' => $item->data->link_flair_text ?? "Unflaired",
                            'body'     => (strlen($item->data->selftext) > 0) ? $item->data->selftext : $item->data->url,
                            'author'   => $item->data->author,
                        ];
                    }
                    foreach ($newItems as $item) {
                        if (mb_strlen($item->body) > 512) {
                            $item->body = substr($item->body, 0, 509) . "...";
                        }
                        $channel = $bot->client->channels->get(466074264731385876);
                        $embed   = new \CharlotteDunois\Yasmin\Models\MessageEmbed();
                        $embed->setTitle($item->title)->setURL($item->link)->setDescription($item->body)->setTimestamp($item->date->timestamp)->setFooter($item->category)->setAuthor($item->author, '', "https://reddit.com/user/" . $item->author);
                        $channel->send("", ['embed' => $embed]);
                    }
                    $query = \Huntress\DatabaseFactory::get()->prepare('INSERT INTO pct_config (`key`, `value`) VALUES(?, ?) '
                    . 'ON DUPLICATE KEY UPDATE `value`=VALUES(`value`);', ['string', 'integer']);
                    $query->bindValue(1, "rssPublished");
                    $query->bindValue(2, $newest);
                    $query->execute();
                } catch (\Throwable $e) {
                    echo $e->xdebug_message;
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
        $qb  = \Huntress\DatabaseFactory::get()->createQueryBuilder();
        $qb->select("*")->from("pct_sbhell")->where('`idTopic` = ?')->setParameter(0, $post->id, "integer");
        $res = $qb->execute()->fetchAll();
        foreach ($res as $data) {
            return true;
        }
        return false;
    }

    private static function getLastRSS(): int
    {
        $qb  = \Huntress\DatabaseFactory::get()->createQueryBuilder();
        $qb->select("*")->from("pct_config")->where('`key` = ?')->setParameter(0, 'rssPublished', "string");
        $res = $qb->execute()->fetchAll();
        foreach ($res as $data) {
            return $data['value'];
        }
        return 0;
    }
}
