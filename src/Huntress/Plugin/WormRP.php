<?php

/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use Carbon\Carbon;
use CharlotteDunois\Collect\Collection;
use CharlotteDunois\Yasmin\Models\Guild;
use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\Message;
use CharlotteDunois\Yasmin\Models\MessageEmbed;
use CharlotteDunois\Yasmin\Utils\URLHelpers;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Huntress\DatabaseFactory;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Huntress\QueueItem;
use Huntress\RSSProcessor;
use React\ChildProcess\Process;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Throwable;
use function React\Promise\all;

/**
 * Simple builtin to show user information
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class WormRP implements PluginInterface
{
    use PluginHelperTrait;

    // const APPROVAL_SHEET = '1kXME7JHB5VVa5pMBLy1qxlBBef7dkcs2JlXf3z97xpQ'; // test
    const APPROVAL_SHEET = '1Crv5q-J_oZ_rGJebqJw6xIFeT3Gdil31dqqSEZL_mBc'; // prod

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(
            EventListener::new()->addEvent("dbSchema")->setCallback([
                self::class,
                'db',
            ])
        );
        if (self::isTestingClient()) {
            $bot->log->debug("Not adding comments/active checking on testing.");
        } else {
            $bot->eventManager->addURLEvent(
                "https://www.reddit.com/r/wormrp/comments.json",
                30,
                [self::class, "pollComments"]
            );
            $bot->eventManager->addEventListener(
                EventListener::new()->setCallback([
                    self::class,
                    "pollActiveCheck",
                ])->setPeriodic(10)
            );

            $bot->eventManager->addURLEvent(
                "https://wormrp.com/api/roles",
                60,
                [self::class, "wikiRoleHandler"]
            );

            $wiki = new RSSProcessor(
                $bot, 'WikiRecentChanges',
                'https://wiki.wormrp.com/w/api.php?urlversion=2&action=feedrecentchanges&feedformat=rss&hideminor=true',
                60,
                [504159510965911563]
            );
            $wiki->showBody = false;
        }

        $bot->eventManager->addEventListener(
            EventListener::new()->setCallback([
                self::class,
                "pollStaffDump",
            ])->setPeriodic(5)
        );

        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addCommand("linkAccount")
                ->addCommand("linkaccount")
                ->addGuild(118981144464195584)
                ->setCallback([self::class, "accountLinkHandler"])
        );
        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addCommand("character")
                ->addGuild(118981144464195584)
                ->setCallback([self::class, "lookupHandler"])
        );
        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addCommand("queue")
                ->addGuild(118981144464195584)
                ->setCallback([self::class, "queueHandler"])
        );
        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addCommand("updateWiki")
                ->addCommand("updatewiki")
                ->addGuild(118981144464195584)
                ->setCallback([self::class, "updateWiki"])
        );

        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addEvent("agendaPluginConf")
                ->setCallback(function (Collection $c) {
                    $c->set(118981144464195584, [
                        'staffRole' => 456321111945248779,
                        'tiebreakerRole' => 492912331857199115,
                        'quorum' => (2 / 3),
                        'voteTypes' => [
                            "For" => 394653535863570442,
                            "Against" => 394653616050405376,
                            "Abstain" => "👀",
                            "Absent" => null,
                        ],
                    ]);
                })
        );

        $bot->eventManager->addEventListener(
            EventListener::new()->setCallback([self::class, "newMemberHandler"])
                ->addEvent("guildMemberAdd")->addGuild(118981144464195584)
        );
    }


    public static function newMemberHandler(EventData $data): PromiseInterface
    {
        $ch = $data->guild->channels->get(118981144464195584);
        $gm = $data->user;

        $message = "%s, **welcome to WormRP!**\n" .
            "First please take a look through our rules, found in #welcome\n" .
            "you can find some other helpful resources there as well.\n\n" .
            "Feel free to head on down to <#118983851975376898>  or <#955640767907520553>\n" .
            "There you'll find some helpful resources on creating a character in the pinned messages.\n" .
            "If you have questions about the setting, <#256831745272446978> is likewise helpful in that regard.\n" .
            "If you have any additional questions, feel free to ask in <#118981144464195584> or <#118981977935183873>\n" .
            "Apologies for any delays, time zones & busy lives mean sometimes things take time";

        return $ch->send(sprintf($message, $gm));
    }

    public static function updateWiki(EventData $data): PromiseInterface
    {
        return $data->message->reply("🔮")->then(function (Message $response) use ($data) {
            try {
                $cmd = trim("/home/keira/bots/wormrpWikiUpdater/refresh");
                $data->huntress->log->debug("Running $cmd");
                if (php_uname('s') == "Windows NT") {
                    $null = 'nul';
                    return null;
                } else {
                    $null = '/dev/null';
                }
                $process = new Process($cmd, null, null, [
                    ['file', $null, 'r'],
                    $stdout = tmpfile(),
                    $stderr = tmpfile(),
                ]);
                $process->start($data->huntress->getLoop());
                $prom = new Deferred();

                $process->on('exit', function (int $exitcode) use ($stdout, $stderr, $prom, $data) {
                    $data->huntress->log->debug("wikiUpdater child exited with code $exitcode.");

                    // todo: use FileHelper filesystem nonsense for this.
                    rewind($stdout);
                    $childData = stream_get_contents($stdout);
                    fclose($stdout);
                    rewind($stderr);
                    $childData .= stream_get_contents($stderr);
                    fclose($stderr);

                    if ($exitcode == 0) {
                        $data->huntress->log->debug("flairHandler child success!");
                        $prom->resolve($childData);
                    } else {
                        $prom->reject($childData);
                        $data->huntress->log->debug("flairHandler child failure!");
                    }
                });
                return $prom->promise()->then(function ($childData) use ($data, $response) {
                    return $response->edit("```\n$childData\n```");
                }, function ($e) use ($data) {
                    return self::error($data->message, "Updating wiki failed!", "```\n$e\n```");
                });
            } catch (Throwable $e) {
                return self::exceptionHandler($data->message, $e);
            }
        });
    }

    public static function fetchAccount(
        Guild $guild,
        string $redditName
    ): ?GuildMember {
        $qb = DatabaseFactory::get()->createQueryBuilder();
        $qb->select("*")->from("wormrp_users")->where('`redditName` = ?')->setParameter(0, $redditName, "string");
        $res = $qb->execute()->fetchAll();
        foreach ($res as $data) {
            return $guild->members->get($data['user']) ?? null;
        }
        return null;
    }

    public static function db(Schema $schema): void
    {
        $t = $schema->createTable("wormrp_config");
        $t->addColumn("key", "string", ['customSchemaOptions' => DatabaseFactory::CHARSET]);
        $t->addColumn("value", "text", ['customSchemaOptions' => DatabaseFactory::CHARSET]);
        $t->setPrimaryKey(["key"]);

        $t2 = $schema->createTable("wormrp_users");
        $t2->addColumn("user", "bigint", ["unsigned" => true]);
        $t2->addColumn("redditName", "string", ['customSchemaOptions' => DatabaseFactory::CHARSET]);
        $t2->setPrimaryKey(["user"]);
        $t2->addIndex(["redditName"]);

        $t3 = $schema->createTable("wormrp_activity");
        $t3->addColumn("redditName", "string", ['customSchemaOptions' => DatabaseFactory::CHARSET]);
        $t3->addColumn("lastSubActivity", "datetime");
        $t3->addColumn(
            "flair",
            "string",
            ['customSchemaOptions' => DatabaseFactory::CHARSET, 'notnull' => false]
        );
        $t3->setPrimaryKey(["redditName"]);

        $t4 = $schema->createTable("wormrp_staff");
        $t4->addColumn("idUser", "bigint", ["unsigned" => true]);
        $t4->addColumn("staffRole", "bigint", ["unsigned" => true, 'notnull' => false]);
        $t4->setPrimaryKey(["idUser"]);
    }

    public static function wikiRoleHandler(string $string, Huntress $bot)
    {
        $x = json_decode($string, true);
        $autoRoles = $x['roles'];
        $data = $x['data'];

        $guild = $bot->guilds->get(118981144464195584);
        $modlog = $guild->channels->get(491099441357651969);

        $x = [];
        foreach ($data as $id => $roles) {
            if (!$guild->members->has($id)) {
                continue;
            }
            $member = $guild->members->get($id);
            foreach ($autoRoles as $tag => $roleID) {
                $has = $member->roles->has($roleID);
                $should = in_array($tag, $roles);

                if ($has && !$should) {
                    $x[] = $member->removeRole($roleID, "Huntress role management")->then(function () use (
                        $modlog,
                        $tag,
                        $member
                    ) {
                        return $modlog->send("[HRM Wiki] Removed the `$tag` role from $member");
                    });
                }
                if (!$has && $should) {
                    $x[] = $member->addRole($roleID, "Huntress role management")->then(function () use (
                        $modlog,
                        $tag,
                        $member
                    ) {
                        return $modlog->send("[HRM Wiki] Added the `$tag` role to $member");
                    });
                }
            }
        }
        return all($x);
    }

    public static function pollComments(string $string, Huntress $bot)
    {
        try {
            $items = json_decode($string)->data->children ?? null;
            if (!is_countable($items)) {
                return;
            }
            $users = [];
            foreach ($items as $item) {
                $published = $item->data->created_utc;
                $users[$item->data->author] = [
                    max($published, $users[$item->data->author][0] ?? 0),
                    $item->data->author_flair_text ?? null,
                ];
            }
            $query = $bot->db->prepare(
                'INSERT INTO wormrp_activity (`redditName`, `lastSubActivity`, `flair`) VALUES(?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE `lastSubActivity`=GREATEST(`lastSubActivity`, VALUES(`lastSubActivity`)), `flair`=VALUES(`flair`);',
                ['string', 'datetime', 'string']
            );
            foreach ($users as $name => $data) {
                $query->bindValue(1, $name);
                $query->bindValue(2, Carbon::createFromTimestamp($data[0]));
                $query->bindValue(3, $data[1]);
                $query->execute();
            }
        } catch (Throwable $e) {
            $bot->log->warning($e->getMessage(), ['exception' => $e]);
        }
    }

    public static function pollActiveCheck(Huntress $bot)
    {
        if (self::isTestingClient()) {
            $bot->log->debug("Not firing " . __METHOD__);
            return;
        }
        try {
            $redd = [];
            $cutoff = Carbon::now()->addDays(-14);
            $query = DatabaseFactory::get()->query(
                'SELECT * from wormrp_activity right join wormrp_users on wormrp_users.redditName = wormrp_activity.redditName where wormrp_users.user is not null'
            );
            foreach ($query->fetchAll() as $redditor) {
                $redd[$redditor['user']] = ((new Carbon($redditor['lastSubActivity'] ?? "1990-01-01")) >= $cutoff);
            }

            $curr_actives = $bot->guilds->get(118981144464195584)->members->filter(function ($v, $k) {
                return $v->roles->has(492933723340144640);
            });

            foreach ($curr_actives as $member) {
                if (!array_key_exists($member->id, $redd)) {
                    $member->removeRole(
                        492933723340144640,
                        "Active Users role requires a linked reddit account"
                    )->then(function ($member) {
                        $member->guild->channels->get(491099441357651969)->send(
                            "Removed <@{$member->id}> from Active Users due to account linkage. " .
                            "Please perform `!linkAccount [redditName] {$member->user->tag}`"
                        );
                    });
                } elseif ($redd[$member->id]) {
                    unset($redd[$member->id]);
                } else {
                    $member->removeRole(492933723340144640, "User fell out of Active status (14 days)")->then(function (
                        $member
                    ) {
                        $member->guild->channels->get(491099441357651969)->send(
                            "Removed <@{$member->id}> from Active Users due to inactivity."
                        );
                    });
                    unset($redd[$member->id]);
                }
            }
            foreach ($redd as $id => $val) {
                if ($val) {
                    $member = $bot->guilds->get(118981144464195584)->members->get($id);
                    if (!is_null($member)) {
                        $member->addRole(492933723340144640, "User is now active on reddit")->then(function ($member) {
                            $member->guild->channels->get(491099441357651969)->send(
                                "Added <@{$member->id}> to Active Users."
                            );
                        });
                    }
                }
            }
        } catch (Throwable $e) {
            $bot->log->warning($e->getMessage(), ['exception' => $e]);
        }
    }

    public static function pollStaffDump(Huntress $bot)
    {
        try {
            $query = $bot->db->prepare("replace into wormrp_staff (`idUser`, `staffRole`) values (?, ?);");
            $bot->guilds->get(118981144464195584)->members->filter(function (GuildMember $v) {
                return $v->roles->has(456321111945248779) || $v->roles->has(965849665411121192);
            })->each(function (GuildMember $v) use ($query) {
                $query->bindValue(1, $v->id, ParameterType::INTEGER);
                if ($v->roles->has(456321111945248779)) {
                    $query->bindValue(2, 456321111945248779, ParameterType::INTEGER);
                } elseif ($v->roles->has(965849665411121192)) {
                    $query->bindValue(2, 965849665411121192, ParameterType::INTEGER);
                } else {
                    // should not do anything but lets be safe
                    return null;
                }
                $query->executeQuery();
            });
            // todo: yeet old staff
        } catch (Throwable $e) {
            $bot->log->warning($e->getMessage(), ['exception' => $e]);
        }
    }

    public static function accountLinkHandler(EventData $data): ?PromiseInterface
    {
        if (!$data->message->member->roles->has(456321111945248779)) {
            return self::unauthorized($data->message);
        } else {
            try {
                $args = self::_split($data->message->content);
                if (count($args) < 3) {
                    return self::error(
                        $data->message,
                        "You dipshit :open_mouth:",
                        "!linkAccount redditName discordName"
                    );
                }
                $user = self::parseGuildUser($data->message->guild, $args[2]);

                if (is_null($user)) {
                    return self::error($data->message, "Error", "I don't know who the hell {$args[2]} is :(");
                }

                $query = DatabaseFactory::get()->prepare(
                    'INSERT INTO wormrp_users (`user`, `redditName`) VALUES(?, ?) '
                    . 'ON DUPLICATE KEY UPDATE `redditName`=VALUES(`redditName`);',
                    ['integer', 'string']
                );
                $query->bindValue(1, $user->id);
                $query->bindValue(2, $args[1]);
                $query->execute();

                return $data->message->reply(
                    "Added/updated {$user->user->tag} ({$user->id}) to tracker with reddit username /u/{$args[1]}."
                );
            } catch (Throwable $e) {
                return self::exceptionHandler($data->message, $e, true);
            }
        }
    }

    public static function lookupHandler(EventData $data): ?PromiseInterface
    {
        try {
            $args = self::_split($data->message->content);
            if (count($args) < 2) {
                return self::error($data->message, "Error", "usage: `!character Character Name`");
            }
            $char = trim(str_replace($args[0], "", $data->message->content)); // todo: do this better

            return all([
                'wiki' => URLHelpers::resolveURLToData(
                    "https://wiki.wormrp.com/w/api.php?action=ask&format=json&api_version=3&query=[[Identity::like:*" . urlencode(
                        $char
                    ) . "*]]|?Identity|?Author|?Alignment|?Affiliation|?Status|?Meta%20element%20og-image|limit=5"
                ),
                'reddit' => URLHelpers::resolveURLToData(
                    "https://www.reddit.com/r/wormrp/search.json?q=flair%3ACharacter+" . urlencode(
                        $char
                    ) . "&sort=relevance&restrict_sr=on&t=all&include_over_18=on"
                ),
            ])->then(function ($results) use ($char, $data) {
                $wiki = self::lookupWiki($results['wiki'], $char);
                if (count($wiki) > 0) {
                    return all(
                        array_map(function ($embed) use ($data) {
                            return $data->message->reply("", ['embed' => $embed]);
                        }, $wiki)
                    );
                }

                $reddit = self::lookupReddit($results['reddit'], $char);
                if (!is_null($reddit)) {
                    return $data->message->reply($reddit);
                }

                return $data->message->reply("I didn't find anything on the wiki or reddit :sob:");
            });
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true);
        }
    }

    private static function lookupWiki(string $string, string $char): array
    {
        $items = json_decode($string)->query->results;
        $res = [];
        foreach ($items as $item) {
            key($item);
            $item = current($item);
            $embed = new MessageEmbed();
            $embed->setTitle($item->fulltext);
            $embed->setURL($item->fullurl);
            $fields = [
                "Known as" => "Identity",
                "Player" => "Author",
                "Status" => "Status",
                "Alignment" => "Alignment",
                "Affiliation" => "Affiliation",
            ];
            foreach ($fields as $label => $pval) {
                $x = array_map(function ($v) {
                    if (is_object($v)) {
                        if (property_exists($v, "fulltext")) {
                            return property_exists($v, "fullurl") ? sprintf(
                                "[%s](%s)",
                                $v->fulltext,
                                $v->fullurl
                            ) : $v->fulltext;
                        } else {
                            return $v->fulltext ?? null;
                        }
                    } else {
                        return $v;
                    }
                }, $item->printouts->{$pval});
                if (count($x) > 0) {
                    $val = implode(", ", $x);
                    if (mb_strlen(trim($val)) > 0) {
                        $inline = ($pval != "Identity"); // this one in particular not inline
                        $embed->addField($label, $val, $inline);
                    }
                }
            }
            if (count($item->printouts->{'Meta element og-image'}) > 0) {
                $embed->setThumbnail($item->printouts->{'Meta element og-image'}[0]);
            }
            $res[] = $embed;
        }
        return $res;
    }

    private static function lookupReddit(string $string, string $char): ?string
    {
        $items = json_decode($string)->data->children;
        foreach ($items as $item) {
            return "I didn't find anything on the WormRP wiki, but reddit gave me this: https://www.reddit.com" . $item->data->permalink .
                "\n*If this is your character, please port them over to the wiki when you have time with this link: <https://wormrp.com/reports/wikiwizard?name=" .
                rawurlencode($char) . ">*";
        }
        return null;
    }

    public static function queueHandler(EventData $data): ?PromiseInterface
    {
        try {
            $queue = QueueItem::getQueue($data->huntress->db);

            $embed = new MessageEmbed();
            $embed->setTitle("WormRP Approval Queue");
            $embed->setURL("https://wormrp.com/reports/queue");
            $embed->setTimestamp(time());

            $queue->filter(function (QueueItem $v) {
                return $v->getState() != QueueItem::STATE_APPROVED;
            })->each(function (QueueItem $v) use ($embed) {
                $title = sprintf(
                    "%s (%s day%s, %s)",
                    $v->title,
                    $v->postTime->diffInDays(),
                    $v->postTime->diffInDays() == 1 ? "" : "s",
                    $v->getStateClass()
                );
                $lines = [];

                $approvers = [];
                if (!is_null($v->idApprover1)) {
                    $approvers[] = sprintf("<@%s>", $v->idApprover1);
                }
                if (!is_null($v->idApprover2)) {
                    $approvers[] = sprintf("<@%s>", $v->idApprover2);
                }
                $astr = match (count($approvers)) {
                    0 => "Awaiting approver",
                    1 => "Approver: " . implode(", ", $approvers),
                    default => "Approvers: " . implode(", ", $approvers),
                };
                $lines[] = sprintf("[[Link](%s)] %s by %s (%s)", $v->url, $v->flair, $v->author, $astr);

                $embed->addField($title, implode(PHP_EOL, $lines));
            });

            return $data->message->reply("", ['embed' => $embed]);
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true);
        }
    }
}
