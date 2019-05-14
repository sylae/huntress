<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use \Huntress\Huntress;
use \React\Promise\ExtendedPromiseInterface as Promise;
use Carbon\Carbon;
use CharlotteDunois\Yasmin\Models\Message;
use GetOpt\GetOpt;
use GetOpt\Operand;
use GetOpt\Option;
use GetOpt\Command;
use GetOpt\ArgumentException;
use Huntress\Snowflake;
use CharlotteDunois\Collect\Collection;

/**
 * Simple builtin to show user information
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class Match implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->on(self::PLUGINEVENT_DB_SCHEMA, [self::class, "db"]);
        $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "match", [self::class, "matchHandler"]);
        $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "tally", [self::class, "tallyHandler"]);
    }

    public static function db(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        $t1 = $schema->createTable("match_matches");
        $t1->addColumn("idMatch", "bigint", ["unsigned" => true]);
        $t1->addColumn("created", "datetime");
        $t1->addColumn("duedate", "datetime");
        $t1->addColumn("title", "string", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET, 'notnull' => false]);
        $t1->setPrimaryKey(["idMatch"]);

        $t2 = $schema->createTable("match_competitors");
        $t2->addColumn("idCompetitor", "bigint", ["unsigned" => true]);
        $t2->addColumn("idMatch", "bigint", ["unsigned" => true]);
        $t2->addColumn("idDiscord", "bigint", ["unsigned" => true]);
        $t2->addColumn("created", "datetime");
        $t2->addColumn("data", "string", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET, 'notnull' => false]);
        $t2->setPrimaryKey(["idMatch", "idCompetitor"]);
        $t2->addForeignKeyConstraint($t1, ["idMatch"], ["idMatch"], ["onUpdate" => "CASCADE", "onDelete" => "CASCADE"], "fk_idMatch");

        $t3 = $schema->createTable("match_votes");
        $t3->addColumn("idVoter", "bigint", ["unsigned" => true]);
        $t3->addColumn("idMatch", "bigint", ["unsigned" => true]);
        $t3->addColumn("idCompetitor", "bigint", ["unsigned" => true]);
        $t3->addColumn("created", "datetime");
        $t3->setPrimaryKey(["idVoter", "idMatch"]);
        $t3->addForeignKeyConstraint($t2, ["idMatch", "idCompetitor"], ["idMatch", "idCompetitor"], ["onUpdate" => "CASCADE", "onDelete" => "CASCADE"], "fk_idMatch_idCompetitor");
    }

    public static function matchHandler(Huntress $bot, Message $message): ?Promise
    {
        try {
            $getOpt     = new GetOpt();
            $getOpt->set(GetOpt::SETTING_SCRIPT_NAME, '!match');
            $getOpt->set(GetOpt::SETTING_STRICT_OPERANDS, true);
            $commands   = [];
            $commands[] = Command::create('create', [self::class, 'createMatch'])->setDescription('Create a Match to vote on')->addOperands([
                (new Operand('title', Operand::REQUIRED))->setValidation('is_string')->setDescription('Display title of the match.'),
                (new Operand('period', Operand::OPTIONAL))->setValidation('is_string')->setDefaultValue("24h")->setDescription('Length voting is allowed. Default: 24h'),
            ]);
            $commands[] = Command::create('addcompetitor', [self::class, 'addCompetitor'])->setDescription('Add a competitor to a match')->addOperands([
                (new Operand('match', Operand::REQUIRED))->setValidation('is_string')->setDescription('The match you are adding a competitor to.'),
                (new Operand('user', Operand::REQUIRED))->setValidation('is_string')->setDescription('The user you are adding, in a format Huntress can recognize.'),
                (new Operand('data', Operand::OPTIONAL))->setValidation('is_string')->setDescription('Data, if applicable'),
            ]);
            $commands[] = Command::create('vote', [self::class, 'voteMatch'])->setDescription('Vote for a match')->addOperands([
                (new Operand('match', Operand::REQUIRED))->setValidation('is_string')->setDescription('The match you are voting for.'),
                (new Operand('entry', Operand::REQUIRED))->setValidation('is_string')->setDescription('The entry you are voting for.'),
            ]);
            $commands[] = Command::create('announce', [self::class, 'announceMatch'])->setDescription('Announce a match')->addOperands([
                (new Operand('room', Operand::REQUIRED))->setValidation('is_string')->setDescription('Where you would like the match to be announced.'),
                (new Operand('match', Operand::REQUIRED))->setValidation('is_string')->setDescription('The match you are announcing.'),
            ])->addOptions([
                (new Option('u', 'no-anonymous', GetOpt::NO_ARGUMENT))->setDescription("Show user names instead of anonymizing options."),
                (new Option('c', 'cc', GetOpt::MULTIPLE_ARGUMENT))->setDescription("Add a user or group to be @-ed."),
                (new Option('p', 'pin', GetOpt::NO_ARGUMENT))->setDescription("Attempt to pin the announcement."),
                (new Option('t', 'timezone', GetOpt::OPTIONAL_ARGUMENT))->setDefaultValue("UTC")->setDescription("Set the announcement timezone. Default: UTC."),
                (new Option('s', 'spoiler', GetOpt::OPTIONAL_ARGUMENT))->setDescription("Add a spoiler warning for the match."),
                (new Option('n', 'note', GetOpt::OPTIONAL_ARGUMENT))->setDescription("Add an additional note to be displayed."),
            ]
            );
            $commands[] = Command::create('tally', [self::class, 'tallyMatch'])->setDescription('Get results for a match')->addOperands([
                (new Operand('match', Operand::REQUIRED))->setValidation('is_string')->setDescription('The match in question.'),
            ])->addOptions([
                (new Option(null, 'no-anonymous', GetOpt::NO_ARGUMENT))->setDescription("Show user names instead of anonymizing options."),
            ]
            );
            $getOpt->addCommands($commands);
            try {
                $args = substr(strstr($message->content, " "), 1);
                $getOpt->process((string) $args);
            } catch (ArgumentException $exception) {
                return self::send($message->channel, $getOpt->getHelpText());
            }
            $command = $getOpt->getCommand();
            if (is_null($command)) {
                return self::send($message->channel, $getOpt->getHelpText());
            }
            return call_user_func($command->getHandler(), $getOpt, $message);
        } catch (\Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }

    public static function createMatch(GetOpt $getOpt, Message $message)
    {
        try {
            if (!$message->member->permissions->has('MANAGE_ROLES') || !in_array($message->author->id, $message->client->config['evalUsers'])) { // todo: actual permission stuff
                return self::unauthorized($message);
            }
            $title  = (string) $getOpt->getOperand('title');
            $period = (string) $getOpt->getOperand('period');
            $time   = self::readTime($period);
            $id     = Snowflake::generate();
            $qb     = \Huntress\DatabaseFactory::get()->createQueryBuilder();
            $qb->insert("match_matches")->values([
                'idMatch' => '?',
                'created' => '?',
                'duedate' => '?',
                'title'   => '?',
            ])
            ->setParameter(0, $id, "integer")
            ->setParameter(1, Carbon::now(), "datetime")
            ->setParameter(2, $time, "datetime")
            ->setParameter(3, $title ?? "#$id", "text")
            ->execute();
            $line1  = sprintf("Match \"%s\" has been added with a deadline of %s.", $title, $time->diffForHumans(Carbon::now(), true, false, 2));
            $line2  = sprintf("Add competitors using `!match addcompetitor %s <user> [<data>]`.", Snowflake::format($id));
            return self::send($message->channel, $line1 . PHP_EOL . $line2);
        } catch (\Throwable $e) {
            self::exceptionHandler($message, $e);
        }
    }

    public static function addCompetitor(GetOpt $getOpt, Message $message)
    {
        try {
            if (!$message->member->permissions->has('MANAGE_ROLES') || !in_array($message->author->id, $message->client->config['evalUsers'])) { // todo: actual permission stuff
                return self::unauthorized($message);
            }
            $match = Snowflake::parse($getOpt->getOperand('match'));
            $user  = self::parseGuildUser($message->guild, $getOpt->getOperand('user'));
            $data  = $getOpt->getOperand('data');
            $id    = Snowflake::generate();

            if (!$user instanceof \CharlotteDunois\Yasmin\Models\GuildMember) {
                return self::send($message->channel, "Could not parse user. Try a username, @-mention, or their Tag#0000.");
            }

            $qb    = \Huntress\DatabaseFactory::get()->createQueryBuilder();
            $qb->insert("match_competitors")->values([
                'idCompetitor' => '?',
                'idMatch'      => '?',
                'idDiscord'    => '?',
                'created'      => '?',
                'data'         => '?',
            ])
            ->setParameter(0, $id, "integer")
            ->setParameter(1, $match, "integer")
            ->setParameter(2, $user->id, "integer")
            ->setParameter(3, Carbon::now(), "datetime")
            ->setParameter(4, $data ?? null, "text")
            ->execute();
            $line1 = sprintf("Competitor %s added with value `%s`", $user, $data ?? "<no data>");
            $line2 = sprintf("Add more competitors or announce with `!match announce <room> %s`.", Snowflake::format($match));
            return self::send($message->channel, $line1 . PHP_EOL . $line2);
        } catch (\Throwable $e) {
            self::exceptionHandler($message, $e);
        }
    }

    public static function voteMatch(GetOpt $getOpt, Message $message)
    {
        try {
            $match = Snowflake::parse($getOpt->getOperand('match'));
            $entry = Snowflake::parse($getOpt->getOperand('entry'));

            $info = self::getMatchInfo($match, $message->guild);

            if ($info->duedate < Carbon::now()) {
                return self::send($message->channel, "I'm sorry, voting has expired for that match. Please try again later.");
            }

            $query = \Huntress\DatabaseFactory::get()->prepare('REPLACE INTO match_votes (`idCompetitor`, `idMatch`, `idVoter`, `created`) VALUES(?, ?, ?, ?)', ['integer', 'integer', 'integer', 'datetime']);
            $query->bindValue(1, $entry);
            $query->bindValue(2, $match);
            $query->bindValue(3, $message->author->id);
            $query->bindValue(4, Carbon::now());
            $query->execute();

            $line1 = sprintf("%s, your vote for match ID `%s` has been recorded!", $message->member->displayName, Snowflake::format($match));
            return self::send($message->channel, $line1)->then(function ($donemsg) use ($message) {
                return $message->delete();
            });
        } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException $e) {
            self::send($message->channel, sprintf("%s, that competitor is invalid!", $message->member->displayName));
            return $message->delete();
        } catch (\Throwable $e) {
            self::send($message->channel, sprintf("%s, either that match doesn't exist or something has gone wrong!", $message->member->displayName));
            return $message->delete();
        }
    }

    public static function announceMatch(GetOpt $getOpt, Message $message)
    {
        try {
            $anon = !((bool) $getOpt->getOption("no-anonymous"));
            if (!in_array($message->author->id, $message->client->config['evalUsers'])) { // todo: actual permission stuff
                return self::unauthorized($message);
            }
            $match = Snowflake::parse($getOpt->getOperand('match'));
            $room  = $getOpt->getOperand('room');
            $info  = self::getMatchInfo($match, $message->guild);

            $info->duedate->setTimezone($getOpt->getOption("timezone"));

            if (preg_match("/<#(\\d+)>/", $room, $matches)) {
                $channel = $message->client->channels->get($matches[1]);
                if (!$channel instanceof \CharlotteDunois\Yasmin\Interfaces\TextChannelInterface) {
                    return self::send($message->channel, "$room is not a valid text channel in this guild.");
                }
            } else {
                return self::send($message->channel, "That's not a valid channel name!");
            }

            $embed   = new \CharlotteDunois\Yasmin\Models\MessageEmbed();
            $embed->setTitle($info->title);
            $embed->setTimestamp($info->duedate->timestamp);
            $embed->setDescription(sprintf("Voting is open until *%s* [[other timezones](https://syl.ae/time/#%s)]", $info->duedate->toCookieString(), $info->duedate->timestamp));
            $counter = 1;

            if (!is_null($getOpt->getOption("note"))) {
                $embed->addField("Note", $getOpt->getOption("note"));
            }
            if (!is_null($getOpt->getOption("spoiler"))) {
                $embed->addField("SPOILER ALERT", $getOpt->getOption("spoiler"));
            }
            $info->entries->each(function ($v, $k) use ($info, $anon, $embed, &$counter) {
                $data = sprintf("%s\nVote with `!match vote %s %s`", $v->data, Snowflake::format($info->idMatch), Snowflake::format($v->id));
                if ($anon) {
                    $embed->addField(sprintf("Option %s", $counter), $data);
                } else {
                    $embed->addField(sprintf("%s", $v->user->displayName), $data);
                }
                $counter++;
            });
            $prom = self::send($channel, "A match is available for voting!\n" . implode(", ", $getOpt->getOption("cc")), ['embed' => $embed]);
            if ((bool) $getOpt->getOption("pin")) {
                $prom->then(function ($announcement) use ($message) {
                    $message->channel->send("Announcement sent!");
                    return $announcement->pin();
                }, function ($error) use ($message) {
                    self::dump($message->channel, $error);
                });
            }
        } catch (\Throwable $e) {
            self::exceptionHandler($message, $e);
        }
    }

    public static function tallyMatch(GetOpt $getOpt, Message $message)
    {
        try {
            $anon = !((bool) $getOpt->getOption("no-anonymous"));
            if (!in_array($message->author->id, $message->client->config['evalUsers'])) { // todo: actual permission stuff
                return self::unauthorized($message);
            }
            $match = Snowflake::parse($getOpt->getOperand('match'));
            $info  = self::getMatchInfo($match, $message->guild);

            $r   = [];
            $r[] = "__**Match {$info->title}**__ `" . Snowflake::format($info->idMatch) . "`";
            $r[] = "Deadline: *" . $info->duedate->diffForHumans(Carbon::now(), true, false, 2) . "*";
            $r[] = "";
            $info->entries->each(function ($v, $k) use (&$r, $anon) {
                if ($anon) {
                    $r[] = sprintf("Competitor ID %s - Data `%s`", Snowflake::format($v->id), $v->data ?? "<null>");
                } else {
                    $r[] = sprintf("Competitor %s (ID %s) - Data `%s`", $v->user->displayName, Snowflake::format($v->id), $v->data ?? "<null>");
                }
                $vcount = $v->votes->count();
                $vplode = $v->votes->implode("displayName", ", ");
                $r[]    = sprintf("%s votes - %s", $vcount, $vplode);
                $r[]    = "";
            });
            return self::send($message->channel, implode(PHP_EOL, $r), ['split' => true]);
        } catch (\Throwable $e) {
            self::exceptionHandler($message, $e);
        }
    }

    private static function getMatchInfo(int $idMatch, \CharlotteDunois\Yasmin\Models\Guild $guild): \stdClass
    {
        $db    = \Huntress\DatabaseFactory::get();
        $match = $db->createQueryBuilder()->select("*")->from("match_matches")->where('idMatch = ?')->setParameter(0, $idMatch)->execute()->fetchAll();

        if (count($match) != 1) {
            throw new \Exception("Either that match doesn't exist, or something that gone apocalyptically wrong.");
        } else {
            $match            = $match[0];
            $match['created'] = new Carbon($match['created']);
            $match['duedate'] = new Carbon($match['duedate']);
            $match            = (object) $match;
        }

        $match->entries = (new Collection($db->createQueryBuilder()->select("*")->from("match_competitors")->where('idMatch = ?')->setParameter(0, $idMatch)->execute()->fetchAll()))->map(function ($v, $k) use ($guild) {
            $entry          = new \stdClass();
            $entry->id      = $v['idCompetitor'];
            $entry->user    = $guild->members->get($v['idDiscord']);
            $entry->created = new Carbon($v['created']);
            $entry->data    = $v['data'];
            $entry->votes   = new Collection();
            return $entry;
        })->groupBy("id")->map(function ($v, $k) {
            return $v[0];
        });
        $votes = (new Collection($db->createQueryBuilder()->select("*")->from("match_votes")->where('idMatch = ?')->setParameter(0, $idMatch)->execute()->fetchAll()));
        $votes->each(function ($v, $k) use ($match, $guild) {
            if ($guild->members->has($v['idVoter'])) {
                $vote              = new \stdClass();
                $vote->user        = $guild->members->get($v['idVoter']);
                $vote->displayName = $vote->user->displayName;
                $vote->created     = new Carbon($v['created']);
                $match->entries->get($v['idCompetitor'])->votes->set($v['idVoter'], $vote);
            }
        });

        return $match;
    }

    public static function tallyHandler(Huntress $bot, Message $message): ?Promise
    {
        try {
            $getOpt = new GetOpt();
            $getOpt->set(GetOpt::SETTING_SCRIPT_NAME, '!tally');
            $getOpt->set(GetOpt::SETTING_STRICT_OPERANDS, true);
            $getOpt->addOperands([
                (new Operand('message', Operand::REQUIRED))->setValidation('is_numeric')->setDescription('The message ID to count votes on.'),
                (new Operand('channel', Operand::OPTIONAL))->setValidation('is_string')->setDefaultValue((string) $message->channel)->setDescription('The channel the message is in, in #mention format. Default: current channel.'),
            ]);
            try {
                $args = substr(strstr($message->content, " "), 1);
                $getOpt->process((string) $args);
            } catch (ArgumentException $exception) {
                return self::send($message->channel, $getOpt->getHelpText());
            }

            return \CharlotteDunois\Yasmin\Utils\MessageHelpers::parseMentions($bot, $getOpt->getOperand('channel'))->then(function (array $res) use ($message, $getOpt) {
                try {
                    if (count($res) != 1 || !$res[0]['ref'] instanceof \CharlotteDunois\Yasmin\Models\TextChannel) {
                        return self::error($message, "Could not parse channel!", "#mention one channel, this channel must be accessible by Huntress.");
                    }
                    return $res[0]['ref']->fetchMessage($getOpt->getOperand('message'))->then(function ($quest) use ($message) {
                        return \React\Promise\all($quest->reactions->map(function (\CharlotteDunois\Yasmin\Models\MessageReaction $mr) {
                            return $mr->fetchUsers();
                        })->all())->then(function (array $reactUsers) use ($message, $quest) {
                            $reactions = [];
                            $seenUsers = [];
                            $cheaters  = [];

                            /** @var \CharlotteDunois\Collect\Collection  $users */
                            foreach ($reactUsers as $reactionID => $users) {
                                /** @var \CharlotteDunois\Yasmin\Models\User  $user */
                                foreach ($users as $user) {
                                    if (isset($seenUsers[$user->id])) {
                                        if (isset($cheaters[$user->id])) {
                                            continue;
                                        } else {
                                            $reactions[$seenUsers[$user->id]]--;
                                            $cheaters[$user->id] = true;
                                        }
                                    } else {
                                        if (!isset($reactions[$reactionID])) {
                                            $reactions[$reactionID] = 0;
                                        }

                                        $reactions[$reactionID]++;
                                        $seenUsers[$user->id] = $reactionID;
                                    }
                                }
                            }
                            $msg = $quest->reactions->map(function (\CharlotteDunois\Yasmin\Models\MessageReaction $mr) use ($reactions) {
                                return $mr->emoji->name . ' ' . ($reactions[$mr->emoji->name] ?? 0);
                            })->implode(null, PHP_EOL);

                            return $message->channel->send($msg);
                        });
                    });
                } catch (\Throwable $e) {
                    self::exceptionHandler($message, $e);
                }
            });
        } catch (\Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }
}
