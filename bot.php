<?php

/*
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

use CharlotteDunois\Yasmin\Utils\URLHelpers;
use React\EventLoop\Factory;
use Sentry\ClientBuilder;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\Scope;
use Sentry\Transport\NullTransport;
use Throwable;

require_once __DIR__ . "/vendor/autoload.php";
require_once __DIR__ . "/config.php";

// set up our event loop
$loop = Factory::create();
URLHelpers::setLoop($loop);

// grab out git ID and thow it in a const
exec("git diff --quiet HEAD", $null, $rv);
define('VERSION', trim(`git rev-parse HEAD`) . ($rv == 1 ? "-modified" : ""));

// initialize Sentry
$builder = ClientBuilder::create(array_merge($config['sentry'], [
    'release' => 'huntress@' . VERSION,
]));
if (php_uname('s') == "Windows NT") {
    $transport = new NullTransport();
    $client = $builder->setTransport($transport)->getClient();
} else {
    $transport = new SentryTransportFactory();
    $transport->setLoop($loop);
    $client = $builder->setTransportFactory($transport)->getClient();
}
SentrySdk::setCurrentHub(new Hub($client));

set_exception_handler(function (Throwable $e) {
    $scope = new Scope();
    $scope->setExtra('fatal', true);
    Hub::getCurrent()->getClient()->captureException($e, $scope);
    if (property_exists($e, "xdebug_message")) {
        echo $e->xdebug_message;
    } else {
        echo $e->getMessage() . PHP_EOL . PHP_EOL . $e->getTraceAsString();
    }
});

if (PHP_SAPI != "cli") {
    die("Only run from the command-line.");
}

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

$bot = new Huntress($config, $loop);
$bot->log->info('Connecting to discord...');
$bot->start();
