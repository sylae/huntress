<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

/**
 * Simple builtin to show user information
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class CauldronEmoteHub implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;

    public static function register(\Huntress\Bot $bot)
    {
        $bot->client->on(self::PLUGINEVENT_COMMAND_PREFIX . "emote", [self::class, "process"]);
    }

    public static function process(\Huntress\Bot $bot, \CharlotteDunois\Yasmin\Models\Message $message): ?\React\Promise\ExtendedPromiseInterface
    {
        try {
            $m = self::_split($message->content);
            if (count($m) < 2) {
                return self::error($message, "Missing Argument", "You need to tell me what to search for");
            }
            $code = $m[1];
            $x    = [];
            $bot->client->emojis->each(function ($v, $k) use ($code, &$x) {
                if ($v->guild->name == "Cauldron Emote Hub" || stripos($v->guild->name, "CEH") !== false) { // todo: do this better
                    $l = levenshtein($code, $v->name);
                    if (stripos($v->name, $code) !== false || $l < 3) {
                        $x[$k] = $l;
                    }
                }
            });
            asort($x);

            $s = [];
            foreach (array_slice($x, 0, 50, true) as $code => $similarity) {
                $emote   = $bot->client->emojis->resolve($code);
                $sim_str = ($similarity == 0) ? "perfect match" : "similarity $similarity";

                $s[] = sprintf("%s `%s` - Found on %s, %s", (string) $emote, $emote->name, $emote->guild->name, $sim_str);
            }
            if (count($s) == 0) {
                $s[] = "No results found matching `$code`";
            }
            return self::send($message->channel, implode(PHP_EOL, $s), ['split' => true]);
        } catch (\Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }
}
