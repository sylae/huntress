<?php

/*
 * Copyright (c) 2019-2026 MisfitMaid and contributors.
 *
 * Use of this source code is governed by the MIT Non-AI license, which can be found in the LICENSE file.
 */

namespace Huntress;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Exception;

class DatabaseFactory
{

    private static ?Connection $db = null;

    /**
     * Initialize the database. Make sure config is set beforehand or it'll
     * throw shit.
     */
    public static function make(Huntress $bot, $dbConfig): void
    {
        $bot->getLogger()->info("[DB] Database initialized");
        self::$db = DriverManager::getConnection($dbConfig, new Configuration());
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
