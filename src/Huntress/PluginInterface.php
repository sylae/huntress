<?php

/*
 * Copyright (c) 2019-2026 MisfitMaid and contributors.
 *
 * Use of this source code is governed by the MIT Non-AI license, which can be found in the LICENSE file.
 */

namespace Huntress;

interface PluginInterface
{
    const string PLUGINEVENT_COMMAND_PREFIX = "huntress_command_";
    const string PLUGINEVENT_DB_SCHEMA = "huntress_database_schema";
    const string PLUGINEVENT_MESSAGE = "huntress_message";
    const string PLUGINEVENT_READY = "huntress_ready";

    public static function register(Huntress $bot);
}
