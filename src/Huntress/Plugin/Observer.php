<?php

/*
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use \Huntress\Huntress;
use \React\Promise\ExtendedPromiseInterface as Promise;
use \Huntress\EventListener;
use \CharlotteDunois\Yasmin\Models\MessageEmbed;

/**
 * Moderation logging and user reporting
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class Observer implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(EventListener::new()->addEvent("dbSchema")->setCallback([self::class, 'db']));
        $bot->on("messageDelete", [self::class, "observerHandler"]);
        $bot->on("messageDeleteBulk", [self::class, "observerHandlerBulk"]);
        $bot->on("messageDeleteRaw", [self::class, "rawHandler"]);
        $bot->on("messageDeleteRawBulk", [self::class, "rawHandler"]);
        $bot->on("messageReactionAdd", [self::class, "reportHandler"]);

        $eh = \Huntress\EventListener::new()
        ->addCommand("observer")
        ->setCallback([self::class, "config"]);
        $bot->eventManager->addEventListener($eh);
    }

    public static function db(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        $t1 = $schema->createTable("observer");
        $t1->addColumn("idGuild", "bigint", ["unsigned" => true]);
        $t1->addColumn("idChannel", "bigint", ["unsigned" => true]);
        $t1->addColumn("reportEmote", "bigint", ["unsigned" => true, 'notnull' => false]);
        $t1->setPrimaryKey(["idGuild"]);
    }

    public static function config(\Huntress\EventData $data)
    {
        if (!in_array($data->message->author->id, $data->message->client->config['evalUsers'])) {
            return self::unauthorized($data->messag);
        }
        try {
            $args = self::_split($data->message->content);
            if (count($args) != 2) {
                return $data->message->channel->send("Malformed command.");
            }
            if ($channel = $data->message->guild->channels->get($args[1])) {
                self::setMonitor($data->message->guild, $channel);
                return $data->message->channel->send("{$channel} set as reporting channel for guild `{$data->message->guild->name}`");
            }
            // okay it must be an emote then
            self::setReport($data->message->guild, $args[1]);
            return $data->message->channel->send("`{$args[1]}` set as reporting emote for guild `{$data->message->guild->name}`");
        } catch (\Throwable $e) {
            return self::exceptionHandler($data->message, $e);
        }
    }

    public static function observerHandlerBulk(\CharlotteDunois\Collect\Collection $messages): Promise
    {
        return \React\Promise\all($messages->map(function (\CharlotteDunois\Yasmin\Models\Message $message) {
            return self::observerHandler($message);
        })->all());
    }

    public static function observerHandler(\CharlotteDunois\Yasmin\Models\Message $message): ?Promise
    {
        try {
            $info = self::getInfo($message->guild);

            if (is_null($info) || !$message->guild->channels->has($info['idChannel'] ?? null) || $message->author->bot) {
                return null;
            }

            $embed = self::embedMessage($message);

            $msg = "🗑 Message deleted - from {$message->channel}";
            return $message->guild->channels->get($info['idChannel'])->send($msg, ['embed' => $embed]);
        } catch (\Throwable $e) {
            \Sentry\captureException($e);
            $message->client->log->addWarning($e->getMessage(), ['exception' => $e]);
        }
    }

    public static function rawHandler(\CharlotteDunois\Yasmin\Models\TextChannel $channel, $messages): ?Promise
    {
        try {
            $info = self::getInfo($channel->guild);

            if (!is_array($messages)) {
                $messages = [$messages];
            }

            if (is_null($info) || !$channel->guild->channels->has($info['idChannel'] ?? null)) {
                return null;
            }

            $prom = [];
            foreach ($messages as $message) {
                $snowflake = \Carbon\Carbon::createFromTimestamp(\CharlotteDunois\Yasmin\Utils\Snowflake::deconstruct($message)->timestamp)->toCookieString();

                $msg    = "🗑 Uncached message deleted - from {$channel} with timestamp `{$snowflake}`.";
                $prom[] = $channel->guild->channels->get($info['idChannel'])->send($msg);
            }
            return \React\Promise\all($prom);
        } catch (\Throwable $e) {
            \Sentry\captureException($e);
            $channel->client->log->addWarning($e->getMessage(), ['exception' => $e]);
        }
    }

    public static function reportHandler(\CharlotteDunois\Yasmin\Models\MessageReaction $reaction, \CharlotteDunois\Yasmin\Models\User $user): ?Promise
    {
        try {
            $info = self::getInfo($reaction->message->guild);

            // is guild set up properly?
            if (is_null($info) || is_null($info['reportEmote']) || !$reaction->message->guild->channels->has($info['idChannel'])) {
                return null;
            }

            // is this our report emote and a valid user?
            if ($user->bot || $reaction->message->author->id == $user->id || $reaction->emoji->id != $info['reportEmote']) {
                return null;
            }

            $embed = self::embedMessage($reaction->message, 0xcc0000);

            $member = $reaction->message->guild->members->get($user->id);
            $msg    = "**⚠ Reported message** - reported by {$member->displayName} in {$reaction->message->channel} - " . $reaction->message->getJumpURL();
            return \React\Promise\all([
                $reaction->message->guild->channels->get($info['idChannel'])->send($msg, ['embed' => $embed]),
                $reaction->remove($member->user),
            ]);
        } catch (\Throwable $e) {
            \Sentry\captureException($e);
            $reaction->client->log->addWarning($e->getMessage(), ['exception' => $e]);
        }
    }

    private static function embedMessage(\CharlotteDunois\Yasmin\Models\Message $message, int $color = null): MessageEmbed
    {
        $embed = new MessageEmbed();
        $embed->setDescription($message->content)
        ->setAuthor($message->author->tag, $message->author->getDisplayAvatarURL())
        ->setTimestamp($message->createdTimestamp);

        if (is_int($color)) {
            $embed->setColor($color);
        }

        if (count($message->attachments) > 0) {
            $att = [];
            foreach ($message->attachments as $attach) {
                $att[] = "{$attach->url} (" . number_format($attach->size) . " bytes)";
            }
            $embed->addField("Attachments", implode("\n", $att));
        }
        return $embed;
    }

    private static function getInfo(\CharlotteDunois\Yasmin\Models\Guild $guild, bool $forceRefresh = false): ?array
    {
        static $cache = [];

        if (!$forceRefresh && array_key_exists($guild->id, $cache) && $cache[$guild->id][0] + 30 > time()) {
            return $cache[$guild->id][1];
        }

        $qb  = $guild->client->db->createQueryBuilder();
        $qb->select("*")->from("observer")->where('`idGuild` = ?')->setParameter(0, $guild->id, "integer");
        $res = $qb->execute()->fetchAll();
        if (count($res) == 1) {
            $cache[$guild->id] = [time(), $res[0]];
            return $res[0];
        }
        return null;
    }

    private static function setMonitor(\CharlotteDunois\Yasmin\Models\Guild $guild, \CharlotteDunois\Yasmin\Models\TextChannel $channel)
    {
        $query = $guild->client->db->prepare('INSERT INTO observer (`idGuild`, `idChannel`) VALUES(?, ?) '
        . 'ON DUPLICATE KEY UPDATE `idChannel`=VALUES(`idChannel`);', ['integer', 'integer']);
        $query->bindValue(1, $guild->id);
        $query->bindValue(2, $channel->id);
        $query->execute();

        self::getInfo($guild, true);
    }

    private static function setReport(\CharlotteDunois\Yasmin\Models\Guild $guild, int $emote)
    {
        $query = $guild->client->db->prepare('UPDATE observer SET `reportEmote` = ? where `idGuild` = ?;', ['integer', 'integer']);
        $query->bindValue(1, $emote);
        $query->bindValue(2, $guild->id);
        $query->execute();

        self::getInfo($guild, true);
    }
}
