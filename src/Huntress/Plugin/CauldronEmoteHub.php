<?php

/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Yasmin\HTTP\APIEndpoints;
use CharlotteDunois\Yasmin\Models\Emoji;
use Doctrine\DBAL\Schema\Schema;
use Huntress\DatabaseFactory;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface as Promise;
use Throwable;

/**
 * Emoji management functions.
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class CauldronEmoteHub implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(EventListener::new()
            ->addEvent("dbSchema")
            ->setCallback([self::class, 'db'])
        );

        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("_CEHInternalAddGuildInviteURL")
            ->addGuild(444430952077197322)
            ->setCallback([self::class, "addInvite"]));

        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("importEmote")
            ->addCommand("importemote")
            ->setCallback([self::class, "importmoji"]));

        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("emote")
            ->addCommand("emoji")
            ->setCallback([self::class, "process"]));
    }

    public static function db(Schema $schema): void
    {
        $t = $schema->createTable("ceh_servers");
        $t->addColumn("guild", "bigint", ["unsigned" => true]);
        $t->addColumn("url", "text", ['customSchemaOptions' => DatabaseFactory::CHARSET]);
        $t->setPrimaryKey(["guild"]);
    }

    public static function addInvite(EventData $data): ?Promise
    {
        try {
            $p = new Permission("p.ceh.addinvite", $data->huntress, false);
            $p->addMessageContext($data->message);
            if (!$p->resolve()) {
                return $p->sendUnauthorizedMessage($data->message->channel);
            }

            $args = self::_split($data->message->content);
            if (count($args) < 3) {
                return self::error($data->message, "You dipshit :open_mouth:",
                    "arg1 = guild id\narg2 = invite url\n\nhow hard can this be?");
            }

            $query = DatabaseFactory::get()->prepare('INSERT INTO ceh_servers (guild, url) VALUES(?, ?) '
                . 'ON DUPLICATE KEY UPDATE url=VALUES(url);', ['integer', 'string']);
            $query->bindValue(1, $args[1]);
            $query->bindValue(2, $args[2]);
            $query->execute();

            return self::send($data->message->channel, "Added! :triumph:");
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true);
        }
    }

    public static function importmoji(EventData $data): ?Promise
    {
        $p = new Permission("p.emotes.import", $data->huntress, false);
        $p->addMessageContext($data->message);
        if (!$data->message->member->permissions->has('MANAGE_EMOJIS') || !$p->resolve()) {
            return self::unauthorized($data->message);
        } elseif (!$data->message->guild->me->permissions->has('MANAGE_EMOJIS')) {
            return self::error($data->message, "Unauthorized!",
                "I don't have permission to add emotes to this server. Please give me the **Manage Emojis** permission.");
        } else {
            try {
                $emotes = self::getEmotes($data->message->content);
                if (count($emotes) != 1) {
                    return self::error($data->message, "Invalid Arguments", "Give me exactly one emote as an argument");
                }
                $url = APIEndpoints::CDN['url'] . APIEndpoints::format(APIEndpoints::CDN['emojis'], $emotes[0]['id'],
                        ($emotes[0]['animated'] ? 'gif' : 'png'));

                return $data->message->guild->createEmoji($url,
                    $emotes[0]['name'])->then(function (Emoji $emote) use ($data) {
                    return self::send($data->message->channel, "Imported the emote {$emote->name} ({$emote->id})");
                }, function ($e) use ($data) {
                    return self::send($data->message->channel,
                        "Failed to import emote!\n" . json_encode($e, JSON_PRETTY_PRINT));
                });
            } catch (Throwable $e) {
                return self::exceptionHandler($data->message, $e, true);
            }
        }
    }

    public static function process(EventData $data): ?Promise
    {
        try {
            $p = new Permission("p.emotes.lookup", $data->huntress, true);
            $p->addMessageContext($data->message);
            if (!$p->resolve()) {
                return $p->sendUnauthorizedMessage($data->message->channel);
            }

            $m = self::_split($data->message->content);
            if (count($m) < 2) {
                return self::error($data->message, "Missing Argument", "You need to tell me what to search for");
            }
            $code = $m[1];
            $x = [];
            $gperm = new Permission("p.emotes.searchable", $data->huntress, true);
            $data->huntress->emojis->each(function (Emoji $v, $k) use ($code, &$x, $gperm) {
                if (!is_null($v->guild)) {
                    $gperm->addGuildContext($v->guild);
                    if ($gperm->resolve()) {
                        $l = levenshtein($code, $v->name);
                        if (stripos($v->name, $code) !== false || $l < 3) {
                            $x[$k] = $l;
                        }
                    }
                }
            });
            asort($x);

            $s = [];
            $guildcount = [];
            foreach (array_slice($x, 0, 50, true) as $code => $similarity) {
                $emote = $data->huntress->emojis->resolve($code);
                $sim_str = ($similarity == 0) ? "perfect match" : "similarity $similarity";

                $guildcount[$emote->guild->id] = true;

                $s[] = sprintf("%s `%s` - Found on %s, %s", (string)$emote, $emote->name, $emote->guild->name,
                    $sim_str);
            }
            if (count($s) == 0) {
                $s[] = "No results found matching `$code`";
            }
            foreach ($guildcount as $guild => $count) {
                if (!$data->huntress->guilds->get($guild)->members->has($data->message->author->id) && $url = self::getInvite($guild)) {
                    $s[] = $url;
                }
            }

            return self::send($data->message->channel, implode(PHP_EOL, $s), ['split' => true]);
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e);
        }
    }

    private static function getInvite(string $guildID)
    {
        $qb = DatabaseFactory::get()->createQueryBuilder();
        $qb->select("*")->from("ceh_servers")->where('guild = ?')->setParameter(0, $guildID, "integer");
        $res = $qb->execute()->fetchAll();
        foreach ($res as $data) {
            return $data['url'];
        }
        return false;
    }
}
