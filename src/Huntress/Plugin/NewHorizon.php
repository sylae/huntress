<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use \Huntress\Huntress;
use \React\Promise\ExtendedPromiseInterface as Promise;

/**
 * Simple builtin to show user information
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class NewHorizon implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->on("voiceStateUpdate", [self::class, "voiceStateHandler"]);
        $bot->on("guildMemberAdd", [self::class, "guildMemberAddHandler"]);
        $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "_NHInternalSetWelcomeMessage", [self::class, "setWelcome"]);
        $bot->eventManager->addEventListener(EventListener::new()->addEvent("dbSchema")->setCallback([self::class, 'db']));
        // $rss            = new \Huntress\RSSProcessor($bot, 'NewHorizonRSS', 'https://ayin.earth/forum/index.php?action=.xml;type=rss2', 60, 479296410647527425);
        // $rss->itemColor = 0xffd22b;
    }

    public static function db(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        $t = $schema->createTable("nh_config");
        $t->addColumn("key", "string", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t->addColumn("value", "text", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t->setPrimaryKey(["key"]);
    }

    public static function setWelcome(Huntress $bot, \CharlotteDunois\Yasmin\Models\Message $message): ?Promise
    {
        if (is_null($message->member->roles->get(450658242125627402))) {
            return self::unauthorized($message);
        } else {
            try {
                $args = self::_split($message->content);
                if (count($args) < 2) {
                    return self::error($message, "You dipshit :open_mouth:", "!_NHInternalSetWelcomeMessage This is where you put the message\n%s = username");
                }
                $welcomeMsg = trim(str_replace($args[0], "", $message->content));


                $query = \Huntress\DatabaseFactory::get()->prepare('INSERT INTO nh_config (`key`, `value`) VALUES(?, ?) '
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

    public static function guildMemberAddHandler(\CharlotteDunois\Yasmin\Models\GuildMember $member): ?Promise
    {
        if ($member->guild->id != 450657331068403712) {
            return null;
        }
        return self::send($member->guild->channels->get("450691718359023616"), self::formatWelcomeMessage($member->user));
    }

    private static function formatWelcomeMessage(\CharlotteDunois\Yasmin\Models\User $member)
    {
        return sprintf(self::getWelcomeMessage(), (string) $member);
    }

    private static function getWelcomeMessage(): string
    {
        $qb  = \Huntress\DatabaseFactory::get()->createQueryBuilder();
        $qb->select("*")->from("nh_config")->where('`key` = ?')->setParameter(0, 'serverWelcomeMessage', "string");
        $res = $qb->execute()->fetchAll();
        foreach ($res as $data) {
            return $data['value'];
        }
        return "Welcome to New Horizon!";
    }

    private static function getLastRSS(): int
    {
        $qb  = \Huntress\DatabaseFactory::get()->createQueryBuilder();
        $qb->select("*")->from("nh_config")->where('`key` = ?')->setParameter(0, 'rssPublished', "string");
        $res = $qb->execute()->fetchAll();
        foreach ($res as $data) {
            return $data['value'];
        }
        return 0;
    }

    public static function voiceStateHandler(\CharlotteDunois\Yasmin\Models\GuildMember $new, ?\CharlotteDunois\Yasmin\Models\GuildMember $old)
    {
        if ($new->guild->id == "450657331068403712" && $new->voiceChannel instanceof \CharlotteDunois\Yasmin\Models\VoiceChannel) {
            $role = $new->guild->roles->get("474058052916477955");
            if (is_null($new->roles->get("474058052916477955"))) {
                $new->addRole($role)->then(function () use ($new) {
                    self::send($new->guild->channels->get("468082034045222942"), "<@{$new->id}>, I'm going to give you the DJ role, since you're joining a voice chat.");
                });
            }
        }
    }
}
