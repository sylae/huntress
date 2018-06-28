<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

require_once __DIR__ . "/vendor/autoload.php";
require_once __DIR__ . "/config.php";

if (PHP_SAPI != "cli") {
    header("Location: https://cdn.discordapp.com/emojis/393579183160295424.png?v=1");
    die();
}

foreach (glob(__DIR__ . "/src/Command/*.php") as $file) {
    require_once($file);
}
$huntress_inhibit_auto_restart = false;
register_shutdown_function(function() {
    global $huntress_inhibit_auto_restart;
    if ($huntress_inhibit_auto_restart) {
        die(0);
    } else {
        die(1);
    }
});

$library = new Library();
$library->loadFanfic();

$bot = new Bot($config);
$bot->start();
