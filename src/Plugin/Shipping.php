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
class Shipping implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;

    public static function register(\Huntress\Bot $bot)
    {
        $bot->client->on(self::PLUGINEVENT_COMMAND_PREFIX . "ship", [self::class, "process"]);
        $bot->client->on(self::PLUGINEVENT_DB_SCHEMA, [self::class, "db"]);
    }

    public static function db(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        $t = $schema->createTable("ships");
        $t->addColumn("guild", "bigint", ["unsigned" => true]);
        $t->addColumn("ship", "string", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t->setPrimaryKey(["guild", "ship"]);
    }

    public static function process(\Huntress\Bot $bot, \CharlotteDunois\Yasmin\Models\Message $message): ?\React\Promise\ExtendedPromiseInterface
    {
        try {
            $t = self::_split($message->content);
            switch ($t[1] ?? "") {
                case "add":
                    $cape = trim(str_replace("!ship " . $t[1], "", $message->content));
                    self::addCape($message->guild, $cape);
                    return self::send($message->channel, ":ok_hand: " . $cape);
                case "rm":
                case "delete":
                case "del":
                    $cape = trim(str_replace("!ship " . $t[1], "", $message->content));
                    self::delCape($message->guild, $cape);
                    return self::send($message->channel, ":put_litter_in_its_place: " . $cape);
                case "me":
                    return self::send($message->channel, sprintf("%s x %s OTP", $message->member->displayName, self::getCape($message->guild)));
                default:
                    return self::send($message->channel, sprintf("%s x %s OTP", self::getCape($message->guild), self::getCape($message->guild)));
            }
        } catch (\Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }

    public static function addCape(\CharlotteDunois\Yasmin\Models\Guild $guild, string $cape): void
    {
        $query = \Huntress\DatabaseFactory::get()->prepare('INSERT INTO ships (`guild`, `ship`) VALUES(?, ?) '
        . 'ON DUPLICATE KEY UPDATE ship=VALUES(ship);', ['integer', 'string']);
        $query->bindValue(1, $guild->id);
        $query->bindValue(2, $cape);
        $query->execute();
    }

    public static function delCape(\CharlotteDunois\Yasmin\Models\Guild $guild, string $cape): void
    {
        $qb = \Huntress\DatabaseFactory::get()->createQueryBuilder();
        $qb->delete("ships")->where("guild = ?")->andWhere("ship = ?")->setParameter(1, $guild->id, "integer")->setParameter(2, $cape, "string");
        $qb->execute();
    }

    public static function getCape(\CharlotteDunois\Yasmin\Models\Guild $guild): string
    {
        $qb = \Huntress\DatabaseFactory::get()->createQueryBuilder();
        $qb->select("ship")->from("ships")->where("guild = ?")->orderBy("RAND()")->setMaxResults(1)->setParameter(1, $guild->id, "integer");
        $x  = $qb->execute()->fetchColumn();
        if (is_bool($x)) {
            throw new \Exception("No ships found for this server! Add some with `!ship add [name]`");
        }
        return $x;
    }
}
