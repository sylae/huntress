<?php
/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;


use CharlotteDunois\Yasmin\Models\TextChannel;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;

class TelephoneDelay implements PluginInterface
{
    use PluginHelperTrait;

    const DEFAULT = [];
    const OWNER = 211579331837689857; // replace with their user ID
    const ORIGIN = 642366718462656524; // channel id huntress watches
    const DESTINATION = 633321432868454400; // channel id to post to

    public static function register(Huntress $bot)
    {
        $eh = EventListener::new()
            ->addCommand("telephone")
            ->addUser(self::OWNER)
            ->setCallback([self::class, "telephone"]);
        $bot->eventManager->addEventListener($eh);

        $eh2 = EventListener::new()
            ->setPeriodic(300)
            ->setCallback([self::class, "telephonePoll"]);
        $bot->eventManager->addEventListener($eh2);

        $eh3 = EventListener::new()
            ->addEvent("message")
            ->addUser(self::OWNER)
            ->addChannel(self::ORIGIN)
            ->setCallback([self::class, "telephoneListener"]);
        $bot->eventManager->addEventListener($eh3);
    }

    public static function telephone(EventData $data)
    {
        $tp = self::_split($data->message->content);

        switch ($tp[1] ?? 'ls') {
            case 'reset':
                self::state(self::DEFAULT);
                return $data->message->channel->send(":ok_hand:");
            case 'ls':
                return self::dump($data->message->channel, self::state());
            default:
                return $data->message->channel->send("usage: `!telephone reset|ls`");
        }
    }

    public static function state(array $set = null): array
    {
        static $state = self::DEFAULT;
        if (is_array($set)) {
            $state = $set;
        }
        return $state;
    }

    public static function telephonePoll(Huntress $bot)
    {
        $state = self::state();
        $ch = $bot->channels->get(self::DESTINATION);
        if ($ch instanceof TextChannel) {
            $item = array_shift($state);
            if (is_string($item) && mb_strlen(trim($item)) > 0) {
                $ch->send($item);
            }
            self::state($state);
        }
    }

    public static function telephoneListener(EventData $data)
    {
        $state = self::state();
        $state[] = $data->message->content;
        self::state($state);
    }
}
