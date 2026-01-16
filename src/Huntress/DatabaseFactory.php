<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Schema;
use Exception;
use Throwable;

/**
 * Hold the database
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class DatabaseFactory
{
    const array CHARSET = [
        'collation' => 'utf8mb4_unicode_ci',
    ];

    /**
     * Our DB object. Sacred is thy name.
     */
    private static ?Connection $db = null;

    /**
     * Initialize the database. Make sure config is set beforehand or it'll
     * throw shit.
     */
    public static function make(Huntress $bot, $dbConfig): void
    {
        $bot->getLogger()->info("[DB] Database initialized");
        self::$db = DriverManager::getConnection($dbConfig, new Configuration());
        self::schema($bot);
    }

    /**
     * Pull dbSchema events from HEM and apply them to the database.
     */
    public static function schema(Huntress $bot): void
    {
        $db = self::get();
        $sm = $db->createSchemaManager();
        $fromSchema = $sm->introspectSchema();

        // Initialize existing schema database.
        $schema = new Schema();
        $bot->emit(PluginInterface::PLUGINEVENT_DB_SCHEMA, [$schema]);
        $bot->eventManager->fire("dbSchema", $schema);

        // $statements = $sm->createComparator()->compareSchemas($fromSchema, $schema)->toSql($db->getDatabasePlatform());
        $statements = [];

        $total_changes = count($statements);
        if ($total_changes > 0) {
            $bot->getLogger()->info("[DB] Schema needs initialization or upgrade", ["statements_to_execute" => $total_changes]);
            foreach ($statements as $s) {
                $bot->getLogger()->debug($s);
                if (stripos($s, "DROP FOREIGN KEY") !== false || stripos($s, "DROP INDEX") !== false) {
                    $bot->getLogger()->debug("[DB] skipping foreign key/index dropping - dbal bug!");
                    continue;
                }
                $db->executeQuery($s);
            }
        } else {
            $bot->getLogger()->info("[DB] Schema up to date", ["statements_to_execute" => $total_changes]);
        }
    }

    /**
     * Get a reference to the db object. :snug:
     *
     * @throws Exception
     */
    public static function get(): Connection
    {
        if (is_null(self::$db)) {
            throw new Exception("Database not set up! Have you run DatabaseFactory::make() yet?");
        }
        return self::$db;
    }
}
