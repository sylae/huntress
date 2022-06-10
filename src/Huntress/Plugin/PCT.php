<?php

/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\Message;
use Doctrine\DBAL\Schema\Schema;
use Exception;
use Huntress\DatabaseFactory;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Huntress\RedditProcessor;
use React\Promise\ExtendedPromiseInterface as Promise;
use Throwable;

/**
 * Simple builtin to show user information
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class PCT implements PluginInterface
{
    use PluginHelperTrait;

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
        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addEvent("dbSchema")
                ->setCallback([self::class, 'db',])
        );
        if (self::isTestingClient()) {
            $bot->log->debug("Not adding RSS event on testing.");
        } else {
            new RedditProcessor($bot, "theVolcano", "wormfanfic", 30, [542263101559668736, 825140100933091388]);
        }
        $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "promote", [self::class, "promote"]);
        $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "demote", [self::class, "demote"]);
        $bot->on("guildMemberAdd", [self::class, "guildMemberAddHandler"]);
    }

    public static function db(Schema $schema): void
    {
        $t2 = $schema->createTable("pct_config");
        $t2->addColumn("key", "string", ['customSchemaOptions' => DatabaseFactory::CHARSET]);
        $t2->addColumn("value", "text", ['customSchemaOptions' => DatabaseFactory::CHARSET]);
        $t2->setPrimaryKey(["key"]);
    }

    public static function guildMemberAddHandler(GuildMember $member): ?Promise
    {
        if ($member->guild->id != 397462075418607618) {
            return null;
        }
        return $member->addRole(463601286260981763, "new user setup")
            ->then(function (GuildMember $member) {
                return $member->addRole(536798744218304541, "new user setup");
            })
            ->then(function (GuildMember $member) {
                return $member->setNickname("Recruit {$member->displayName}", "new user setup");
            })
            ->then(function (GuildMember $member) {
                return self::send(
                    $member->guild->channels->get(397462075896627221),
                    sprintf("Welcome to PCT, %s!", (string)$member)
                );
            });
    }

    public static function promote(Huntress $bot, Message $message): ?Promise
    {
        if (is_null($message->member->roles->get(406698099143213066))) {
            return self::unauthorized($message);
        }
        try {
            $user = self::parseGuildUser(
                $message->guild,
                str_replace(self::_split($message->content)[0], "", $message->content)
            );
            if (!$user instanceof GuildMember) {
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
                    throw new Exception("tell keira something is fucked, user with no rank found");
                case "Director":
                    return self::send($message->channel, "This user is already at the maximum rank!");
                default:
                    $new_rank = self::RANKS[$user_rank + 1];

                    return $user->addRole($new_rank[0], "Promotion on behalf of {$message->author->tag}")
                        ->then(function (GuildMember $member) use ($message, $new_rank) {
                            return $member->setNickname(
                                "{$new_rank[1]} {$member->user->username}",
                                "Promotion on behalf of {$message->author->tag}"
                            );
                        })
                        ->then(function (GuildMember $member) use ($message) {
                            return self::send($message->channel, "$member has been promoted!");
                        });
            }
        } catch (Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }

    public static function demote(Huntress $bot, Message $message): ?Promise
    {
        if (is_null($message->member->roles->get(406698099143213066))) {
            return self::unauthorized($message);
        }
        try {
            $user = self::parseGuildUser(
                $message->guild,
                str_replace(self::_split($message->content)[0], "", $message->content)
            );
            if (!$user instanceof GuildMember) {
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
                    throw new Exception("tell keira something is fucked, user with no rank found");
                case "Recruit":
                    return self::send($message->channel, "This user is already at the minimum rank!");
                default:
                    $new_rank = self::RANKS[$user_rank];

                    return $user->removeRole($new_rank[0], "Demotion on behalf of {$message->author->tag}")
                        ->then(function (GuildMember $member) use (
                            $message,
                            $user_rank
                        ) {
                            return $member->setNickname(
                                self::RANKS[$user_rank - 1][1] . " {$member->user->username}",
                                "Demotion on behalf of {$message->author->tag}"
                            );
                        })
                        ->then(function (GuildMember $member) use ($message) {
                            return self::send($message->channel, "$member has been demoted. :pensive:");
                        });
            }
        } catch (Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }
}
