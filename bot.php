<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

require_once __DIR__ . "/vendor/autoload.php";
require_once __DIR__ . "/config.php";

$loop = \React\EventLoop\Factory::create();
\CharlotteDunois\Yasmin\Utils\URLHelpers::setLoop($loop);

$builder   = \Sentry\ClientBuilder::create($config['sentry']);
$transport = new SentryTransport($builder->getOptions(), $loop);
$client    = $builder->setTransport($transport)->getClient();
\Sentry\State\Hub::setCurrent((new \Sentry\State\Hub($client)));

set_exception_handler(function (\Throwable $e) {
    $scope = new \Sentry\State\Scope();
    $scope->setExtra('fatal', true);
    \Sentry\State\Hub::getCurrent()->getClient()->captureException($e, $scope);
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
