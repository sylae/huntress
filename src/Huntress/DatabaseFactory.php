<?php

/*
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

/**
 * Hold the database
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class DatabaseFactory
{
    /**
     * Our DB object. Sacred is thy name.
     * @var \Doctrine\DBAL\Connection
     */
    private static $db = null;

    const CHARSET = [
        'collation' => 'utf8mb4_unicode_ci',
    ];

    /**
     * Get a reference to the db object. :snug:
     * @return \Doctrine\DBAL\Connection
     * @throws \Exception
     */
    public static function get(): \Doctrine\DBAL\Connection
    {
        if (is_null(self::$db)) {
            throw new \Exception("Database not set up! Have you run DatabaseFactory::make() yet?");
        }
        return self::$db;
    }

    /**
     * Initialize the database. Make sure config is set beforehand or it'll
     * throw shit.
     * @return void
     */
    public static function make(Huntress $bot): void
    {
        $bot->log->info("[DB] Database initialized");
        self::$db = \Doctrine\DBAL\DriverManager::getConnection(['url' => $bot->config['database']], new \Doctrine\DBAL\Configuration());
        self::schema($bot);
    }

    public static function schema(Huntress $bot): void
    {
        $db         = self::get();
        $sm         = $db->getSchemaManager();
        $fromSchema = $sm->createSchema();

        // Initialize existing schema database.
        $schema = new \Doctrine\DBAL\Schema\Schema();
        $bot->emit(PluginInterface::PLUGINEVENT_DB_SCHEMA, $schema);
        $bot->eventManager->fire("dbSchema", $schema);

        $comparator    = new \Doctrine\DBAL\Schema\Comparator();
        $schemaDiff    = $comparator->compare($fromSchema, $schema);
        $sql           = $schemaDiff->toSaveSql($db->getDatabasePlatform());
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
                } catch (\Doctrine\DBAL\Exception\DriverException $e) {
                    if ($e->getErrorCode() == 1826) {
                        $bot->log->debug("[DB] ignoring foreign key duplication error 1826 - dbal bug!");
                    } else {
                        \Sentry\captureException($e);
                        throw $e;
                    }
                }
            }
        } else {
            $bot->log->info("[DB] Schema up to date", ["statements_to_execute" => $total_changes]);
        }
    }
}
