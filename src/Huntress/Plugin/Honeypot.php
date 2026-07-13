<?php

/*
 * Copyright (c) 2026 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface;

/**
 * ban they ass
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class Honeypot implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addEvent("message")
                ->setCallback([self::class, "process"])
        );
    }

    public static function process(EventData $data): ?PromiseInterface
    {
        $p = new Permission("p.honeypot.kill", $data->huntress, false);
        $p->addMessageContext($data->message);
        if ($data->message->author->bot || !$p->resolve()) {
            return null;
        }
		
		if ($data->message->member->isBannable()) {
			return $data->message->member->ban(1, sprintf("Triggered automated honeypot in channel %s.", $data->message->channel));
		} else {
			$data->huntress->log->warn(sprintf("Unable to ban honeypot user in channel %s", $data->message->channel->id));
			return null;
		}
    }
}
