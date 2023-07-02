<?php

/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Collect\Collection;
use CharlotteDunois\Yasmin\Models\Guild;
use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\MessageEmbed;
use CharlotteDunois\Yasmin\Utils\URLHelpers;
use Doctrine\DBAL\Schema\Schema;
use Huntress\DatabaseFactory;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Huntress\QueueItem;
use Huntress\RSSProcessor;
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

        $t4 = $schema->createTable("wormrp_staff");
        $t4->addColumn("idUser", "bigint", ["unsigned" => true]);
        $t4->addColumn("staffRole", "bigint", ["unsigned" => true, 'notnull' => false]);
        $t4->setPrimaryKey(["idUser"]);
    }

    public static function wikiRoleHandler(string $string, Huntress $bot)
    {
        return; // disabled for now
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
            ])->then(function ($results) use ($char, $data) {
                $wiki = self::lookupWiki($results['wiki'], $char);
                if (count($wiki) > 0) {
                    return all(
                        array_map(function ($embed) use ($data) {
                            return $data->message->reply("", ['embed' => $embed]);
                        }, $wiki)
                    );
                }

                return $data->message->reply("I didn't find anything on the wiki :sob:");
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
