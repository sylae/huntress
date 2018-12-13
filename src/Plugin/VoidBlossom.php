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
class VoidBlossom implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;

    public static function register(\Huntress\Bot $bot)
    {
        $bot->client->on("voiceStateUpdate", [self::class, "voiceStateHandler"]);
        $bot->client->on(self::PLUGINEVENT_DB_SCHEMA, [self::class, "db"]);
        $bot->client->on(self::PLUGINEVENT_READY, [self::class, "poll"]);
    }

    public static function db(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        $t = $schema->createTable("vb_config");
        $t->addColumn("key", "string", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t->addColumn("value", "text", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t->setPrimaryKey(["key"]);
    }

    /**
     * Adapted from Ligrev code by Christoph Burschka <christoph@burschka.de>
     */
    public static function poll(\Huntress\Bot $bot)
    {
        $bot->loop->addPeriodicTimer(60, function () use ($bot) {
            if (php_uname('s') == "Windows NT") {
                return null; // don't run on testing because oof
            }
            return \CharlotteDunois\Yasmin\Utils\URLHelpers::resolveURLToData("https://voidblossom.syl.ae/index.php?action=.xml;type=rss2")->then(function (string $string) use ($bot) {

                $data     = \qp($string);
                $items    = $data->find('item');
                $lastPub  = self::getLastRSS();
                $newest   = $lastPub;
                $newItems = [];
                foreach ($items as $item) {
                    $published  = strtotime($item->find('pubDate')->text());
                    if ($published <= $lastPub) { // temporarily showing replies too :o
                        continue;
                    }
                    $newest     = max($newest, $published);
                    $newItems[] = (object) [
                        'title'    => $item->find('title')->text(),
                        'link'     => $item->find('link')->text(),
                        'date'     => (new \Carbon\Carbon($item->find('pubDate')->text())),
                        'category' => $item->find('category')->text(),
                        'body'     => (new \League\HTMLToMarkdown\HtmlConverter(['strip_tags' => true]))->convert($item->find('description')->text()),
                    ];
                }
                foreach ($newItems as $item) {
                    $embed = new \CharlotteDunois\Yasmin\Models\MessageEmbed();
                    $embed->setTitle($item->title)->setURL($item->link)->setDescription($item->body)->setTimestamp($item->date->timestamp)->setFooter($item->category);
                    $bot->client->channels->get("514714763956322364")->send("", ['embed' => $embed]);
                }
                $query = \Huntress\DatabaseFactory::get()->prepare('INSERT INTO vb_config (`key`, `value`) VALUES(?, ?) '
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
        $qb->select("*")->from("vb_config")->where('`key` = ?')->setParameter(0, 'rssPublished', "string");
        $res = $qb->execute()->fetchAll();
        foreach ($res as $data) {
            return $data['value'];
        }
        return 0;
    }

    public static function voiceStateHandler(\CharlotteDunois\Yasmin\Models\GuildMember $new, ?\CharlotteDunois\Yasmin\Models\GuildMember $old)
    {
        if ($new->guild->id == "514715039509512214" && $new->voiceChannel instanceof \CharlotteDunois\Yasmin\Models\VoiceChannel) {
            $role = $new->guild->roles->get("514715039509512214");
            if (is_null($new->roles->get("514715039509512214"))) {
                $new->addRole($role)->then(function () use ($new) {
                    self::send($new->guild->channels->get("511066708484554762"), "<@{$new->id}>, I'm going to give you the DJ role, since you're joining a voice chat.");
                });
            }
        }
    }
}
