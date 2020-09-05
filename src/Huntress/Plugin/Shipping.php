<?php

/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Yasmin\Models\Guild;
use CharlotteDunois\Yasmin\Models\Message;
use Doctrine\DBAL\Schema\Schema;
use Exception;
use Huntress\DatabaseFactory;
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
class Shipping implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "ship", [self::class, "process"]);
        $bot->on(self::PLUGINEVENT_DB_SCHEMA, [self::class, "db"]);
    }

    public static function db(Schema $schema): void
    {
        $t = $schema->createTable("ships");
        $t->addColumn("guild", "bigint", ["unsigned" => true]);
        $t->addColumn("ship", "string", ['customSchemaOptions' => DatabaseFactory::CHARSET]);
        $t->setPrimaryKey(["guild", "ship"]);
    }

    public static function process(Huntress $bot, Message $message): ?Promise
    {
        try {
            $t = self::_split($message->content);
            switch ($t[1] ?? "") {
                case "add":
                    $cape = trim(str_replace("!ship " . $t[1], "", $message->content));
                    if (mb_strlen($cape) < 1) {
                        return self::send($message->channel, "usage: `!ship add [name]`");
                    }
                    self::addCape($message->guild, $cape);
                    return self::send($message->channel, ":ok_hand: " . $cape);
                case "rm":
                case "delete":
                case "del":
                    $cape = trim(str_replace("!ship " . $t[1], "", $message->content));
                    self::delCape($message->guild, $cape);
                    return self::send($message->channel, ":put_litter_in_its_place: " . $cape);
                case "list":
                case "ls":
                    $qb = DatabaseFactory::get()->createQueryBuilder();
                    $qb->select("ship")->from("ships")->where("guild = ?")->orderBy("ship")->setParameter(1,
                        $message->guild->id, "integer");
                    $x = $qb->execute()->fetchAll();
                    $r = [];
                    foreach ($x as $s) {
                        $r[] = '`' . $s['ship'] . '`';
                    }
                    if (count($r) < 1) {
                        throw new Exception("No ships found for this server! Add some with `!ship add [name]`");
                    }
                    return self::send($message->channel, implode(", ", $r), ['split' => ['char' => ","]]);

                case "me":
                    return self::send($message->channel,
                        sprintf("%s x %s OTP", $message->member->displayName, self::getCape($message->guild)));
                default:
                    return self::send($message->channel,
                        sprintf("%s x %s OTP", self::getCape($message->guild), self::getCape($message->guild)));
            }
        } catch (Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }

    public static function addCape(Guild $guild, string $cape): void
    {
        $query = DatabaseFactory::get()->prepare('INSERT INTO ships (`guild`, `ship`) VALUES(?, ?) '
            . 'ON DUPLICATE KEY UPDATE ship=VALUES(ship);', ['integer', 'string']);
        $query->bindValue(1, $guild->id);
        $query->bindValue(2, $cape);
        $query->execute();
    }

    public static function delCape(Guild $guild, string $cape): void
    {
        $qb = DatabaseFactory::get()->createQueryBuilder();
        $qb->delete("ships")->where("guild = ?")->andWhere("ship = ?")->setParameter(1, $guild->id,
            "integer")->setParameter(2, $cape, "string");
        $qb->execute();
    }

    public static function getCape(Guild $guild): string
    {
        $qb = DatabaseFactory::get()->createQueryBuilder();
        $qb->select("ship")->from("ships")->where("guild = ?")->orderBy("RAND()")->setMaxResults(1)->setParameter(1,
            $guild->id, "integer");
        $x = $qb->execute()->fetchColumn();
        if (is_bool($x)) {
            throw new Exception("No ships found for this server! Add some with `!ship add [name]`");
        }
        return $x;
    }
}
