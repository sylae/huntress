<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Yasmin\Models\Message;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\ExtendedPromiseInterface as Promise;
use Throwable;

/**
 * Simple builtin to show user information
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class Evaluate implements PluginInterface
{
    use PluginHelperTrait;
    const USE_CLASSES = [
        '\Carbon\Carbon',
        '\CharlotteDunois\Yasmin\Utils\URLHelpers',
        '\Huntress\Huntress',
    ];

    public static function register(Huntress $bot)
    {
        $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "eval", [self::class, "process"]);
    }

    public static function process(Huntress $bot, Message $message): ?Promise
    {
        if (!in_array($message->author->id, $bot->config['evalUsers'])) {
            return self::unauthorized($message);
        } else {
            try {
                $args = self::_split($message->content);
                $msg = str_replace($args[0], "", $message->content);
                $msg = str_replace(['```php', '```'], "", $msg);
                $response = eval(self::useClassesString() . $msg);
                if (is_string($response)) {
                    return self::send($message->channel, $response, ['split' => true]);
                } else {
                    return self::send($message->channel,
                        "```json" . PHP_EOL . json_encode($response, JSON_PRETTY_PRINT) . PHP_EOL . "```",
                        ['split' => ['before' => '```json' . PHP_EOL, 'after' => '```']]);
                }
            } catch (Throwable $e) {
                return self::exceptionHandler($message, $e, true, false);
            }
        }
    }

    private static function useClassesString(): string
    {
        $x = [];
        $x[] = PHP_EOL;
        foreach (self::USE_CLASSES as $class) {
            $x[] = "use $class;";
        }
        $x[] = PHP_EOL;
        return implode(PHP_EOL, $x);
    }
}
