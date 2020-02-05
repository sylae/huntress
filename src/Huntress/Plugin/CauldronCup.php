<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use Carbon\Carbon;
use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\Message;
use CharlotteDunois\Yasmin\Models\MessageEmbed;
use CharlotteDunois\Yasmin\Models\Permissions;
use CharlotteDunois\Yasmin\Models\TextChannel;
use Doctrine\DBAL\Schema\Schema;
use Exception;
use GetOpt\ArgumentException;
use GetOpt\Command;
use GetOpt\GetOpt;
use GetOpt\Operand;
use GetOpt\Option;
use Huntress\DatabaseFactory;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Huntress\Snowflake;
use React\Promise\PromiseInterface;
use Throwable;
use function React\Promise\all;

/**
 * Cauldron Cup / PCT Cup management
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class CauldronCup implements PluginInterface
{
    use PluginHelperTrait;

    const NAME = "Cauldron Cup Season Five";

    const NOTE = <<<NOTE
**Welcome to Cauldron Cup Season Five!**
As a reminder, please do not publicly share who you are competing with or what you are writing until the coordinators give you the okay. You can use this channel to ask your opponent or the coordinators any questions. You will have no less than **72 hours** to complete your snips and submit them for processing.

Your submission should be around **1k words**, no biggie if more or less but shoot for that. The link to submit snips is pinned in <#565085613955612702>. Good luck!

Competitors have permission to pin anything they might find useful. Please pin your characters once you've chosen them! :)

__What you need to do:__
1. Pick a character! Your opponent will do the same.
2. Write a snippet featuring the two characters within the genre and including (as loosly or tightly as you want) the theme.
3. Submit your snippet within 72 hours of this post to the form.

Please write your posts in Markdown format, with a blank line between paragraphs. Here's a cheatsheet: https://commonmark.org/help/

This round's **genre** is: *%s*
Your **theme** is: *%s*
NOTE;

    public static function register(Huntress $bot)
    {
        $eh = EventListener::new()
            ->addCommand("ccup")
            ->addCommand("ccm")
            ->addGuild(385678357745893376)
            ->setCallback([self::class, "cup"]);
        $bot->eventManager->addEventListener($eh);

        $dbEv = EventListener::new()->addEvent("dbSchema")->setCallback([self::class, 'db']);
        $bot->eventManager->addEventListener($dbEv);
    }

    public static function db(Schema $schema): void
    {
        $t = $schema->createTable("ccup");
        $t->addColumn("idMatch", "bigint", ["unsigned" => true]);
        $t->addColumn("idChannel", "bigint", ["unsigned" => true]);
        $t->addColumn("round", "string", ['length' => 255, 'customSchemaOptions' => DatabaseFactory::CHARSET]);
        $t->addColumn("theme", "string", ['length' => 255, 'customSchemaOptions' => DatabaseFactory::CHARSET]);
        $t->addColumn("idCompA", "bigint", ["unsigned" => true, 'notnull' => false]);
        $t->addColumn("idCompB", "bigint", ["unsigned" => true, 'notnull' => false]);
        $t->addColumn("charA", "string",
            ['length' => 255, 'customSchemaOptions' => DatabaseFactory::CHARSET, 'notnull' => false]);
        $t->addColumn("charB", "string",
            ['length' => 255, 'customSchemaOptions' => DatabaseFactory::CHARSET, 'notnull' => false]);
        $t->setPrimaryKey(["idMatch"]);
    }

    public static function cup(EventData $data)
    {
        $p = new Permission("p.ccup.manage", $data->huntress, false);
        $p->addMessageContext($data->message);
        if (!$p->resolve()) {
            return $p->sendUnauthorizedMessage($data->message->channel);
        }
        try {
            $getOpt = new GetOpt();
            $getOpt->set(GetOpt::SETTING_SCRIPT_NAME, '!ccup');
            $getOpt->set(GetOpt::SETTING_STRICT_OPERANDS, true);
            $commands = [];
            $commands[] = Command::create('create',
                [self::class, 'create'])->setDescription('Create a match channel')->addOperands([
                (new Operand('genre', Operand::REQUIRED))->setValidation('is_string'),
                (new Operand('theme', Operand::REQUIRED))->setValidation('is_string'),
            ]);
            $commands[] = Command::create('set',
                [self::class, 'set'])->setDescription('Set match information')->addOperands([
                (new Operand('key', Operand::REQUIRED))->setValidation('is_string'),
                (new Operand('value', Operand::REQUIRED))->setValidation('is_string'),
            ]);
            $commands[] = Command::create('post',
                [self::class, 'post'])->setDescription('Announce the match!')->addOptions([
                (new Option('s', 'spoiler',
                    GetOpt::OPTIONAL_ARGUMENT))->setDescription("Add a spoiler warning for the match."),
                (new Option('n', 'note',
                    GetOpt::OPTIONAL_ARGUMENT))->setDescription("Add an additional note to be displayed."),
            ]);
            $commands[] = Command::create('info',
                [self::class, 'info'])->setDescription('Print match state');
            $getOpt->addCommands($commands);
            try {
                $args = substr(strstr($data->message->content, " "), 1);
                $getOpt->process((string) $args);
            } catch (ArgumentException $exception) {
                return self::send($data->message->channel, $getOpt->getHelpText());
            }
            $command = $getOpt->getCommand();
            if (is_null($command)) {
                return self::send($data->message->channel, $getOpt->getHelpText());
            }
            return call_user_func($command->getHandler(), $getOpt, $data->message);
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e);
        }
    }

    public static function create(GetOpt $getOpt, Message $message): PromiseInterface
    {
        return self::createMatchAndRoom($message, $getOpt->getOperand("genre"), $getOpt->getOperand("theme"));
    }

    private static function createMatchAndRoom(Message $message, string $round, string $theme): PromiseInterface
    {
        try {
            $id = Snowflake::generate();

            // create match plugin entry in database
            $qb = $message->client->db->createQueryBuilder();
            $qb->insert("match_matches")->values([
                'idMatch' => '?',
                'created' => '?',
                'duedate' => '?',
            ])->setParameter(0, $id, "integer")
                ->setParameter(1, Carbon::now(), "datetime")
                ->setParameter(2, Carbon::now(), "datetime")
                ->execute();

            // create channel
            return $message->guild->createChannel([
                'name' => "ccup-secret-" . Snowflake::format($id),
                'type' => "text",
                'parent' => 391049778286559253,
            ], "Created on behalf of {$message->author->tag} from {$message->id}")->then(function (
                TextChannel $channel
            ) use ($message, $round, $theme, $id) {
                try {
                    // create ccup match entry in database
                    $qb = $message->client->db->createQueryBuilder();
                    $qb->insert("ccup")->values([
                        'idMatch' => '?',
                        'idChannel' => '?',
                        'round' => '?',
                        'theme' => '?',
                    ])->setParameter(0, $id, "integer")
                        ->setParameter(1, $channel->id, "integer")
                        ->setParameter(2, $round, "string")
                        ->setParameter(3, $theme, "string")
                        ->execute();

                    // send message and pin it, then return status success
                    $p1 = $channel->send(sprintf(self::NOTE, $round, $theme))->then(function (Message $m) {
                        return $m->pin();
                    });
                    $p2 = $message->channel->send("<#{$channel->id}> :ok_hand:");
                    return all([$p1, $p2]);
                } catch (Throwable $e) {
                    self::exceptionHandler($message, $e);
                }

            }, function ($error) use ($message) {
                self::error($message, "Error", json_encode($error));
            });

        } catch (Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }

    public static function set(GetOpt $getOpt, Message $message): PromiseInterface
    {
        try {
            $matchID = self::getMatchFromChannel($message->channel);
            $match = $message->client->db->createQueryBuilder()->select("*")->from("ccup")->where('idMatch = ?')->setParameter(0,
                $matchID)->execute()->fetchAll();

            $k = mb_strtolower($getOpt->getOperand("key"));
            $v = $getOpt->getOperand("value");
            switch ($k) {
                case "compa":
                case "a":
                    $user = self::parseGuildUser($message->guild, $v);
                    self::setValue($message->client, $matchID, 'idCompA', (int) $user->id);
                    return self::summonUser($user, $message->channel);
                case "compb":
                case "b":
                    $user = self::parseGuildUser($message->guild, $v);
                    self::setValue($message->client, $matchID, 'idCompB', (int) $user->id);
                    return self::summonUser($user, $message->channel);
                case "chara":
                    self::setValue($message->client, $matchID, 'charA', $v);
                    return self::printInfo($message->channel, $matchID);
                case "charb":
                    self::setValue($message->client, $matchID, 'charB', $v);
                    return self::printInfo($message->channel, $matchID);
                case "snipa":
                    $user = $message->guild->members->get($match[0]['idCompA']);
                    self::addEntry($user, $matchID, $v);
                    return self::printInfo($message->channel, $matchID);
                case "snipb":
                    $user = $message->guild->members->get($match[0]['idCompB']);
                    self::addEntry($user, $matchID, $v);
                    return self::printInfo($message->channel, $matchID);
            }
        } catch (Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }

    private static function getMatchFromChannel(TextChannel $channel): int
    {
        $match = $channel->client->db->createQueryBuilder()->select("*")->from("ccup")->where('idChannel = ?')->setParameter(0,
            $channel->id)->execute()->fetchAll();

        if (count($match) != 1) {
            throw new Exception("No match bound to this channel.");
        } else {
            return $match[0]['idMatch'];
        }
    }

    private static function setValue(Huntress $bot, int $match, string $key, $value)
    {
        $query = $bot->db->prepare('UPDATE ccup set `' . $key . '` = ? where idMatch = ?
        ', [(is_int($value) ? 'integer' : 'string'), 'integer']); // i know
        $query->bindValue(1, $value);
        $query->bindValue(2, $match);
        $query->execute();
    }

    public static function summonUser(GuildMember $member, TextChannel $channel): PromiseInterface
    {
        return $channel->overwritePermissions($member,
            Permissions::PERMISSIONS['VIEW_CHANNEL'] | Permissions::PERMISSIONS['MANAGE_MESSAGES'],
            0)->then(function ($overwrites) use ($channel, $member) {
            return $channel->send("$member come here.");
        });
    }

    private static function printInfo(TextChannel $channel, int $matchID): PromiseInterface
    {
        $matchCup = $channel->client->db->createQueryBuilder()->select("*")->from("ccup")->where('idMatch = ?')->setParameter(0,
            $matchID)->execute()->fetchAll();

        $matchObj = Match::getMatchInfo($matchID, $channel->guild);

        if (count($matchCup) != 1) {
            throw new Exception("No match bound to this channel.");
        } else {
            $x = [];
            foreach ($matchCup[0] as $k => $v) {
                if (stripos($k, "idComp") === 0) {
                    $x[] = sprintf("`%s`: %s", $k, $channel->guild->members->get($v)->user->tag);
                } else {
                    $x[] = sprintf("`%s`: %s", $k, $v);
                }
            }
            // get each comp entry
            $matchA = $matchObj->entries->filter(function ($v) use ($matchCup) {
                return $v->user->id == $matchCup[0]['idCompA'];
            })->first();
            $matchB = $matchObj->entries->filter(function ($v) use ($matchCup) {
                return $v->user->id == $matchCup[0]['idCompB'];
            })->first();

            $x[] = sprintf("`%s`: %s / %s", "snipA", $matchA->id ?? null, $matchA->data ?? null);
            $x[] = sprintf("`%s`: %s / %s", "snipB", $matchB->id ?? null, $matchB->data ?? null);

            return $channel->send(implode(PHP_EOL, $x));
        }
    }

    private static function addEntry(GuildMember $member, int $matchID, string $entry)
    {
        $qb = DatabaseFactory::get()->createQueryBuilder();
        $qb->insert("match_competitors")->values([
            'idCompetitor' => '?',
            'idMatch' => '?',
            'idDiscord' => '?',
            'created' => '?',
            'data' => '?',
        ])
            ->setParameter(0, Snowflake::generate(), "integer")
            ->setParameter(1, $matchID, "integer")
            ->setParameter(2, $member->id, "integer")
            ->setParameter(3, Carbon::now(), "datetime")
            ->setParameter(4, $entry, "text")
            ->execute();
    }

    public static function post(GetOpt $getOpt, Message $message): PromiseInterface
    {

        try {
            $matchID = self::getMatchFromChannel($message->channel);
            self::setDeadline($matchID, $message->client);

            $match = $message->client->db->createQueryBuilder()->select("*")->from("ccup")->where('idMatch = ?')->setParameter(0,
                $matchID)->execute()->fetchAll();
            $info = Match::getMatchInfo($matchID, $message->guild);

            $embed = new MessageEmbed();
            $embed->setTitle(self::NAME . " - {$match[0]['round']}, {$match[0]['theme']}");
            $embed->setTimestamp($info->duedate->timestamp);
            $embed->setDescription(sprintf("Voting is open until *%s* [[other timezones](https://syl.ae/time/#%s)]",
                $info->duedate->toCookieString(), $info->duedate->timestamp));
            $counter = 1;

            $embed->addField("Characters", "{$match[0]['charA']} / {$match[0]['charB']}");

            if (!is_null($getOpt->getOption("note"))) {
                $embed->addField("Note", $getOpt->getOption("note"));
            }
            if (!is_null($getOpt->getOption("spoiler"))) {
                $embed->addField("SPOILER ALERT", $getOpt->getOption("spoiler"));
            }
            $info->entries->each(function ($v, $k) use ($info, $embed, &$counter) {
                $voteCMD = sprintf("!match vote %s %s", Snowflake::format($info->idMatch), Snowflake::format($v->id));
                $data = sprintf("%s\nVote with `%s` ([mobile](https://syl.ae/copy.php?v=%s))", $v->data, $voteCMD,
                    urlencode($voteCMD));
                $embed->addField(sprintf("Option %s", $counter), $data);
                $counter++;
            });
            $prom = self::send($message->client->channels->get(319816145353834509),
                "<@&385685972664320003>, a match is available for voting!",
                ['embed' => $embed]);

            return $prom->then(function ($announcement) use ($message) {
                $message->channel->send("Announcement sent!");
                return $announcement->pin();
            }, function ($error) use ($message) {
                return self::dump($message->channel, $error);
            });
        } catch (Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }

    private static function setDeadline(int $matchID, Huntress $bot)
    {
        $query = $bot->db->prepare('UPDATE match_matches set created = ?, duedate = ? where idMatch = ?',
            ['datetime', 'datetime', 'integer']);
        $query->bindValue(1, Carbon::now());
        $query->bindValue(2, Carbon::now()->addDays(2));
        $query->bindValue(3, $matchID);
        $query->execute();
    }

    public static function info(GetOpt $getOpt, Message $message): PromiseInterface
    {
        $matchID = self::getMatchFromChannel($message->channel);
        return self::printInfo($message->channel, $matchID);
    }
}
