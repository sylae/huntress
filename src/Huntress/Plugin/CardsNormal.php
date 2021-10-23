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
use Throwable;

/**
 * Simple dice roller / math robot.
 */
class CardsNormal implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $eh = EventListener::new()
            ->addCommand("card")
            ->setCallback([self::class, "cardHandler"]);
        $bot->eventManager->addEventListener($eh);
    }

    public static function cardHandler(EventData $data)
    {
        static $cache = [];
        try {
            $p = new Permission("p.cards.draw", $data->huntress, true);
            $p->addMessageContext($data->message);
            if (!$p->resolve()) {
                return $p->sendUnauthorizedMessage($data->message->channel);
            }
            $string = self::arg_substr($data->message->content, 1) ?? "1";

            $p = new Permission("p.cards.shuffle", $data->huntress, true);
            $p->addMessageContext($data->message);
            if ($string == "shuffle") {
                if (!$p->resolve()) {
                    return $p->sendUnauthorizedMessage($data->message->channel);
                }

                $cache[$data->message->channel->id] = static::genDeck();
                return $data->message->reply('Shuffled a new deck for the channel.');
            } elseif (is_numeric($string)) {
                $count = (int)$string;
            } else {
                return $data->message->reply(
                    sprintf(
                        'Usage: `%s [NUM]` or `%s shuffle`',
                        static::arg_substr($data->message->content, 0),
                        static::arg_substr($data->message->content, 0)
                    )
                );
            }

            $x = [];

            // no deck at all, shuffle a new one automatically
            if (!array_key_exists($data->message->channel->id, $cache)) {
                $x[] = "No deck found, shuffling!";
                $cache[$data->message->channel->id] = static::genDeck();
            }

            // stop nonsense
            if ($count < 1 || $count > 16) {
                $count = 1;
            }

            // deal them out!
            foreach (range(1, $count) as $n) {
                if (count($cache[$data->message->channel->id]) == 0) {
                    $x[] = "Out of cards!";
                    continue;
                }

                $x[] = static::drawCard($cache[$data->message->channel->id]);
            }


            return $data->message->reply(implode("\n", $x));
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, false);
        }
    }

    protected static function genDeck(): array
    {
        $suits = [
            'Hearts ♥',
            'Diamonds ♦',
            'Spades ♠',
            'Clubs ♣',
        ];

        $deck = [];
        foreach ($suits as $suit) {
            foreach (range(1, 13) as $num) {
                $nameNice = match ($num) {
                    1 => 'Ace',
                    11 => 'Jack',
                    12 => 'Queen',
                    13 => 'King',
                    default => $num,
                };

                $deck[] = sprintf('%s of %s', $nameNice, $suit);
            }
        }

        $deck[] = "Joker 🃏";
        $deck[] = "Joker 🃏";

        shuffle($deck);
        return $deck;
    }

    protected static function drawCard(&$deck): string
    {
        return array_pop($deck);
    }
}
