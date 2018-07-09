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
    die("Only run from the command-line.");
}

if (!is_writable("temp")) {
    if (!mkdir("temp", 0770)) {
        die("Huntress must be able to write to the 'temp' directory. Please make this dir, give permissions, and try again");
    }
}

foreach (glob(__DIR__ . "/src/Command/*.php") as $file) {
    require_once($file);
}
foreach (glob(__DIR__ . "/src/Plugin/*.php") as $file) {
    require_once($file);
}

$huntress_inhibit_auto_restart = false;
register_shutdown_function(function () {
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
