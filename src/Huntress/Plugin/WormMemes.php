<?php
/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;


use Carbon\Carbon;
use CharlotteDunois\Collect\Collection;
use CharlotteDunois\Yasmin\Models\MessageEmbed;
use CharlotteDunois\Yasmin\Utils\DataHelpers;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Huntress\RedditProcessor;
use Huntress\RSSItem;
use Huntress\RSSProcessor;
use Throwable;

class WormMemes extends RedditProcessor implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        if (self::isTestingClient()) {
            $bot->log->debug("Not adding RSS event on testing.");
        } else {
            new self($bot, "rcbWormMemes", "WormMemes", 30, [771610178781052949]);
        }
    }

    protected function channelCheckCallback(RSSItem $item, array $channels): array
    {
        $channels[] = match ($item->category) {
            "Ward" => 705397126775177217,
            "Pact" => 620845142382739467,
            "Pale", "Poof" => 707103734018342923,
            "Twig" => 621183537424498718,
            default => 608108475037384708,
        };
        return parent::channelCheckCallback($item, $channels);
    }
}
