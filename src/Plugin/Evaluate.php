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
class Evaluate implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;

    public static function register(\Huntress\Bot $bot)
    {
        $bot->client->on(self::PLUGINEVENT_COMMAND_PREFIX . "eval", [self::class, "process"]);
    }

    public static function process(\Huntress\Bot $bot, \CharlotteDunois\Yasmin\Models\Message $message): \React\Promise\ExtendedPromiseInterface
    {
        if (!in_array($message->author->id, $bot->config['evalUsers'])) {
            return self::unauthorized($message);
        } else {
            try {
                $args     = self::_split($message->content);
                $msg      = str_replace($args[0], "", $message->content);
                $msg      = str_replace(['```php', '```'], "", $msg);
                $response = eval($msg);
                if (is_string($response)) {
                    return self::send($message->channel, $response);
                } else {
                    return self::send($message->channel, "```json" . PHP_EOL . json_encode($response, JSON_PRETTY_PRINT) . PHP_EOL . "```", ['split' => ['before' => '```json' . PHP_EOL, 'after' => '```']]);
                }
            } catch (\Throwable $e) {
                return self::exceptionHandler($message, $e, true);
            }
        }
    }
}
