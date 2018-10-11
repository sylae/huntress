<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use GetOpt\GetOpt;
use GetOpt\Operand;
use GetOpt\Option;
use GetOpt\Command;
use GetOpt\ArgumentException;

/**
 * Simple builtin to show user information
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class FanficLibrary implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;
    /**
     *
     * @var \Huntress\Library
     */
    static $library;

    /**
     *
     * @var \Carbon\Carbon
     */
    static $lastUpdateTime;

    public static function register(\Huntress\Bot $bot)
    {
        $bot->client->on(self::PLUGINEVENT_COMMAND_PREFIX . "find", [self::class, "find"]);
        $bot->client->on(self::PLUGINEVENT_COMMAND_PREFIX . "lookup", [self::class, "lookup"]);
        $bot->client->on(self::PLUGINEVENT_COMMAND_PREFIX . "reloadfanfic", [self::class, "reload"]);
        $bot->client->on(self::PLUGINEVENT_READY, [self::class, "init"]);
        $bot->client->on(self::PLUGINEVENT_DB_SCHEMA, [self::class, "db"]);
    }

    public static function db(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        $t = $schema->createTable("fanfic");
        $t->addColumn("fid", "integer", ["unsigned" => true, "autoincrement" => true]);
        $t->addColumn("title", "string", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t->addColumn("author", "string", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t->addColumn("authorurl", "string", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t->addColumn("status", "string", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t->addColumn("comments", "text", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t->addColumn("isSmut", "boolean");
        $t->addColumn("created", "datetime");
        $t->addColumn("modified", "datetime");
        $t->addColumn("words", "integer", ["unsigned" => true]);
        $t->setPrimaryKey(["fid"]);
        $t->addIndex(["title"]);
        $t->addIndex(["author"]);

        $t2 = $schema->createTable("fanfic_links");
        $t2->addColumn("lid", "integer", ["unsigned" => true, "autoincrement" => true]);
        $t2->addColumn("fid", "integer", ["unsigned" => true]);
        $t2->addColumn("url", "string", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t2->setPrimaryKey(["lid"]);

        $t3 = $schema->createTable("fanfic_tags");
        $t3->addColumn("tid", "integer", ["unsigned" => true, "autoincrement" => true]);
        $t3->addColumn("fid", "integer", ["unsigned" => true]);
        $t3->addColumn("tag", "string", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t3->setPrimaryKey(["tid"]);
        $t3->addIndex(["fid"]);
    }

    public static function init(\Huntress\Bot $bot)
    {
        self::$library = new \Huntress\Library();
        if (file_exists("temp/fanficDB.json")) {
            self::update();
            self::$lastUpdateTime = \Carbon\Carbon::createFromTimestamp(filemtime("temp/fanficDB.json"));
        } else {
            $bot->log->warning("fanficDB.json not found. Run !reloadfanfic :o");
        }
    }

    public static function reload(\Huntress\Bot $bot, \CharlotteDunois\Yasmin\Models\Message $message): \React\Promise\ExtendedPromiseInterface
    {
        if (!in_array($message->author->id, $bot->config['evalUsers'])) {
            return self::unauthorized($message);
        } else {
            try {
                return \CharlotteDunois\Yasmin\Utils\URLHelpers::resolveURLToData($bot->config['fanficURL'])->then(function (string $body) {
                    file_put_contents("temp/fanficDB.json", "[" . str_replace("}\n{", "},\n{", $body . "]"));
                })->then(function () use ($message) {
                    return self::send($message->channel, "File downloaded!");
                })->then(function () {
                    self::update();
                    self::$lastUpdateTime = \Carbon\Carbon::now();
                });
            } catch (\Throwable $e) {
                return self::exceptionHandler($message, $e);
            }
        }
    }

    public static function find(\Huntress\Bot $bot, \CharlotteDunois\Yasmin\Models\Message $message): \React\Promise\ExtendedPromiseInterface
    {
        try {
            $args = self::_split($message->content);
            $user = $message->member;

            $v = self::$library->titleSearch(trim(str_replace($args[0], "", $message->content)), 1)[0];

            $embed = self::easyEmbed($message);
            $embed->setTitle($v->title)
            ->setDescription(self::htmlToMD((string) $v->comments))
            ->setColor($user->displayColor);


            if (mb_strlen(trim($v->cover ?? "")) > 0) {
                $embed->setThumbnail($v->cover);
            }

            $l = [];
            foreach ($v->links ?? [] as $link) {
                $l[] = self::storyURL($link);
            }
            $embed->addField("Links", implode(" - ", $l), true);

            if (mb_strlen(trim($v->author ?? "")) > 0) {
                if (mb_strlen(trim($v->authorurl ?? "")) > 0) {
                    $embed->addField("Author", "[{$v->author}]({$v->authorurl})");
                } else {
                    $embed->addField("Author", $v->authorurl, true);
                }
            }
            if (mb_strlen(trim($v->status ?? "")) > 0) {
                $embed->addField("Status", $v->status, true);
            }

            $embed->addField("Wordcount", number_format($v->words), true);

            if (count($v->tags ?? []) > 0) {
                $embed->addField("Tags", implode(", ", $v->tags));
            }
            return self::send($message->channel, "", ['embed' => $embed]);
        } catch (\Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }

    public static function lookup(\Huntress\Bot $bot, \CharlotteDunois\Yasmin\Models\Message $message): \React\Promise\ExtendedPromiseInterface
    {
        try {
            $time  = \Carbon\Carbon::createFromTimestampMs((int) round(microtime(true) * 1000));
            $count = number_format(count(self::$library));
            return self::send($message->channel, ":crystal_ball: Searching **$count** stories...")->then(function (\CharlotteDunois\Yasmin\Models\Message $sent) use ($message, $time) {
                $args   = self::_split($message->content);
                $search = trim(str_replace($args[0], "", $message->content));
                $user   = $message->member;

                $res = self::$library->titleSearch($search, 10);

                $embed = self::easyEmbed($message);
                $embed->setTitle("Browsing stories matching: `$search`")
                ->setDescription("For more details, use `!find [FIC_NAME]`")
                ->setColor($user->displayColor);
                foreach ($res as $k => $v) {
                    $title = [];
                    $data  = [];

                    $title[] = "{$v->title}";
                    if (mb_strlen(trim($v->author ?? "")) > 0) {
                        $title[] = "*by {$v->author}*";
                    }
                    if (mb_strlen(trim($v->status ?? "")) > 0) {
                        $title[] = "*({$v->status})*";
                    }
                    if ($v->words ?? 0 > 0) {
                        $data[] = "*(" . number_format($v->words) . " words)*";
                    }
                    if (mb_strlen(trim($v->comments ?? "")) > 0) {
                        $data[] = "*(" . self::htmlToMD((string) $v->comments) . ")*";
                    }
                    if (count($v->tags ?? []) > 0) {
                        $data[] = "\n__Tagged__: " . implode(", ", $v->tags);
                    }
                    $data[] = "\n";

                    $embed->addField(implode(" ", $title), implode(" ", $data));
                }
                $spent = number_format((\Carbon\Carbon::createFromTimestampMs((int) round(microtime(true) * 1000))->format("U.u") - $time->format("U.u")), 1);
                $count = number_format(count(self::$library));
                return $sent->edit("Searched **$count** records in {$spent} seconds.", ['embed' => $embed]);
            });
        } catch (\Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }

    private static function update(): void
    {
        self::$library->loadFanfic();
    }

    private static function storyURL(string $url): string
    {
        $regex   = "/https?\\:\\/\\/(.+?)\\//i";
        $matches = [];
        if (preg_match($regex, $url, $matches)) {
            switch ($matches[1]) {
                case "forums.spacebattles.com":
                    $tag = "SB";
                    break;
                case "forums.sufficientvelocity.com":
                    $tag = "SV";
                    break;
                case "archiveofourown.org":
                    $tag = "AO3";
                    break;
                case "www.fanfiction.net":
                case "fanfiction.net":
                    $tag = "FFN";
                    break;
                case "forum.questionablequesting.com":
                case "questionablequesting.com":
                    $tag = "QQ";
                    break;
                default:
                    return $url;
            }
            return "[$tag]($url)";
        }
        return $url;
    }
}
