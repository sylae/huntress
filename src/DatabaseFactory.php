<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace Huntress;

/**
 * Hold the database
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
    public static function make(Bot $bot): void
    {
        self::$db = \Doctrine\DBAL\DriverManager::getConnection(['url' => $bot->config['database']], new \Doctrine\DBAL\Configuration());
        self::schema($bot);
    }

    public static function schema(Bot $bot): void
    {
        $db         = self::get();
        $sm         = $db->getSchemaManager();
        $fromSchema = $sm->createSchema();

        // Initialize existing schema database.
        $schema = new \Doctrine\DBAL\Schema\Schema();
        $bot->client->emit(PluginInterface::PLUGINEVENT_DB_SCHEMA, $schema);

        $comparator    = new \Doctrine\DBAL\Schema\Comparator();
        $schemaDiff    = $comparator->compare($fromSchema, $schema);
        $sql           = $schemaDiff->toSaveSql($db->getDatabasePlatform());
        $total_changes = count($sql);
        if ($total_changes > 0) {
            \Monolog\Registry::Bot()->info("Schema needs initialization or upgrade", ["statements_to_execute" => $total_changes]);
            foreach ($sql as $s) {
                \Monolog\Registry::Bot()->debug($s);
                if (stripos($s, "DROP FOREIGN KEY") !== false || stripos($s, "DROP INDEX") !== false) {
                    \Monolog\Registry::Bot()->debug("skipping foreign key/index dropping - dbal bug!");
                    continue;
                }
                try {
                    $db->exec($s);
                } catch (\Doctrine\DBAL\Exception\DriverException $e) {
                    \Sentry\captureException($e);
                    if ($e->getErrorCode() == 1826) {
                        \Monolog\Registry::Bot()->debug("ignoring foreign key duplication exception - dbal bug!");
                    } else {
                        throw $e;
                    }
                }
            }
        } else {
            \Monolog\Registry::Bot()->info("Schema up to date", ["statements_to_execute" => $total_changes]);
        }
    }
}
