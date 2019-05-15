<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\Message;
use CharlotteDunois\Yasmin\Models\VoiceChannel;
use Doctrine\DBAL\Schema\Schema;
use Huntress\DatabaseFactory;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\ExtendedPromiseInterface as Promise;
use Throwable;

/**
 * Simple builtin to show user information
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class NewHorizon implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->on("voiceStateUpdate", [self::class, "voiceStateHandler"]);
        $bot->on("guildMemberAdd", [self::class, "guildMemberAddHandler"]);
        $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "_NHInternalSetWelcomeMessage", [self::class, "setWelcome"]);
        $bot->eventManager->addEventListener(EventListener::new()->addEvent("dbSchema")->setCallback([
            self::class,
            'db',
        ]));
        // $rss            = new \Huntress\RSSProcessor($bot, 'NewHorizonRSS', 'https://ayin.earth/forum/index.php?action=.xml;type=rss2', 60, 479296410647527425);
        // $rss->itemColor = 0xffd22b;
    }

    public static function db(Schema $schema): void
    {
        $t = $schema->createTable("nh_config");
        $t->addColumn("key", "string", ['customSchemaOptions' => DatabaseFactory::CHARSET]);
        $t->addColumn("value", "text", ['customSchemaOptions' => DatabaseFactory::CHARSET]);
        $t->setPrimaryKey(["key"]);
    }

    public static function setWelcome(Huntress $bot, Message $message): ?Promise
    {
        if (is_null($message->member->roles->get(450658242125627402))) {
            return self::unauthorized($message);
        } else {
            try {
                $args = self::_split($message->content);
                if (count($args) < 2) {
                    return self::error($message, "You dipshit :open_mouth:",
                        "!_NHInternalSetWelcomeMessage This is where you put the message\n%s = username");
                }
                $welcomeMsg = trim(str_replace($args[0], "", $message->content));


                $query = DatabaseFactory::get()->prepare('INSERT INTO nh_config (`key`, `value`) VALUES(?, ?) '
                    . 'ON DUPLICATE KEY UPDATE `value`=VALUES(`value`);', ['string', 'string']);
                $query->bindValue(1, "serverWelcomeMessage");
                $query->bindValue(2, $welcomeMsg);
                $query->execute();

                return self::send($message->channel, self::formatWelcomeMessage($message->author));
            } catch (Throwable $e) {
                return self::exceptionHandler($message, $e, true);
            }
        }
    }

    private static function formatWelcomeMessage(\CharlotteDunois\Yasmin\Models\User $member)
    {
        return sprintf(self::getWelcomeMessage(), (string) $member);
    }

    private static function getWelcomeMessage(): string
    {
        $qb = DatabaseFactory::get()->createQueryBuilder();
        $qb->select("*")->from("nh_config")->where('`key` = ?')->setParameter(0, 'serverWelcomeMessage', "string");
        $res = $qb->execute()->fetchAll();
        foreach ($res as $data) {
            return $data['value'];
        }
        return "Welcome to New Horizon!";
    }

    public static function guildMemberAddHandler(GuildMember $member): ?Promise
    {
        if ($member->guild->id != 450657331068403712) {
            return null;
        }
        return self::send($member->guild->channels->get("450691718359023616"),
            self::formatWelcomeMessage($member->user));
    }

    public static function voiceStateHandler(
        GuildMember $new,
        ?GuildMember $old
    ) {
        if ($new->guild->id == "450657331068403712" && $new->voiceChannel instanceof VoiceChannel) {
            $role = $new->guild->roles->get("474058052916477955");
            if (is_null($new->roles->get("474058052916477955"))) {
                $new->addRole($role)->then(function () use ($new) {
                    self::send($new->guild->channels->get("468082034045222942"),
                        "<@{$new->id}>, I'm going to give you the DJ role, since you're joining a voice chat.");
                });
            }
        }
    }

    private static function getLastRSS(): int
    {
        $qb = DatabaseFactory::get()->createQueryBuilder();
        $qb->select("*")->from("nh_config")->where('`key` = ?')->setParameter(0, 'rssPublished', "string");
        $res = $qb->execute()->fetchAll();
        foreach ($res as $data) {
            return $data['value'];
        }
        return 0;
    }
}
