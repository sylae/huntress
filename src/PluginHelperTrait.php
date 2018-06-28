<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

/**
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
trait PluginHelperTrait
{

    public static function _split(string $string): array
    {
        //$regex = '/(.*?[^\\\\](\\\\\\\\)*?)\\s/';
        $regex = '/(?<=^|\s)([\'"]?)(.+?)(?<!\\\\)\1(?=$|\s)/';
        preg_match_all($regex, $string . ' ', $matches);
        return $matches[2];
    }

    public static function error(\CharlotteDunois\Yasmin\Models\Message $message, string $title, string $msg): \React\Promise\ExtendedPromiseInterface
    {
        $embed = self::easyEmbed($message);
        $embed->setTitle("Error - " . $title)->setDescription(substr($msg, 0, 2048))->setColor(0xff8040);
        return self::send($message->channel, "", ['embed' => $embed]);
    }

    public static function exceptionHandler(\CharlotteDunois\Yasmin\Models\Message $message, \Throwable $e, bool $showTrace = false): \React\Promise\ExtendedPromiseInterface
    {
        $msg = $e->getFile() . ":" . $e->getLine() . PHP_EOL . PHP_EOL . $e->getMessage();
        if ($showTrace) {
            $msg   .= PHP_EOL;
            $len   = strlen($message) + 8;
            $trace = $e->getTraceAsString();
            if (strlen($trace) > 2048 - $len) {
                $trace = substr($trace, 0, 2048 - $len - 3) . "...";
            }
            $msg .= "```\n" . $trace . "\n```";
        }
        return self::error($message, get_class($e), $msg);
    }

    public static function unauthorized(\CharlotteDunois\Yasmin\Models\Message $message): \React\Promise\ExtendedPromiseInterface
    {
        return self::error($message, "Unauthorized!", "You are not permitted to use this command!");
    }

    public static function easyEmbed(\CharlotteDunois\Yasmin\Models\Message $message): \CharlotteDunois\Yasmin\Models\MessageEmbed
    {
        $embed = new \CharlotteDunois\Yasmin\Models\MessageEmbed();
        return $embed->setTimestamp(time())
                        ->setAuthor($message->guild->me->nickname ?? $message->client->user->username, $message->client->user->getDisplayAvatarURL());
    }

    public static function parseGuildUser(\CharlotteDunois\Yasmin\Models\Guild $guild, string $string): ?\CharlotteDunois\Yasmin\Models\GuildMember
    {
        $string = trim($string);
        if (mb_strlen($string) == 0) {
            return null;
        }
        if (preg_match("/<@(\\d+)>/", $string, $matches)) {
            return $guild->members->resolve($matches[1]);
        }
        try {
            return $guild->members->first(function (\CharlotteDunois\Yasmin\Models\GuildMember $val, $key) use ($string) {
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
        } catch (\InvalidArgumentException $e) {
            return null;
        }
    }

    public static function htmlToMD(string $html): string
    {
        $converter = new \League\HTMLToMarkdown\HtmlConverter();
        $converter->getConfig()->setOption('strip_tags', true);
        return $converter->convert($html);
    }

    public static function paginateToCode(string $code, string $lang = ""): array
    {
        $lines   = explode("\n", $code);
        $payload = 2000 - 7 - mb_strlen($lang);
        $packets = [];
        $pack    = "";

        foreach ($lines as $line) {
            $len = mb_strlen($line) + 1;
            if ($len >= $payload) {
                throw new Exception("Single line cannot exceed message limit :v");
            }
            if ($len + mb_strlen($pack) <= $payload) {
                $pack .= $line . "\n";
            } else {
                $packets[] = $pack;
                $pack      = $line . "\n";
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

    public static function send(\CharlotteDunois\Yasmin\Interfaces\TextChannelInterface $channel, string $msg = "", array $opts = []): \React\Promise\ExtendedPromiseInterface
    {
        return $channel->send($msg, $opts);
    }
}
