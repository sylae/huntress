<?php
/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use Huntress\EventListener;
use Huntress\Huntress;

/**
 * Simple dice roller / math robot.
 */
class CardsTarot extends CardsNormal
{

    public static function register(Huntress $bot)
    {
        $eh = EventListener::new()
            ->addCommand("tarot")
            ->setCallback([self::class, "cardHandler"]);
        $bot->eventManager->addEventListener($eh);
    }

    protected static function genDeck(): array
    {
        $suits = [
            'Wands 🪄',
            'Cups 🥤',
            'Swords ⚔',
            'Pentacles ⭐',
        ];

        $deck = [];
        foreach ($suits as $suit) {
            foreach (range(1, 14) as $num) {
                $nameNice = match ($num) {
                    1 => 'Ace',
                    11 => 'Page',
                    12 => 'Knight',
                    13 => 'Queen',
                    14 => 'King',
                    default => $num,
                };

                $deck[] = sprintf('%s of %s', $nameNice, $suit);
            }
        }

        $deck[] = "The Fool 🤡";
        $deck[] = "(I) The Magician 🪄";
        $deck[] = "(II) The High Priestess 👰";
        $deck[] = "(III) The Empress 💁‍♀️";
        $deck[] = "(IV) The Emperor 👑";
        $deck[] = "(V) The Hierophant 🙇";
        $deck[] = "(VI) The Lovers 💘";
        $deck[] = "(VII) The Chariot 🎠";
        $deck[] = "(VIII) Justice ⚖";
        $deck[] = "(IX) The Hermit 🕵️";
        $deck[] = "(X) Wheel of Fortune ⏰";
        $deck[] = "(XI) Strength 💪";
        $deck[] = "(XII) The Hanged Man 🙃";
        $deck[] = "(XIII) Death 💀";
        $deck[] = "(XIV) Temperance 🥂";
        $deck[] = "(XV) The Devil 😈";
        $deck[] = "(XVI) The Tower 🌆";
        $deck[] = "(XVII) The Star ⭐";
        $deck[] = "(XVIII) The Moon 🌙";
        $deck[] = "(XIX) The Sun 🌞";
        $deck[] = "(XX) Judgement <:pensiveshoot:615369725056385024>";
        $deck[] = "(XXI) The World 🌍";

        shuffle($deck);
        return $deck;
    }

    protected static function drawCard(&$deck): string
    {
        $card = array_pop($deck);
        $updown = random_int(0, 1) == 1 ? "Upright" : "Reversed";
        return sprintf('%s, %s', $card, $updown);
    }
}
