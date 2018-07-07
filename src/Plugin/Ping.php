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
class Ping implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;

    public static function register(\Huntress\Bot $bot)
    {
        $bot->client->on(self::PLUGINEVENT_COMMAND_PREFIX . "ping", [self::class, "process"]);
    }

    public static function process(\Huntress\Bot $bot, \CharlotteDunois\Yasmin\Models\Message $message): \React\Promise\ExtendedPromiseInterface
    {
        try {
            $message_tx = \Carbon\Carbon::createFromTimestampMs(round(microtime(true) * 1000));
            $dstamp_tx  = \Carbon\Carbon::createFromTimestampMs(\CharlotteDunois\Yasmin\Utils\Snowflake::deconstruct($message->id)->timestamp * 1000);
            return self::send($message->channel, "Pong!")->then(function (\CharlotteDunois\Yasmin\Models\Message $message) use ($message_tx, $dstamp_tx) {
                $message_rx = \Carbon\Carbon::createFromTimestampMs(round(microtime(true) * 1000));
                $dstamp_rx  = \Carbon\Carbon::createFromTimestampMs(\CharlotteDunois\Yasmin\Utils\Snowflake::deconstruct($message->id)->timestamp * 1000);

                $v = [
                    number_format(($message_rx->format("U.u") - $message_tx->format("U.u")) * 1000),
                    number_format(($dstamp_rx->format("U.u") - $dstamp_tx->format("U.u")) * 1000),
                ];

                $message->edit(vsprintf("Pong!\n%sms ping (huntress-rx)\n%sms ping (msg-snowflake)", $v));
            });
        } catch (\Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }
}
