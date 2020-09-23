<?php
/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;


use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Huntress\RSSProcessor;

class Ironreach implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        if (self::isTestingClient()) {
            $bot->log->debug("Not adding RSS event on testing.");
        } else {
            new RSSProcessor($bot, 'ironreachTwitter', "https://queryfeed.net/tw?q=%40ironreach", 30,
                [755783497850814557]);
        }
    }
    
}
