<?php

/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use Carbon\Carbon;
use CharlotteDunois\Yasmin\Models\Message;
use CharlotteDunois\Yasmin\Utils\MessageHelpers;
use CharlotteDunois\Yasmin\Utils\Snowflake;
use Exception;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface as Promise;
use ReflectionClass;
use Throwable;

/**
 * Simple builtin to show user information
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class Management implements PluginInterface
{
    use PluginHelperTrait;

    /**
     *
     * @var Carbon
     */
    public static $startupTime;

    public static function register(Huntress $bot)
    {
        $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "update", [self::class, "update"]);
        $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "restart", [self::class, "restart"]);
        $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "ping", [self::class, "ping"]);
        $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "huntress", [self::class, "info"]);
        $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "invite", [self::class, "invite"]);
        $bot->on(self::PLUGINEVENT_READY, function () {
            self::$startupTime = Carbon::now();
        });
    }

    public static function invite(Huntress $bot, Message $message): ?Promise
    {
        return $bot->generateOAuthInvite()->then(function ($i) use ($message) {
            self::send($message->channel,
                sprintf("Use the following URL to add this Huntress instance to your server!\n<%s>", $i));
        });
    }

    public static function update(Huntress $bot, Message $message): ?Promise
    {
        if (!in_array($message->author->id, $bot->config['evalUsers'])) {
            return self::unauthorized($message);
        } else {
            try {
                return self::send($message->channel, "```" . PHP_EOL . self::gitPull() . "```",
                    ['split' => ['before' => '```' . PHP_EOL, 'after' => '```']]);
            } catch (Throwable $e) {
                return self::exceptionHandler($message, $e, true);
            }
        }
    }

    private static function gitPull(): string
    {
        $descriptorspec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"],
        ];
        $pipes = [];
        if (php_uname('s') == "Windows NT") {
            $process = proc_open('sh -x update 2>&1', $descriptorspec, $pipes);
        } else {
            $process = proc_open('./update 2>&1', $descriptorspec, $pipes);
        }
        if (is_resource($process)) {
            fclose($pipes[0]);
            $stdout = trim(stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            return $stdout;
        } else {
            throw new Exception("Could not init script");
        }
    }

    public static function info(Huntress $bot, Message $message): ?Promise
    {
        try {
            $embed = self::easyEmbed($message);

            $embed->addField("Memory usage", self::formatBytes(memory_get_usage()), true);
            $embed->addField("PHP", phpversion(), true);
            $embed->addField("PID / User", getmypid() . " / " . get_current_user(), true);

            $count = [
                $bot->guilds->count(),
                $bot->channels->count(),
                $bot->users->count(),
            ];
            $embed->addField("Guilds / Channels / (loaded) Users", implode(" / ", $count));
            $embed->addField("Huntress", self::gitVersion());
            $embed->addField("System", php_uname());
            $embed->addField("Uptime",
                sprintf("%s - *(connected %s)*", self::$startupTime->diffForHumans(null, true, null, 2),
                    self::$startupTime->toAtomString()));


            $plugins = implode("\n", self::getPlugins());
            if (mb_strlen($plugins) > 0) {
                $inheritance = MessageHelpers::splitMessage($plugins,
                    ['maxLength' => 1024]);
                $first = true;
                foreach ($inheritance as $i) {
                    $embed->addField($first ? "Loaded Plugins" : "Loaded Plugins (cont.)", $i);
                    $first = false;
                }
            }

            $deps = "";
            foreach (self::composerPackages() as $p => $v) {
                $deps .= "[$p](https://packagist.org/packages/$p) ($v)\n";
            }
            if (mb_strlen($deps) > 0) {
                $inheritance = MessageHelpers::splitMessage($deps,
                    ['maxLength' => 1024]);
                $first = true;
                foreach ($inheritance as $i) {
                    $embed->addField($first ? "Composer dependencies" : "Composer dependencies (cont.)", $i);
                    $first = false;
                }
            }

            return self::send($message->channel, "", ['embed' => $embed]);
        } catch (Throwable $e) {
            return self::exceptionHandler($message, $e, true);
        }
    }

    private static function formatBytes(int $b): string
    {
        $units = ["bytes", "KiB", "MiB", "GiB", "TiB", "PiB", "EiB", "ZiB", "YiB"];
        $c = 0;
        $r = [];
        foreach ($units as $k => $u) {
            if (($b / pow(1024, $k)) >= 1) {
                $r["bytes"] = $b / pow(1024, $k);
                $r["units"] = $u;
                $c++;
            }
        }
        return number_format($r["bytes"], 2) . " " . $r["units"];
    }

    private static function gitVersion(): string
    {
        return sprintf("[%s](https://github.com/sylae/huntress/commit/%s)", VERSION, VERSION);
    }

    private static function getPlugins(): array
    {
        $a = [];
        foreach (get_declared_classes() as $class) {
            if ((new ReflectionClass($class))->implementsInterface("Huntress\PluginInterface")) {
                $a[] = $class;
            }
        }
        return $a;
    }

    private static function composerPackages(): array
    {
        $descriptorspec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"],
        ];
        $pipes = [];
        $process = proc_open('composer show -D -f json', $descriptorspec, $pipes);

        if (is_resource($process)) {
            fclose($pipes[0]);
            $stdout = trim(stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $e = json_decode($stdout, true)['installed'];
            $r = [];
            foreach ($e as $p) {
                $r[$p['name']] = $p['version'];
            }
            return $r;
        } else {
            throw new Exception("Could not init script");
        }
    }

    public static function restart(Huntress $bot, Message $message): ?Promise
    {
        if (!in_array($message->author->id, $bot->config['evalUsers'])) {
            return self::unauthorized($message);
        } else {
            return self::send($message->channel, ":joy::gun:")->then(function () {
                die();
            }, function () {
                die();
            });
        }
    }

    public static function ping(Huntress $bot, Message $message): ?Promise
    {
        try {
            $message_tx = Carbon::createFromTimestampMs((int)(round(microtime(true) * 1000)));
            $dstamp_tx = Carbon::createFromTimestampMs((int)(Snowflake::deconstruct($message->id)->timestamp * 1000));
            return $message->reply("Pong!")->then(function (
                Message $message
            ) use ($message_tx, $dstamp_tx) {
                $message_rx = Carbon::createFromTimestampMs((int)(round(microtime(true) * 1000)));
                $dstamp_rx = Carbon::createFromTimestampMs((int)(Snowflake::deconstruct($message->id)->timestamp * 1000));

                $v = [
                    number_format(($message_rx->format("U.u") - $message_tx->format("U.u")) * 1000),
                    number_format(($dstamp_rx->format("U.u") - $dstamp_tx->format("U.u")) * 1000),
                ];

                $message->edit(vsprintf("Pong!\n%sms ping (huntress-rx)\n%sms ping (msg-snowflake)", $v));
            });
        } catch (Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }
}
