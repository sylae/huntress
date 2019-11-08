<?php
/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;


use CharlotteDunois\Yasmin\Models\Message;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface;

class Misfit implements PluginInterface
{
    use PluginHelperTrait;

    const DEFAULT = [
        'head' => [0, null],
        'larm' => [0, null],
        'rarm' => [0, null],
        'torso' => [0, null],
        'lleg' => [0, null],
        'rleg' => [0, null],
    ];

    public static function register(Huntress $bot)
    {
        $eh = EventListener::new()
            ->addCommand("misfit")
            ->addUser(297969955356540929)
            ->setCallback([self::class, "misfit"]);
        $bot->eventManager->addEventListener($eh);
    }

    public static function misfit(EventData $data)
    {
        $tp = self::_split($data->message->content);

        switch ($tp[1] ?? 'ls') {
            case 'reset':
                $state = self::state(self::DEFAULT);
                return self::ls($data->message, $state);
            case 'ls':
                return self::ls($data->message, self::state());
            case 'export':
                return $data->message->channel->send(json_encode(self::state()));
            case 'import':
                return self::fetchMessage($data->message->client,
                    $tp[2])->then(function (Message $importMsg) use ($data) {
                    $oldState = json_decode(array_pop(explode("\n", $importMsg->content)), true);
                    if (count($oldState) == 6) {
                        $state = self::state($oldState);
                        return self::ls($data->message, $state);
                    } else {
                        return $data->message->channel->send("Could not read data: " . json_last_error_msg());
                    }
                }, function ($error) use ($data) {
                    return self::error($data->message, "Unable to fetch message", $error);
                });

            case 'set':
                $state = self::state();
                $state[$tp[2]] = [(int) $tp[4], $tp[3]];
                $state = self::state($state);
                return self::ls($data->message, $state);
            default:
                if (is_numeric($tp[1])) {
                    $x = 0;
                    $msg = [];
                    $state = self::state();
                    while ($x < $tp[1]) {
                        $res = self::roll();
                        if ($state[$res[0]][1] == null) {
                            $msg[] = "Shifting from human to {$res[1]} in {$res[0]}";
                            $state[$res[0]] = [1, $res[1]];
                        } elseif ($res[1] == $state[$res[0]][1]) {
                            // assume it's an upgrade
                            $state[$res[0]][0]++;
                            $msg[] = "Upgrading {$res[0]} to {$res[1]}@{$state[$res[0]][0]}";
                        } else {
                            $msg[] = sprintf("Rolled %s %s - species differ", $res[0], $res[1]);
                        }

                        $x++;
                    }
                    self::state($state);
                    return self::ls($data->message, self::state(), implode(PHP_EOL, $msg));
                } else {
                    return $data->message->channel->send(":thinking:");
                }
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

    private static function ls(Message $message, array $state, $text = ""): ?PromiseInterface
    {
        $x = [];
        $x[] = $text;
        foreach ($state as $part => $d) {
            $x[] = sprintf("*%s*: level %s %s", $part, $d[0], $d[1]);
        }
        $x[] = json_encode($state);
        return self::send($message->channel, implode(PHP_EOL, $x));
    }

    private static function roll(): array
    {
        // get existing state
        $state = self::state();

        // only get ones not at max conquest
        $opts = [];
        foreach ($state as $part => $value) {
            if ($value[0] < 3) {
                $opts[] = $part;
            }
        }

        // pick a random part;
        $part = $opts[array_rand($opts)];

        $species = ['dragon', 'snake', 'roc'];
        $s = $species[array_rand($species)];

        return [$part, $s];
    }
}
