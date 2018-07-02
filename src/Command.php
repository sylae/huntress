<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

/**
 * Description of Command
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
abstract class Command
{
    /**
     *
     * @var \Huntress\Bot
     */
    protected $bot;

    /**
     *
     * @var array
     */
    protected $config;

    /**
     *
     * @var \CharlotteDunois\Yasmin\Models\Message
     */
    protected $message;

    /**
     *
     * @var \Huntress\Library
     */
    protected $library;

    public function __construct(Bot $bot, \CharlotteDunois\Yasmin\Models\Message $message)
    {
        global $library;

        $this->bot     = $bot;
        $this->config  = $bot->config;
        $this->message = $message;
        $this->library = $library;
    }

    abstract public function process(): \React\Promise\ExtendedPromiseInterface;

    public static function _split(string $string): array
    {
        //$regex = '/(.*?[^\\\\](\\\\\\\\)*?)\\s/';
        $regex = '/(?<=^|\s)([\'"]?)(.+?)(?<!\\\\)\1(?=$|\s)/';
        preg_match_all($regex, $string . ' ', $matches);
        return $matches[2];
    }

    public static function snowflakeToTime(string $id): \Carbon\Carbon
    {
        return \Carbon\Carbon::createFromTimestamp((($id >> 22) + 1420070400000) / 1000);
    }

    public function error(string $title, string $message): \React\Promise\ExtendedPromiseInterface
    {
        $embed = $this->easyEmbed();
        $embed->setTitle("Error - " . $title)->setDescription(substr($message, 0, 2048))->setColor(0xff8040);
        return $this->send("", ['embed' => $embed]);
    }

    public function exceptionHandler(\Throwable $e, bool $showTrace = false): \React\Promise\ExtendedPromiseInterface
    {
        $message = $e->getFile() . ":" . $e->getLine() . PHP_EOL . PHP_EOL . $e->getMessage();
        if ($showTrace) {
            $message .= PHP_EOL;
            $len     = strlen($message) + 8;
            $trace   = $e->getTraceAsString();
            if (strlen($trace) > 2048 - $len) {
                $trace = substr($trace, 0, 2048 - $len - 3) . "...";
            }
            $message .= "```\n" . $trace . "\n```";
        }
        return $this->error(get_class($e), $message);
    }

    public function unauthorized(): \React\Promise\ExtendedPromiseInterface
    {
        return $this->error("Unauthorized!", "You are not permitted to use this command!");
    }

    public function send(string $content, array $options = []): \React\Promise\ExtendedPromiseInterface
    {
        return $this->message->channel->send($content, $options);
    }

    public function easyEmbed(): \CharlotteDunois\Yasmin\Models\MessageEmbed
    {
        $embed = new \CharlotteDunois\Yasmin\Models\MessageEmbed();
        return $embed->setTimestamp(time())
                        ->setAuthor($this->message->guild->me->nickname ?? $this->bot->client->user->username, $this->message->client->user->getDisplayAvatarURL());
    }

    public function parseUser(string $string): ?\CharlotteDunois\Yasmin\Models\GuildMember
    {
        $string = trim($string);
        if (mb_strlen($string) == 0) {
            return null;
        }
        if (preg_match("/<@(\\d+)>/", $string, $matches)) {
            return $this->message->guild->members->resolve($matches[1]);
        }
        try {
            return $this->message->guild->members->first(function (\CharlotteDunois\Yasmin\Models\GuildMember $val, $key) use ($string) {
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

    public function htmlToMD(string $html): string
    {
        $converter = new \League\HTMLToMarkdown\HtmlConverter();
        $converter->getConfig()->setOption('strip_tags', true);
        return $converter->convert($html);
    }

    public function paginateToCode(string $code, string $lang = ""): array
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
}
