<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

/**
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
interface PluginInterface
{
    const PLUGINEVENT_COMMAND_PREFIX = "huntress_command_";
    const PLUGINEVENT_DB_SCHEMA      = "huntress_database_schema";
    const PLUGINEVENT_MESSAGE        = "huntress_message";
    const PLUGINEVENT_READY          = "huntress_ready";

    public static function register(Huntress $bot);
}
