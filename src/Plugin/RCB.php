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
class RCB implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;

    public static function register(\Huntress\Bot $bot)
    {
        $bot->client->on(self::PLUGINEVENT_DB_SCHEMA, [self::class, "db"]);
        $bot->client->on(self::PLUGINEVENT_READY, [self::class, "poll"]);
    }

    public static function db(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        $t = $schema->createTable("rcb_config");
        $t->addColumn("key", "string", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t->addColumn("value", "text", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t->setPrimaryKey(["key"]);
    }

    /**
     * Adapted from Ligrev code by Christoph Burschka <christoph@burschka.de>
     */
    public static function poll(\Huntress\Bot $bot)
    {
        $bot->loop->addPeriodicTimer(60, function() use ($bot) {
            if (php_uname('s') == "Windows NT") {
                return null; // don't run on testing because oof
            }
            return \CharlotteDunois\Yasmin\Utils\URLHelpers::resolveURLToData("https://www.reddit.com/user/storyfulwriter.xml")->then(function(string $string) use ($bot) {
                $data     = \qp($string);
                $items    = $data->find('entry');
                $lastPub  = self::getLastRSS();
                $newest   = $lastPub;
                $newItems = [];
                foreach ($items as $item) {
                    $published  = strtotime($item->find('updated')->text());
                    if ($published <= $lastPub || $item->find('category')->attr("label") != "r/WormFanfic")
                        continue;
                    $newest     = max($newest, $published);
                    $newItems[] = (object) [
                        'title'    => $item->find('title')->text(),
                        'link'     => $item->find('link')->attr("href"),
                        'date'     => (new \Carbon\Carbon($item->find('updated')->text())),
                        'category' => $item->find('category')->attr("label"),
                        'body'     => str_replace("<!-- SC_OFF -->", "", (new \League\HTMLToMarkdown\HtmlConverter(['strip_tags' => true]))->convert($item->find('content')->text())),
                    ];
                }
                foreach ($newItems as $item) {
                    if (mb_strlen($item->body) > 2048) {
                        $item->body = substr($item->body, 0, 2045) . "...";
                    }
                    $embed = new \CharlotteDunois\Yasmin\Models\MessageEmbed();
                    $embed->setTitle($item->title)->setURL($item->link)->setDescription($item->body)->setTimestamp($item->date->timestamp)->setFooter($item->category, "https://cdn.discordapp.com/emojis/444463169696694283.png?v=1");
                    $bot->client->channels->get("364780038412959746")->send("", ['embed' => $embed]);
                }
                $query = \Huntress\DatabaseFactory::get()->prepare('INSERT INTO rcb_config (`key`, `value`) VALUES(?, ?) '
                . 'ON DUPLICATE KEY UPDATE `value`=VALUES(`value`);', ['string', 'integer']);
                $query->bindValue(1, "rssPublished");
                $query->bindValue(2, $newest);
                $query->execute();
            });
        });
    }

    private static function getLastRSS(): int
    {
        $qb  = \Huntress\DatabaseFactory::get()->createQueryBuilder();
        $qb->select("*")->from("rcb_config")->where('`key` = ?')->setParameter(0, 'rssPublished', "string");
        $res = $qb->execute()->fetchAll();
        foreach ($res as $data) {
            return $data['value'];
        }
        return 0;
    }
}
