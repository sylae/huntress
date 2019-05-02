<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use \Huntress\Huntress;
use \Huntress\EventListener;
use \React\Promise\ExtendedPromiseInterface as Promise;

/**
 * Simple builtin to show user information
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class PCT implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;
    const RANKS = [
        [463601286260981763, "Recruit"],
        [486760992521584640, "Squaddie"],
        [486760604372041729, "Officer"],
        [486760747427299338, "Captain"],
        [486760608944095232, "Deputy Director"],
        [486760607241076736, "Director"],
    ];

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addURLEvent("https://forums.spacebattles.com/forums/worm.115/", 30, [self::class, "sbHell"]);
        $bot->eventManager->addURLEvent("https://www.reddit.com/r/wormfanfic/new.json", 30, [self::class, "theVolcano"]);
        $bot->eventManager->addEventListener(EventListener::new()->addEvent("dbSchema")->setCallback([self::class, 'db']));
        $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "gaywatch", [self::class, "gaywatch"]);
        $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "promote", [self::class, "promote"]);
        $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "demote", [self::class, "demote"]);
        $bot->on("guildMemberAdd", [self::class, "guildMemberAddHandler"]);
    }

    public static function db(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        $t = $schema->createTable("pct_sbhell");
        $t->addColumn("idTopic", "integer");
        $t->addColumn("timeTopicPost", "datetime");
        $t->addColumn("timeLastReply", "datetime");
        $t->addColumn("gaywatch", "boolean", ['default' => false]);
        $t->addColumn("title", "string", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET, 'notnull' => false]);
        $t->setPrimaryKey(["idTopic"]);

        $t2 = $schema->createTable("pct_config");
        $t2->addColumn("key", "string", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t2->addColumn("value", "text", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t2->setPrimaryKey(["key"]);
    }

    public static function guildMemberAddHandler(\CharlotteDunois\Yasmin\Models\GuildMember $member): ?Promise
    {
        if ($member->guild->id != 397462075418607618) {
            return null;
        }
        return $member->addRole(463601286260981763, "new user setup")
        ->then(function (\CharlotteDunois\Yasmin\Models\GuildMember $member) {
            return $member->addRole(536798744218304541, "new user setup");
        })
        ->then(function (\CharlotteDunois\Yasmin\Models\GuildMember $member) {
            return $member->setNickname("Recruit {$member->displayName}", "new user setup");
        })
        ->then(function (\CharlotteDunois\Yasmin\Models\GuildMember $member) {
            return self::send($member->guild->channels->get(397462075896627221), sprintf("Welcome to PCT, %s!", (string) $member));
        });
    }

    public static function promote(Huntress $bot, \CharlotteDunois\Yasmin\Models\Message $message): ?Promise
    {
        if (is_null($message->member->roles->get(406698099143213066))) {
            return self::unauthorized($message);
        }
        try {
            $user = self::parseGuildUser($message->guild, str_replace(self::_split($message->content)[0], "", $message->content));
            if (!$user instanceof \CharlotteDunois\Yasmin\Models\GuildMember) {
                return self::error($message, "Error", "I don't know who that is.");
            }
            if ($user->roles->has(486762403292512256)) {
                return self::send($message->channel, "Capes can't be in the PRT, silly!");
            }

            // get the current highest role
            $user_rank = null;
            foreach (self::RANKS as $value => $key) { // :ahyperlul:
                if ($user->roles->has($key[0])) {
                    $user_rank = $value;
                }
            }
            switch (self::RANKS[$user_rank][1] ?? null) {
                case null:
                    throw new \Exception("tell keira something is fucked, user with no rank found");
                case "Director":
                    return self::send($message->channel, "This user is already at the maximum rank!");
                default:
                    $new_rank = self::RANKS[$user_rank + 1];

                    return $user->addRole($new_rank[0], "Promotion on behalf of {$message->author->tag}")
                    ->then(function (\CharlotteDunois\Yasmin\Models\GuildMember $member) use ($message, $new_rank) {
                        return $member->setNickname("{$new_rank[1]} {$member->user->username}", "Promotion on behalf of {$message->author->tag}");
                    })
                    ->then(function (\CharlotteDunois\Yasmin\Models\GuildMember $member) use ($message) {
                        return self::send($message->channel, "$member has been promoted!");
                    });
            }
        } catch (\Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }

    public static function demote(Huntress $bot, \CharlotteDunois\Yasmin\Models\Message $message): ?Promise
    {
        if (is_null($message->member->roles->get(406698099143213066))) {
            return self::unauthorized($message);
        }
        try {
            $user = self::parseGuildUser($message->guild, str_replace(self::_split($message->content)[0], "", $message->content));
            if (!$user instanceof \CharlotteDunois\Yasmin\Models\GuildMember) {
                return self::error($message, "Error", "I don't know who that is.");
            }
            if ($user->roles->has(486762403292512256)) {
                return self::send($message->channel, "Capes can't be in the PRT, silly!");
            }

            // get the current highest role
            $user_rank = null;
            foreach (self::RANKS as $value => $key) { // :ahyperlul:
                if ($user->roles->has($key[0])) {
                    $user_rank = $value;
                }
            }
            switch (self::RANKS[$user_rank][1] ?? null) {
                case null:
                    throw new \Exception("tell keira something is fucked, user with no rank found");
                case "Recruit":
                    return self::send($message->channel, "This user is already at the minimum rank!");
                default:
                    $new_rank = self::RANKS[$user_rank];

                    return $user->removeRole($new_rank[0], "Demotion on behalf of {$message->author->tag}")
                    ->then(function (\CharlotteDunois\Yasmin\Models\GuildMember $member) use ($message, $user_rank) {
                        return $member->setNickname(self::RANKS[$user_rank - 1][1] . " {$member->user->username}", "Demotion on behalf of {$message->author->tag}");
                    })
                    ->then(function (\CharlotteDunois\Yasmin\Models\GuildMember $member) use ($message) {
                        return self::send($message->channel, "$member has been demoted. :pensive:");
                    });
            }
        } catch (\Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }

    public static function gaywatch(Huntress $bot, \CharlotteDunois\Yasmin\Models\Message $message): ?Promise
    {
        try {
            $t = self::_split($message->content);
            if (count($t) < 2) {
                $qb  = \Huntress\DatabaseFactory::get()->createQueryBuilder();
                $qb->select("*")->from("pct_sbhell")->where('`gaywatch` = 1');
                $res = $qb->execute()->fetchAll();
                $r   = [];
                if ($message->member->roles->has(406698099143213066)) {
                    $r[] = "As a mod, you can add a gaywatch fic using `!gaywatch SBFicID`";
                }
                foreach ($res as $data) {
                    $title = $data['title'] ?? "<Title unknown>";
                    $r[]   = "*$title* - <https://forums.spacebattles.com/threads/{$data['idTopic']}/>";
                }
                return $message->channel->send(implode("\n", $r), ['split' => true]);
            }
            if (is_numeric($t[1]) && $message->member->roles->has(406698099143213066)) {
                $t[1]        = (int) $t[1];
                $defaultTime = \Carbon\Carbon::now();
                $query       = \Huntress\DatabaseFactory::get()->prepare('INSERT INTO pct_sbhell (`idTopic`, `timeTopicPost`, `timeLastReply`, `gaywatch`) VALUES(?, ?, ?, 1) '
                . 'ON DUPLICATE KEY UPDATE `gaywatch`=VALUES(`gaywatch`);', ['string', 'datetime', 'datetime']);
                $query->bindValue(1, $t[1]);
                $query->bindValue(2, $defaultTime);
                $query->bindValue(3, $defaultTime);
                $query->execute();
                return $message->channel->send("<a:gaybulba:504954316394725376> :eyes: I am now watching for updates to SB thread {$t[1]}.");
            }
        } catch (\Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }

    public static function sbHell(string $string, Huntress $bot)
    {
        try {
            if (self::isTestingClient()) {
                $bot->log->debug("Not firing " . __METHOD__);
                return;
            }
            $data  = \html5qp($string);
            $items = $data->find('li.discussionListItem');
            foreach ($items as $item) {
                $x = (object) [
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

                if (!self::alreadyPosted($x)) {
                    // sbHell mode if it's a new topic
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
                    $bot->channels->get(514258427258601474)->send("", ['embed' => $embed]);
                } else {
                    // gaywatch
                    if (self::isGaywatch($x) && self::lastPost($x) < $x->replyTime) {
                        if ($x->author['name'] == $x->replier['name']) {
                            // op update
                            $embed = new \CharlotteDunois\Yasmin\Models\MessageEmbed();
                            $embed->setTitle($x->title)->setColor(0x00ff00)
                            ->setURL("https://forums.spacebattles.com/threads/{$x->id}/unread")
                            ->setAuthor($x->author['name'], $x->author['av'], $x->author['url'])
                            ->addField("Created", $x->threadTime->toFormattedDateString(), true)
                            ->addField("Replies", sprintf("%s (%s pages)", number_format($x->numReplies), number_format(ceil($x->numReplies / 25))), true)
                            ->addField("Views", number_format($x->numViews), true)
                            ->setFooter("Last reply")
                            ->setTimestamp($x->replyTime->timestamp);

                            if (mb_strlen($x->wordcount) > 0) {
                                $embed->addField("Wordcount", $x->wordcount, true);
                            }
                            $bot->channels->get(540449157320802314)->send("<@&540465395576864789>: {$x->author['name']} has updated *{$x->title}*\n<https://forums.spacebattles.com/threads/{$x->id}/unread>", ['embed' => $embed]);
                        } else {
                            // not op update
                            $bot->channels->get(540449157320802314)->send("SB member {$x->replier['name']} has replied to *{$x->title}*\n<https://forums.spacebattles.com/threads/{$x->id}/unread>");
                        }
                    }
                }

                // push to db
                $query = \Huntress\DatabaseFactory::get()->prepare('INSERT INTO pct_sbhell (`idTopic`, `timeTopicPost`, `timeLastReply`, `title`) VALUES(?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE `timeLastReply`=VALUES(`timeLastReply`), `timeTopicPost`=VALUES(`timeTopicPost`), `title`=VALUES(`title`);', ['string', 'datetime', 'datetime', 'string']);
                $query->bindValue(1, $x->id);
                $query->bindValue(2, $x->threadTime);
                $query->bindValue(3, $x->replyTime);
                $query->bindValue(4, $x->title);
                $query->execute();
            }
        } catch (\Throwable $e) {
            \Sentry\captureException($e);
            $bot->log->addWarning($e->getMessage(), ['exception' => $e]);
        }
    }

    public static function theVolcano(string $string, Huntress $bot)
    {
        try {
            if (self::isTestingClient()) {
                $bot->log->debug("Not firing " . __METHOD__);
                return;
            }
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
                if (mb_strlen($item->title) > 256) {
                    $item->body = substr($item->title, 0, 253) . "...";
                }
                $channel = $bot->channels->get(542263101559668736);
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
            \Sentry\captureException($e);
            $bot->log->addWarning($e->getMessage(), ['exception' => $e]);
        }
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

    private static function lastPost(\stdClass $post): \Carbon\Carbon
    {
        $qb  = \Huntress\DatabaseFactory::get()->createQueryBuilder();
        $qb->select("*")->from("pct_sbhell")->where('`idTopic` = ?')->setParameter(0, $post->id, "integer");
        $res = $qb->execute()->fetchAll();
        foreach ($res as $data) {
            return new \Carbon\Carbon($data['timeLastReply']);
        }
        throw new \Exception("No results found for that post");
    }

    private static function isGaywatch(\stdClass $post): bool
    {
        $qb  = \Huntress\DatabaseFactory::get()->createQueryBuilder();
        $qb->select("*")->from("pct_sbhell")->where('`idTopic` = ?')->setParameter(0, $post->id, "integer");
        $res = $qb->execute()->fetchAll();
        foreach ($res as $data) {
            return (bool) $data['gaywatch'] ?? false;
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
