<?php

/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
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
use Huntress\RedditProcessor;
use React\Promise\PromiseInterface;
use function React\Promise\all;

/**
 * Rules-read verification for the Masturbatorium.
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class Masturbatorium implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("verify")
            ->addGuild(349058708304822273)
            ->setCallback([self::class, "process"]));

        if (self::isTestingClient()) {
            $bot->log->debug("Not adding RSS event on testing.");
        } else {
            new RedditProcessor($bot, "HadesNSFW", "HadesNSFW", 60, [789382738339692574]);
            new RedditProcessor($bot, "HornyOnMaid", "HornyOnMaid", 60, [702911932277063730]);
        }
    }

    public static function process(EventData $data): ?PromiseInterface
    {
        $p = new Permission("p.masturbatorium.verify.enabled", $data->huntress, false);
        $p->addMessageContext($data->message);

        $promises = [];
        $promises[] = $data->message->delete();

        if ($p->resolve() && !$data->message->member->roles->has(674525922895855636)) {
            // check that they have the right code.
            $code = "2bnb00";
            if (mb_strtolower(self::arg_substr($data->message->content, 1)) == $code) {
                $promises[] = $data->message->member->addRole(674525922895855636);
                $promises[] = $data->message->channel->send("{$data->user}, thank you for reading the rules! You may now access keira's masturbatorium.");
            } else {
                $promises[] = $data->message->channel->send("{$data->user}, please try again. Have you read the rules?");
            }
        }
        return all($promises);
    }
}
