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
    public static function make(array $config): void
    {
        self::$db = \Doctrine\DBAL\DriverManager::getConnection(['url' => $config['database']], new \Doctrine\DBAL\Configuration());
        self::schema();
    }

    public static function schema(): void
    {
        $db         = self::get();
        $sm         = $db->getSchemaManager();
        $fromSchema = $sm->createSchema();

        // Initialize existing schema database.
        $schema  = new \Doctrine\DBAL\Schema\Schema();
        $tables  = [];
        $charset = [
            'collation' => 'utf8mb4_unicode_ci',
        ];

        $tables['sprint'] = $schema->createTable("sprint");
        $tables['sprint']->addColumn("sid", "integer", ["unsigned" => true, "autoincrement" => true]);
        $tables['sprint']->addColumn("user", "bigint", ["unsigned" => true]);
        $tables['sprint']->addColumn("guild", "bigint", ["unsigned" => true]);
        $tables['sprint']->addColumn("channel", "bigint", ["unsigned" => true]);
        $tables['sprint']->addColumn("words", "integer", ["unsigned" => true]);
        $tables['sprint']->addColumn("current", "integer", ["unsigned" => true, "default" => 0]);
        $tables['sprint']->addColumn("status", "integer", ["unsigned" => true, "default" => Plugin\Sprint::STATUS_ACTIVE]);
        $tables['sprint']->addColumn("period", "integer", ["unsigned" => true]);
        $tables['sprint']->addColumn("startTime", "datetime");
        $tables['sprint']->addColumn("endTime", "datetime");
        $tables['sprint']->addColumn("label", "text", ['customSchemaOptions' => $charset]);
        $tables['sprint']->setPrimaryKey(["sid"]);
        $tables['sprint']->addIndex(["user"]);
        $tables['sprint']->addIndex(["endTime"]);
        $tables['sprint']->addIndex(["guild"]);

        $comparator    = new \Doctrine\DBAL\Schema\Comparator();
        $schemaDiff    = $comparator->compare($fromSchema, $schema);
        $sql           = $schemaDiff->toSaveSql($db->getDatabasePlatform());
        $total_changes = count($sql);
        if ($total_changes > 0) {
            \Monolog\Registry::Bot()->info("Schema needs initialization or upgrade", ["statements_to_execute" => $total_changes]);
            foreach ($sql as $s) {
                \Monolog\Registry::Bot()->debug($s);
                $db->exec($s);
            }
        } else {
            \Monolog\Registry::Bot()->info("Schema up to date", ["statements_to_execute" => $total_changes]);
        }
    }
}
