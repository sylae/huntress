<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
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
class Localization implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "timezone", [self::class, "timezone"]);
        // $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "locale", [self::class, "locale"]);
        $bot->on(self::PLUGINEVENT_DB_SCHEMA, [self::class, "db"]);
    }

    public static function db(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        $t = $schema->createTable("locale");
        $t->addColumn("user", "bigint", ["unsigned" => true]);
        $t->addColumn("timezone", "text", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET, 'notnull' => false]);
        $t->addColumn("locale", "text", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET, 'notnull' => false]);
        $t->setPrimaryKey(["user"]);
    }

    public static function timezone(Huntress $bot, \CharlotteDunois\Yasmin\Models\Message $message): ?Promise
    {
        try {
            $args = self::_split($message->content);
            $now  = \Carbon\Carbon::now();
            if (count($args) > 1) {
                $query = \Huntress\DatabaseFactory::get()->prepare('INSERT INTO locale (user, timezone) VALUES(?, ?) '
                . 'ON DUPLICATE KEY UPDATE timezone=VALUES(timezone);', ['integer', 'string']);
                $query->bindValue(1, $message->author->id);
                $query->bindValue(2, $args[1]);
                $query->execute();
                $string = "Your timezone has been updated to **%s**.\nI have your local time as **%s**";
            } else {
                $string = "Your timezone is currently set to **%s**.\nI have your local time as **%s**";
            }
            $tz     = new \Huntress\UserLocale($message->author);
            $now_tz = $tz->applyTimezone($now);
            return self::send($message->channel, sprintf($string, $tz->timezone ?? "<unset (default UTC)>", $tz->localeSandbox(function () use ($now_tz) {
                return $now_tz->toDayDateTimeString();
            })));
        } catch (\Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }
}
