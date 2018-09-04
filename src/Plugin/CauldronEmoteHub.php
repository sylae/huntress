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
class CauldronEmoteHub implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;

    public static function register(\Huntress\Bot $bot)
    {
        $bot->client->on(self::PLUGINEVENT_COMMAND_PREFIX . "emote", [self::class, "process"]);
        $bot->client->on(self::PLUGINEVENT_COMMAND_PREFIX . "importEmote", [self::class, "importmoji"]);
        $bot->client->on(self::PLUGINEVENT_COMMAND_PREFIX . "_CEHInternalAddGuildInviteURL", [self::class, "addInvite"]);
        $bot->client->on(self::PLUGINEVENT_DB_SCHEMA, [self::class, "db"]);
    }

    public static function db(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        $t = $schema->createTable("ceh_servers");
        $t->addColumn("guild", "bigint", ["unsigned" => true]);
        $t->addColumn("url", "text", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t->setPrimaryKey(["guild"]);
    }

    public static function addInvite(\Huntress\Bot $bot, \CharlotteDunois\Yasmin\Models\Message $message): \React\Promise\ExtendedPromiseInterface
    {
        if (is_null($message->member->roles->get("444432484114104321"))) {
            return self::unauthorized($message);
        } else {
            try {
                $args = self::_split($message->content);
                if (count($args) < 3) {
                    return self::error($message, "You dipshit :open_mouth:", "arg1 = guild id\narg2 = invite url\n\nhow hard can this be?");
                }

                $query = \Huntress\DatabaseFactory::get()->prepare('INSERT INTO ceh_servers (guild, url) VALUES(?, ?) '
                . 'ON DUPLICATE KEY UPDATE url=VALUES(url);', ['integer', 'string']);
                $query->bindValue(1, $args[1]);
                $query->bindValue(2, $args[2]);
                $query->execute();

                return self::send($message->channel, "Added! :triumph:");
            } catch (\Throwable $e) {
                return self::exceptionHandler($message, $e, true);
            }
        }
    }

    public static function importmoji(\Huntress\Bot $bot, \CharlotteDunois\Yasmin\Models\Message $message): ?\React\Promise\ExtendedPromiseInterface
    {
        if (!$message->member->permissions->has('MANAGE_EMOJIS')) {
            return self::unauthorized($message);
        } elseif (!$message->guild->me->permissions->has('MANAGE_EMOJIS')) {
            return self::error($message, "Unauthorized!", "I don't have permission to add emotes to this server. Please give me the **Manage Emojis** permission.");
        } else {
            try {
                $emotes = self::getEmotes($message->content);
                if (count($emotes) != 1) {
                    return self::error($message, "Invalid Arguments", "Give me exactly one emote as an argument");
                }
                $url = \CharlotteDunois\Yasmin\HTTP\APIEndpoints::CDN['url'] . \CharlotteDunois\Yasmin\HTTP\APIEndpoints::format(\CharlotteDunois\Yasmin\HTTP\APIEndpoints::CDN['emojis'], $emotes[0]['id'], ($emotes[0]['animated'] ? 'gif' : 'png'));

                return $message->guild->createEmoji($url, $emotes[0]['name'])->then(function (\CharlotteDunois\Yasmin\Models\Emoji $emote) use ($message) {
                    return self::send($message->channel, "Imported the emote {$emote->name} ({$emote->id})");
                }, function ($e) use ($message) {
                    return self::send($message->channel, "Failed to import emote!\n" . json_encode($e, JSON_PRETTY_PRINT));
                });
            } catch (\Throwable $e) {
                return self::exceptionHandler($message, $e, true);
            }
        }
    }

    public static function process(\Huntress\Bot $bot, \CharlotteDunois\Yasmin\Models\Message $message): ?\React\Promise\ExtendedPromiseInterface
    {
        try {
            $m = self::_split($message->content);
            if (count($m) < 2) {
                return self::error($message, "Missing Argument", "You need to tell me what to search for");
            }
            $code = $m[1];
            $x    = [];
            $bot->client->emojis->each(function ($v, $k) use ($code, &$x) {
                if ($v->guild->name == "Cauldron Emote Hub" || stripos($v->guild->name, "CEH") !== false) { // todo: do this better
                    $l = levenshtein($code, $v->name);
                    if (stripos($v->name, $code) !== false || $l < 3) {
                        $x[$k] = $l;
                    }
                }
            });
            asort($x);

            $s          = [];
            $guildcount = [];
            foreach (array_slice($x, 0, 50, true) as $code => $similarity) {
                $emote   = $bot->client->emojis->resolve($code);
                $sim_str = ($similarity == 0) ? "perfect match" : "similarity $similarity";

                $guildcount[$emote->guild->id] = true;

                $s[] = sprintf("%s `%s` - Found on %s, %s", (string) $emote, $emote->name, $emote->guild->name, $sim_str);
            }
            if (count($s) == 0) {
                $s[] = "No results found matching `$code`";
            }
            foreach ($guildcount as $guild => $count) {
                if (!$bot->client->guilds->get($guild)->members->has($message->author->id) && $url = self::getInvite($guild)) {
                    $s[] = $url;
                }
            }

            return self::send($message->channel, implode(PHP_EOL, $s), ['split' => true]);
        } catch (\Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }

    private static function getInvite(string $guildID)
    {
        $qb  = \Huntress\DatabaseFactory::get()->createQueryBuilder();
        $qb->select("*")->from("ceh_servers")->where('guild = ?')->setParameter(0, $guildID, "integer");
        $res = $qb->execute()->fetchAll();
        foreach ($res as $data) {
            return $data['url'];
        }
        return false;
    }
}
