<?php

/*
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

use React\EventLoop\Factory;
use Throwable;

if (PHP_SAPI != "cli") {
    die("Only run from the command-line.");
}

require_once __DIR__ . "/vendor/autoload.php";
require_once __DIR__ . "/config.php";

// grab out git ID and thow it in a const
exec("git diff --quiet HEAD", $null, $rv);
define('VERSION', trim(`git rev-parse HEAD`) . ($rv == 1 ? "-modified" : ""));

// error handling
set_exception_handler(function (Throwable $e) {
    if (property_exists($e, "xdebug_message")) {
        echo $e->xdebug_message;
    } else {
        echo $e->getMessage() . PHP_EOL . PHP_EOL . $e->getTraceAsString();
    }
});

if (!is_writable("temp")) {
    if (!mkdir("temp", 0770)) {
        die("Huntress must be able to write to the 'temp' directory. Please make this dir, give permissions, and try again");
    }
}

foreach (glob(__DIR__ . "/src/Huntress/Plugin/*.php") as $file) {
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

$bot = new Huntress($config, Factory::create());
$bot->log->info('Connecting to discord...');
$bot->start();
