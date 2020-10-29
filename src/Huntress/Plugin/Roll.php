<?php
/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;


use Huntress\DiceHandler;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface;
use Throwable;

class Roll implements PluginInterface
{
    use PluginHelperTrait;


    public static function register(Huntress $bot)
    {
        $eh = EventListener::new()
            ->addCommand("roll")
            ->addCommand("r")
            ->setCallback([self::class, "rollHandler"]);
        $bot->eventManager->addEventListener($eh);
    }

    public static function rollHandler(EventData $data): ?PromiseInterface
    {
        try {

            $string = self::arg_substr($data->message->content, 1);
            $res = DiceHandler::fromString($string);
            $res->member = $data->message->member;

            return $data->message->channel->send("", ['embed' => $res->giveEmbed()]);
        } catch (\InvalidArgumentException | \OutOfBoundsException $e) {
            return $data->message->channel->send("I couldn't understand that.\nUsage: `!roll xdy [+/- modifier] [adv|dis] [comments]`");
        } catch
        (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true);
        }
    }
}
