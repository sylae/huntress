<?php
/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;


use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\Permissions;
use Huntress\DiceHandler;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface;
use Throwable;

class Roll implements PluginInterface
{
    use PluginHelperTrait;

    private const KNOWN_DICEBOTS = [
        261302296103747584, // avrae
        559331529378103317, // 5ecrawler
    ];

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
            // don't do anything if another dicebot with the same prefix is here!
            $count = self::getMembersWithPermission($data->channel,
                Permissions::PERMISSIONS['SEND_MESSAGES'] | Permissions::PERMISSIONS['VIEW_CHANNEL'])->filter(function (
                GuildMember $v
            ) {
                return in_array($v->id, self::KNOWN_DICEBOTS);
            })->count();
            if ($count > 0) {
                $data->huntress->log->info("Not rolling due to another bot with matching prefix.");
                return null;
            }

            $p = new Permission("p.dice.roll", $data->huntress, true);
            $p->addMessageContext($data->message);
            if (!$p->resolve()) {
                return $p->sendUnauthorizedMessage($data->message->channel);
            }
            $string = self::arg_substr($data->message->content, 1);
            $res = DiceHandler::fromString($string);
            $res->member = $data->message->member;

            return $data->message->reply("", ['embed' => $res->giveEmbed()]);
        } catch (\InvalidArgumentException | \OutOfBoundsException $e) {
            return $data->message->reply("I couldn't understand that.\nUsage: `!roll xdy [+/- modifier] [adv|dis] [comments]`");
        } catch
        (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true);
        }
    }
}
