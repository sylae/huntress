<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use \Huntress\Huntress;
use \React\Promise\ExtendedPromiseInterface as Promise;

/**
 * Simple builtin to show user information
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class Management implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;
    /**
     *
     * @var \Carbon\Carbon
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
            self::$startupTime = \Carbon\Carbon::now();
        });
    }

    public static function invite(Huntress $bot, \CharlotteDunois\Yasmin\Models\Message $message): ?Promise
    {
        return $bot->generateOAuthInvite()->then(function ($i) use ($message) {
            self::send($message->channel,
                sprintf("Use the following URL to add this Huntress instance to your server!\n<%s>", $i));
        });
    }

    public static function update(Huntress $bot, \CharlotteDunois\Yasmin\Models\Message $message): ?Promise
    {
        if (!in_array($message->author->id, $bot->config['evalUsers'])) {
            return self::unauthorized($message);
        } else {
            try {
            } catch (\Throwable $e) {
                return self::send($message->channel, "```" . PHP_EOL . self::gitPull() . "```",
                    ['split' => ['before' => '```' . PHP_EOL, 'after' => '```']]);
                return self::exceptionHandler($message, $e, true);
            }
        }
    }

    public static function info(Huntress $bot, \CharlotteDunois\Yasmin\Models\Message $message): ?Promise
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
            $embed->addField("Guilds / Channels / Users", implode(" / ", $count));
            $embed->addField("Huntress", self::gitVersion());
            $embed->addField("System", php_uname());
            $embed->addField("Uptime",
                sprintf("%s - *(connected %s)*", self::$startupTime->diffForHumans(null, true, null, 2),
                    self::$startupTime->toAtomString()));
            $embed->addField("Loaded Plugins", implode("\n", self::getPlugins()));

            $deps = "";
            foreach (self::composerPackages() as $p => $v) {
                $deps .= "[$p](https://packagist.org/packages/$p) ($v)\n";
            }
            $embed->addField("Composer dependencies", $deps);

            return self::send($message->channel, "", ['embed' => $embed]);
        } catch (\Throwable $e) {
            return self::exceptionHandler($message, $e, true);
        }
    }

    public static function restart(Huntress $bot, \CharlotteDunois\Yasmin\Models\Message $message): ?Promise
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

    public static function ping(Huntress $bot, \CharlotteDunois\Yasmin\Models\Message $message): ?Promise
    {
        try {
            $message_tx = \Carbon\Carbon::createFromTimestampMs((int) (round(microtime(true) * 1000)));
            $dstamp_tx  = \Carbon\Carbon::createFromTimestampMs((int) (\CharlotteDunois\Yasmin\Utils\Snowflake::deconstruct($message->id)->timestamp * 1000));
            return self::send($message->channel, "Pong!")->then(function (\CharlotteDunois\Yasmin\Models\Message $message) use ($message_tx, $dstamp_tx) {
                $message_rx = \Carbon\Carbon::createFromTimestampMs((int) (round(microtime(true) * 1000)));
                $dstamp_rx  = \Carbon\Carbon::createFromTimestampMs((int) (\CharlotteDunois\Yasmin\Utils\Snowflake::deconstruct($message->id)->timestamp * 1000));

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

    private static function gitPull(): string
    {
        $descriptorspec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];
        $pipes          = [];
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
            throw new \Exception("Could not init script");
        }
    }

    private static function gitVersion(): string
    {
        exec("git diff --quiet HEAD", $null, $rv);

        $commit = trim(`git rev-parse HEAD`);
        $tag    = $commit . ($rv == 1 ? "-modified" : "");
        $url    = "https://github.com/sylae/huntress/commit/" . $commit;

        return "[$tag]($url)";
    }

    private static function composerPackages(): array
    {
        $descriptorspec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"],
        ];
        $pipes          = [];
        $process        = proc_open('composer show -D -f json', $descriptorspec, $pipes);

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
            throw new \Exception("Could not init script");
        }
    }

    private static function formatBytes(int $b): string
    {
        $units = ["bytes", "KiB", "MiB", "GiB", "TiB", "PiB", "EiB", "ZiB", "YiB"];
        $c     = 0;
        $r     = [];
        foreach ($units as $k => $u) {
            if (($b / pow(1024, $k)) >= 1) {
                $r["bytes"] = $b / pow(1024, $k);
                $r["units"] = $u;
                $c++;
            }
        }
        return number_format($r["bytes"], 2) . " " . $r["units"];
    }

    private static function getPlugins(): array
    {
        $a = [];
        foreach (get_declared_classes() as $class) {
            if ((new \ReflectionClass($class))->implementsInterface("Huntress\PluginInterface")) {
                $a[] = $class;
            }
        }
        return $a;
    }
}
