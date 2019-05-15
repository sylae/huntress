<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
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
use Doctrine\DBAL\Schema\Schema;
use Huntress\DatabaseFactory;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Huntress\RedditProcessor;
use function React\Promise\all;
use React\Promise\ExtendedPromiseInterface;
use function Sentry\captureException;
use Throwable;

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
        $bot->eventManager->addEventListener(EventListener::new()->addEvent("dbSchema")->setCallback([
            self::class,
            'db',
        ]));
        if (self::isTestingClient()) {
            $bot->log->debug("Not adding RSS event on testing.");
        } else {
            self::setupEventAnnouncer($bot); // shuffling off there since it has an anonymous class and is a bit of a mess.
            $bot->eventManager->addURLEvent("https://www.reddit.com/r/wormrp/comments.json", 30,
                [self::class, "pollComments"]);
            $bot->eventManager->addEventListener(EventListener::new()->setCallback([
                self::class,
                "pollActiveCheck",
            ])->setPeriodic(10));
        }
        $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "linkAccount", [self::class, "accountLinkHandler"]);

        $eh = EventListener::new()
            ->addCommand("character")
            ->addGuild(118981144464195584)
            ->setCallback([self::class, "lookupHandler"]);
        $bot->eventManager->addEventListener($eh);
    }

    public static function setupEventAnnouncer(Huntress $bot)
    {
        new class ($bot, "wormrpPosts", "wormrp", 30, 118981144464195584) extends RedditProcessor
        {

            protected function dataProcessingCallback(string $string): Collection
            {
                try {
                    $items = json_decode($string)->data->children;
                    if (!is_countable($items)) {
                        return new Collection([]);
                    }
                    $lastPub = $this->getLastRSS();
                    $newest = $lastPub;
                    $newItems = [];
                    foreach ($items as $item) {
                        $published = Carbon::createFromTimestamp($item->data->created_utc);
                        if ($published <= $lastPub || is_null($item->data->link_flair_text)) {
                            continue;
                        }
                        $newest = max($newest, $published);
                        $newItems[] = (object) [
                            'title' => $item->data->title,
                            'link' => "https://www.reddit.com" . $item->data->permalink,
                            'date' => $published,
                            'category' => $item->data->link_flair_text ?? "Unflaired",
                            'body' => (strlen($item->data->selftext) > 0) ? $item->data->selftext : $item->data->url,
                            'author' => $item->data->author,
                        ];
                    }
                    return new Collection($newItems);
                } catch (Throwable $e) {
                    captureException($e);
                    $this->huntress->log->addWarning($e->getMessage(), ['exception' => $e]);
                    return new Collection();
                }
            }

            protected function dataPublishingCallback(object $item): bool
            {
                try {
                    if (mb_strlen($item->body) > 500) {
                        $item->body = substr($item->body, 0, 500) . "...";
                    }
                    if (mb_strlen($item->title) > 250) {
                        $item->body = substr($item->title, 0, 250) . "...";
                    }

                    switch ($item->category) {
                        case "Character":
                        case "Equipment":
                        case "Lore":
                        case "Group":
                            $channel = 386943351062265888; // the_list
                            break;
                        case "Meta":
                            $channel = 118981144464195584; // general
                            break;
                        case "Event":
                        case "Patrol":
                        case "Non-Canon":
                        default:
                            $channel = 409043591881687041; // events
                    }

                    $embed = new MessageEmbed();
                    $embed->setTitle($item->title)->setURL($item->link)->setDescription($item->body)->setFooter($item->category)
                        ->setTimestamp($item->date->timestamp);

                    $redditUser = WormRP::fetchAccount($this->huntress->channels->get($channel)->guild, $item->author);
                    if ($redditUser instanceof GuildMember) {
                        $embed->setAuthor($redditUser->displayName, $redditUser->user->getDisplayAvatarURL(),
                            "https://reddit.com/user/" . $item->author);
                    } else {
                        $embed->setAuthor($item->author, '', "https://reddit.com/user/" . $item->author);
                    }

                    $this->huntress->channels->get($channel)->send("", ['embed' => $embed]);
                } catch (Throwable $e) {
                    captureException($e);
                    $this->huntress->log->addWarning($e->getMessage(), ['exception' => $e]);
                    return false;
                }
                return true;
            }
        };
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
        $t3->addColumn("flair", "string",
            ['customSchemaOptions' => DatabaseFactory::CHARSET, 'notnull' => false]);
        $t3->setPrimaryKey(["redditName"]);
    }

    public static function pollComments(string $string, Huntress $bot)
    {
        try {
            $items = json_decode($string)->data->children;
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
            $query = $bot->db->prepare('INSERT INTO wormrp_activity (`redditName`, `lastSubActivity`, `flair`) VALUES(?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE `lastSubActivity`=VALUES(`lastSubActivity`), `flair`=VALUES(`flair`);',
                ['string', 'datetime', 'string']);
            foreach ($users as $name => $data) {
                $query->bindValue(1, $name);
                $query->bindValue(2, Carbon::createFromTimestamp($data[0]));
                $query->bindValue(3, $data[1]);
                $query->execute();
            }
        } catch (Throwable $e) {
            captureException($e);
            $bot->log->addWarning($e->getMessage(), ['exception' => $e]);
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
            $query = DatabaseFactory::get()->query('SELECT * from wormrp_activity right join wormrp_users on wormrp_users.redditName = wormrp_activity.redditName where wormrp_users.user is not null');
            foreach ($query->fetchAll() as $redditor) {
                $redd[$redditor['user']] = ((new Carbon($redditor['lastSubActivity'] ?? "1990-01-01")) >= $cutoff);
            }

            $curr_actives = $bot->guilds->get(118981144464195584)->members->filter(function ($v, $k) {
                return $v->roles->has(492933723340144640);
            });

            foreach ($curr_actives as $member) {
                if (!array_key_exists($member->id, $redd)) {
                    $member->removeRole(492933723340144640,
                        "Active Users role requires a linked reddit account")->then(function ($member) {
                        $member->guild->channels->get(491099441357651969)->send("Removed <@{$member->id}> from Active Users due to account linkage. " .
                            "Please perform `!linkAccount [redditName] {$member->user->tag}`");
                    });
                } elseif ($redd[$member->id]) {
                    unset($redd[$member->id]);
                } else {
                    $member->removeRole(492933723340144640, "User fell out of Active status (14 days)")->then(function (
                        $member
                    ) {
                        $member->guild->channels->get(491099441357651969)->send("Removed <@{$member->id}> from Active Users due to inactivity.");
                    });
                    unset($redd[$member->id]);
                }
            }
            foreach ($redd as $id => $val) {
                if ($val) {
                    $member = $bot->guilds->get(118981144464195584)->members->get($id);
                    if (!is_null($member)) {
                        $member->addRole(492933723340144640, "User is now active on reddit")->then(function ($member) {
                            $member->guild->channels->get(491099441357651969)->send("Added <@{$member->id}> to Active Users.");
                        });
                    }
                }
            }
        } catch (Throwable $e) {
            captureException($e);
            $bot->log->addWarning($e->getMessage(), ['exception' => $e]);
        }
    }

    public static function accountLinkHandler(
        Huntress $bot,
        Message $message
    ): ?ExtendedPromiseInterface {
        if ($message->guild->id != 118981144464195584) {
            return null;
        }
        if (!$message->member->roles->has(456321111945248779)) {
            return self::unauthorized($message);
        } else {
            try {
                $args = self::_split($message->content);
                if (count($args) < 3) {
                    return self::error($message, "You dipshit :open_mouth:", "!linkAccount redditName discordName");
                }
                $user = self::parseGuildUser($message->guild, $args[2]);

                if (is_null($user)) {
                    return self::error($message, "Error", "I don't know who the hell {$args[2]} is :(");
                }

                $query = DatabaseFactory::get()->prepare('INSERT INTO wormrp_users (`user`, `redditName`) VALUES(?, ?) '
                    . 'ON DUPLICATE KEY UPDATE `redditName`=VALUES(`redditName`);', ['integer', 'string']);
                $query->bindValue(1, $user->id);
                $query->bindValue(2, $args[1]);
                $query->execute();

                return self::send($message->channel,
                    "Added/updated {$user->user->tag} ({$user->id}) to tracker with reddit username /u/{$args[1]}.");
            } catch (Throwable $e) {
                return self::exceptionHandler($message, $e, true);
            }
        }
    }

    public static function lookupHandler(EventData $data): ?ExtendedPromiseInterface
    {
        try {
            $args = self::_split($data->message->content);
            if (count($args) < 2) {
                return self::error($data->message, "Error", "usage: `!character Character Name`");
            }
            $char = trim(str_replace($args[0], "", $data->message->content)); // todo: do this better

            return all([
                'wiki' => URLHelpers::resolveURLToData("https://wormrp.syl.ae/w/api.php?action=ask&format=json&api_version=3&query=[[Identity::like:*" . urlencode($char) . "*]]|?Identity|?Author|?Alignment|?Affiliation|?Status|?Meta%20element%20og-image"),
                'reddit' => URLHelpers::resolveURLToData("https://www.reddit.com/r/wormrp/search.json?q=flair%3ACharacter+" . urlencode($char) . "&sort=relevance&restrict_sr=on&t=all&include_over_18=on"),
            ])->then(function ($results) use ($char, $data) {
                $wiki = self::lookupWiki($results['wiki'], $char);
                if (count($wiki) > 0) {
                    return all(array_map(function ($embed) use ($data) {
                        return $data->message->channel->send("", ['embed' => $embed]);
                    }, $wiki));
                }

                $reddit = self::lookupReddit($results['reddit'], $char);
                if (!is_null($reddit)) {
                    return $data->message->channel->send($reddit);
                }

                return $data->message->channel->send("I didn't find anything on the wiki or reddit :sob:");
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
            $embed->addField("Known as", implode(", ", $item->printouts->Identity ?? ['Unkown']));
            $embed->addField("Player", implode("\n", $item->printouts->Author ?? ['Unkown']), true);
            $embed->addField("Status", implode("\n", $item->printouts->Status ?? ['Unkown']), true);
            $embed->addField("Alignment", implode("\n", $item->printouts->Alignment ?? ['Unkown']), true);
            $embed->addField("Affiliation", implode("\n", $item->printouts->Affiliation ?? ['Unkown']), true);
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
                "\n*If this is your character, please port them over to the wiki when you have time with this link: <https://syl.ae/wormrpwiki.php?name=" .
                rawurlencode($char) . ">*";
        }
        return null;
    }
}
