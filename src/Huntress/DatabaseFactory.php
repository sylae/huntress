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
use function Sentry\captureException;

/**
 * Hold the database
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class DatabaseFactory
{
    const CHARSET = [
        'collation' => 'utf8mb4_unicode_ci',
    ];

    /**
     * Our DB object. Sacred is thy name.
     * @var Connection
     */
    private static $db = null;

    /**
     * Initialize the database. Make sure config is set beforehand or it'll
     * throw shit.
     *
     * @param Huntress $bot
     *
     * @return void
     * @throws DBALException
     * @throws DriverException
     * @throws Throwable
     */
    public static function make(Huntress $bot): void
    {
        $bot->log->info("[DB] Database initialized");
        self::$db = DriverManager::getConnection(['url' => $bot->config['database']],
            new Configuration());
        self::schema($bot);
    }

    /**
     * Pull dbSchema events from HEM and apply them to the database.
     *
     * @param Huntress $bot
     *
     * @throws DBALException
     * @throws DriverException
     * @throws Throwable
     */
    public static function schema(Huntress $bot): void
    {
        $db = self::get();
        $sm = $db->getSchemaManager();
        $fromSchema = $sm->createSchema();

        // Initialize existing schema database.
        $schema = new Schema();
        $bot->emit(PluginInterface::PLUGINEVENT_DB_SCHEMA, $schema);
        $bot->eventManager->fire("dbSchema", $schema);

        $comparator = new Comparator();
        $schemaDiff = $comparator->compare($fromSchema, $schema);
        $sql = $schemaDiff->toSaveSql($db->getDatabasePlatform());
        $total_changes = count($sql);
        if ($total_changes > 0) {
            $bot->log->info("[DB] Schema needs initialization or upgrade", ["statements_to_execute" => $total_changes]);
            foreach ($sql as $s) {
                $bot->log->debug($s);
                if (stripos($s, "DROP FOREIGN KEY") !== false || stripos($s, "DROP INDEX") !== false) {
                    $bot->log->debug("[DB] skipping foreign key/index dropping - dbal bug!");
                    continue;
                }
                try {
                    $db->exec($s);
                } catch (DriverException $e) {
                    if ($e->getErrorCode() == 1826) {
                        $bot->log->debug("[DB] ignoring foreign key duplication error 1826 - dbal bug!");
                    } else {
                        captureException($e);
                        throw $e;
                    }
                }
            }
        } else {
            $bot->log->info("[DB] Schema up to date", ["statements_to_execute" => $total_changes]);
        }
    }

    /**
     * Get a reference to the db object. :snug:
     *
     * @return Connection
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
