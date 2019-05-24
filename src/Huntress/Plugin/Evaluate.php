<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use Carbon\CarbonInterface;
use CharlotteDunois\Collect\Collection;
use CharlotteDunois\Yasmin\Interfaces\TextChannelInterface;
use CharlotteDunois\Yasmin\Models\Message;
use CharlotteDunois\Yasmin\Models\MessageEmbed;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\ExtendedPromiseInterface as Promise;
use Throwable;
use function React\Promise\all;

/**
 * Run artitrary code! Not dangerous at all!
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
        $eh = EventListener::new()
            ->addCommand("eval")
            ->setCallback([self::class, "process"]);
        $bot->eventManager->addEventListener($eh);
    }

    public static function process(EventData $data): ?Promise
    {
        if (!in_array($data->message->author->id, $data->message->client->config['evalUsers'])) {
            return self::unauthorized($data->message);
        }
        try {

            // handy vars to use in eval()
            $message = $data->message;
            $guild = $data->guild;
            $channel = $data->channel;
            $bot = $data->message->client;
            $db = $data->message->client->db;

            $msg = substr(strstr($data->message->content, " "), 1);
            $msg = str_replace(['```php', '```'], "", $msg); // todo: this better
            $response = eval(self::useClassesString() . $msg);

            return self::handleResponse($response, $data);
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true, false);
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

    private static function handleResponse($response, EventData $data, Message $edit = null): ?Promise
    {
        try {
            if (is_string($response)) {
                return self::sendOrEdit($data->message->channel, $response, ['split' => true], $edit = null);
            } elseif (is_object($response)) {
                return self::returnObject($response, $data, $edit);
            } else {
                return self::sendOrEdit($data->message->channel,
                    "```json" . PHP_EOL . json_encode($response, JSON_PRETTY_PRINT) . PHP_EOL . "```",
                    ['split' => ['before' => '```json' . PHP_EOL, 'after' => '```']], $edit);
            }
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true, false);
        }
    }

    private static function sendOrEdit(
        TextChannelInterface $channel,
        string $message,
        array $options,
        Message $edit = null
    ): Promise {
        if (is_null($edit)) {
            return $channel->send($message, $options);
        } else {
            return $edit->edit($message, $options);
        }
    }

    private static function returnObject($value, EventData $data, Message $edit = null): ?Promise
    {
        try {
            $class = get_class($value);
            $embed = new MessageEmbed();
            $embed->setTitle("Returned Value - $class");
            if ($value instanceof CarbonInterface) {
                $embed->setDescription($value->toDayDateTimeString() . PHP_EOL . $value->longRelativeToNowDiffForHumans(2));
                $embed->addField("Timestamp", $value->timestamp, true);
                $embed->addField("Atom", $value->toAtomString(), true);
                $embed->addField("Timezone",
                    sprintf("%s (%s)", $value->timezone->toRegionName(), $value->timezone->toOffsetName()), true);
                $embed->addField("DST?", $value->isDST() ? "Active" : "Inactive", true);
            } elseif ($value instanceof Throwable) {
                return self::exceptionHandler($data->message, $value, true, false);
            } elseif ($value instanceof Collection) {
                $embed->addField("Total count", $value->count(), true);

                // see what it contains
                $contents = $value->map(function ($v) {
                    if (is_object($v)) {
                        return get_class($v);
                    }
                    return gettype($v);
                })->groupBy(function ($v) {
                    return $v;
                })->map(function ($v, $k) {
                    return [sprintf("**%s**: %s", $k, count($v))];
                })->sort(true);

                $embed->setDescription($contents->implode(0, PHP_EOL));
            } elseif ($value instanceof Promise) {
                $embed->setDescription("Will edit this message with result...");
                $p0 = $value;
                $p1 = self::send($data->message->channel, "", ['embed' => $embed]); // never edit
                return all([$p0, $p1])->then(function (array $promises) use ($data) {
                    if ($promises[1] instanceof Message) {
                        return self::handleResponse($promises[0], $data, $promises[1]);
                    }
                });
            } else {
                $json = trim(json_encode($value, JSON_PRETTY_PRINT));
                if (mb_strlen($json) > 0) {
                    $json = "```json" . PHP_EOL . json_encode($value, JSON_PRETTY_PRINT) . PHP_EOL . "```";
                }
                return self::sendOrEdit($data->message->channel,
                    $json,
                    ['split' => ['before' => '```json' . PHP_EOL, 'after' => '```'], 'embed' => $embed], $edit);
            }
            return self::sendOrEdit($data->message->channel, "", ['embed' => $embed], $edit);

        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true, false);
        }
    }
}
