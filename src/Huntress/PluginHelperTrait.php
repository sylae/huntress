<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

use Carbon\Carbon;
use CharlotteDunois\Yasmin\Interfaces\GuildChannelInterface;
use CharlotteDunois\Yasmin\Interfaces\TextChannelInterface;
use CharlotteDunois\Yasmin\Models\Guild;
use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\GuildMemberStorage;
use CharlotteDunois\Yasmin\Models\Message;
use CharlotteDunois\Yasmin\Models\MessageEmbed;
use CharlotteDunois\Yasmin\Models\Permissions;
use CharlotteDunois\Yasmin\Models\Role;
use CharlotteDunois\Yasmin\Models\TextChannel;
use Exception;
use InvalidArgumentException;
use League\HTMLToMarkdown\HtmlConverter;
use React\Promise\ExtendedPromiseInterface;
use React\Promise\PromiseInterface;
use React\Promise\RejectedPromise;
use Throwable;

/**
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
trait PluginHelperTrait
{

    public static function isTestingClient(): bool
    {
        return (php_uname('s') == "Windows NT");
    }

    public static function exceptionHandler(
        Message $message,
        Throwable $e,
        bool $showTrace = false
    ): ExtendedPromiseInterface {
        $msg = $e->getFile() . ":" . $e->getLine() . PHP_EOL . PHP_EOL . $e->getMessage();
        if ($showTrace) {
            $msg .= PHP_EOL;
            $len = strlen($message) + 8;
            $trace = $e->getTraceAsString();
            if (strlen($trace) > 2048 - $len) {
                $trace = substr($trace, 0, 2048 - $len - 3) . "...";
            }
            $msg .= "```\n" . $trace . "\n```";
        }
        return self::error($message, get_class($e), $msg);
    }

    public static function error(
        Message $message,
        string $title,
        string $msg
    ): ExtendedPromiseInterface {
        $embed = self::easyEmbed($message);
        $embed->setTitle("Error - " . $title)->setDescription(substr($msg, 0, 2048))->setColor(0xff8040);
        return self::send($message->channel, "", ['embed' => $embed]);
    }

    public static function easyEmbed(Message $message
    ): MessageEmbed {
        $embed = new MessageEmbed();
        return $embed->setTimestamp(time())
            ->setAuthor($message->guild->me->nickname ?? $message->client->user->username,
                $message->client->user->getDisplayAvatarURL());
    }

    public static function send(
        TextChannelInterface $channel,
        string $msg = "",
        array $opts = []
    ): ExtendedPromiseInterface {
        return $channel->send($msg, $opts);
    }

    public static function unauthorized(Message $message
    ): ExtendedPromiseInterface {
        return self::error($message, "Unauthorized!", "You are not permitted to use this command!");
    }

    public static function parseGuildUser(
        Guild $guild,
        string $string
    ): ?GuildMember {
        $string = trim($string);
        if (mb_strlen($string) == 0) {
            return null;
        }
        if (preg_match("/<@!*(\\d+)>/", $string, $matches)) {
            return $guild->members->resolve($matches[1]);
        }
        try {
            return $guild->members->first(function (GuildMember $val, $key) use ($string
            ) {
                if ($val->nickname == $string) {
                    return true;
                } elseif ($val->user->username . "#" . $val->user->discriminator == $string) {
                    return true;
                } elseif ($val->user->username == $string) {
                    return true;
                } else {
                    return false;
                }
            });
        } catch (InvalidArgumentException $e) {
            return null;
        }
    }

    public static function parseRole(Guild $guild, string $string): ?Role
    {
        $string = trim($string);
        if (mb_strlen($string) == 0) {
            return null;
        }
        if (preg_match("/<@&(\\d+)>/", $string, $matches)) {
            return $guild->roles->resolve($matches[1]);
        }
        try {
            return $guild->roles->first(function (Role $v) use ($string) {
                if (mb_strtolower($v->name) == mb_strtolower($string)) {
                    return true;
                } elseif ("@" . mb_strtolower($v->name) == mb_strtolower($string)) {
                    return true;
                } else {
                    return false;
                }
            });
        } catch (InvalidArgumentException $e) {
            return null;
        }
    }

    public static function htmlToMD(string $html): string
    {
        $converter = new HtmlConverter();
        $converter->getConfig()->setOption('strip_tags', true);
        return $converter->convert($html);
    }

    public static function paginateToCode(string $code, string $lang = ""): array
    {
        $lines = explode("\n", $code);
        $payload = 2000 - 7 - mb_strlen($lang);
        $packets = [];
        $pack = "";

        foreach ($lines as $line) {
            $len = mb_strlen($line) + 1;
            if ($len >= $payload) {
                throw new Exception("Single line cannot exceed message limit :v");
            }
            if ($len + mb_strlen($pack) <= $payload) {
                $pack .= $line . "\n";
            } else {
                $packets[] = $pack;
                $pack = $line . "\n";
            }
        }
        $packets[] = $pack;

        $r = [];
        foreach ($packets as $packet) {
            if (mb_strlen($packet) > 0) {
                $r[] = "```$lang" . PHP_EOL . $packet . "```";
            }
        }
        return $r;
    }

    public static function dump(
        TextChannelInterface $channel,
        $msg
    ): ExtendedPromiseInterface {
        $pre = "```json" . PHP_EOL . json_encode($msg, JSON_PRETTY_PRINT) . PHP_EOL . "```";
        return self::send($channel, $pre, ['split' => ['before' => '```json' . PHP_EOL, 'after' => '```']]);
    }

    public static function getEmotes(string $s): array
    {
        $regex = "/<(a?):(.*?):(\\d+)>/i";
        $matches = [];
        $r = [];
        preg_match_all($regex, $s, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $r[] = [
                'animated' => (bool)$match[1],
                'name' => $match[2],
                'id' => $match[3],
            ];
        }
        return $r;
    }

    public static function readTime(string $r, string $tz = "UTC"): Carbon
    {

        if (self::isRelativeTime($r)) {
            return self::timeRelative($r);
        } else {

            return (new Carbon($r, $tz));
        }
    }

    private static function isRelativeTime(string $r): bool
    {
        $matches = [];
        $nmatches = 0;
        if (preg_match_all("/((\\d+)([ywdhm]))/i", $r, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $nmatches++;
            }
        }
        $m = preg_replace("/((\\d+)([ywdhm]))/i", "", $r);
        return ($nmatches > 0 && mb_strlen(trim($m)) == 0);
    }

    private static function timeRelative(string $r): Carbon
    {
        $matches = [];
        if (preg_match_all("/((\\d+)([ywdhms]))/i", $r, $matches, PREG_SET_ORDER)) {
            $time = Carbon::now();
            foreach ($matches as $m) {
                $num = $m[2] ?? 1;
                $typ = mb_strtolower($m[3] ?? "m");
                switch ($typ) {
                    case "y":
                        $time->addYears($num);
                        break;
                    case "w":
                        $time->addWeeks($num);
                        break;
                    case "d":
                        $time->addDays($num);
                        break;
                    case "h":
                        $time->addHours($num);
                        break;
                    case "m":
                        $time->addMinutes($num);
                        break;
                    case "s":
                        $time->addSeconds($num);
                        break;
                }
            }
            return $time;
        } else {
            throw new Exception("Could not parse relative time.");
        }
    }

    public static function getMembersWithPermission(
        GuildChannelInterface $channel,
        int $permission = Permissions::PERMISSIONS['VIEW_CHANNEL']
    ): GuildMemberStorage {
        return $channel->getGuild()->members->filter(function (GuildMember $v) use ($channel, $permission) {
            return $v->permissionsIn($channel)->has($permission);
        });
    }

    public static function fetchMessage(Huntress $bot, string $url): PromiseInterface
    {
        $match = [];
        preg_match('/https:\/\/.*?discord(?:app)?\.com\/channels\/(\d+)\/(\d+)\/(\d+)/i', $url, $match);

        if (is_numeric($match[1]) && is_numeric($match[2]) && is_numeric($match[3])) {
            $guild = $bot->guilds->get($match[1]);
            if (is_null($guild)) {
                return new RejectedPromise("Unknown Guild");
            }

            /** @var TextChannel $channel */
            $channel = $guild->channels->get($match[2]);
            if (is_null($channel)) {
                return new RejectedPromise("Unknown Channel");
            }
            return $channel->fetchMessage($match[3]);
        } else {
            return new RejectedPromise("Invalid URL");
        }
    }

    public static function arg_substr(string $content, int $start, int $length = null)
    {
        $args = self::_split($content);
        if (array_key_last($args) < $start) {
            return false;
        }

        // @todo: this better
        $newstr = [];
        foreach ($args as $index => $arg) {
            if ($index < $start) {
                continue;
            }
            if (is_int($length) && $index >= $start + $length) {
                continue;
            }
            $newstr[] = $arg;
        }
        return implode(" ", $newstr);
    }

    public static function _split(string $string): array
    {
        $regex = '/(?<=^|\s)([\'"]?)(.+?)(?<!\\\\)\1(?=$|\s)/';
        preg_match_all($regex, $string . ' ', $matches);
        return $matches[2];
    }
}
