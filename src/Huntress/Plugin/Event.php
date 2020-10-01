<?php
/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;


use Carbon\Carbon;
use CharlotteDunois\Yasmin\Models\Guild;
use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\Message;
use CharlotteDunois\Yasmin\Models\MessageEmbed;
use CharlotteDunois\Yasmin\Models\TextChannel;
use CharlotteDunois\Yasmin\Utils\MessageHelpers;
use Doctrine\DBAL\Schema\Schema;
use Huntress\DatabaseFactory;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface;
use React\Promise\PromiseInterface as Promise;
use Throwable;
use function React\Promise\all;
use function Sentry\captureException;

class Event implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("event")
            ->setCallback([self::class, "event"]));

        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("calendar")
            ->setCallback([self::class, "calendar"]));

        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("setCalendar")
            ->setCallback([self::class, "setCalendar"]));

        $bot->eventManager->addEventListener(EventListener::new()
            ->addEvent("dbSchema")
            ->setCallback([self::class, "db"])
        );
        $bot->eventManager->addEventListener(EventListener::new()->setCallback([
            self::class,
            "calendarUpdate",
        ])->setPeriodic(60));
    }

    public static function calendar(EventData $data): ?PromiseInterface
    {
        // get the user's locale first
        $user_tz = Localization::fetchTimezone($data->message->member);
        if (is_null($user_tz)) {
            $user_tz = "UTC";
        }

        return $data->message->channel->send("", ['embed' => self::createCalendarEmbed($data->guild, $user_tz)]);
    }

    private static function createCalendarEmbed(Guild $guild, string $tz = "UTC"): MessageEmbed
    {
        $query = $guild->client->db->query("select * from event where idGuild = {$guild->id} and time >= now()");
        $x = [];
        foreach ($query->fetchAll() as $rem) {
            $time = new Carbon($rem['time']);
            $time->setTimezone($tz);
            $x[] = sprintf("**%s**: %s (%s)", $rem['name'], $time->toDayDateTimeString(),
                $time->longRelativeToNowDiffForHumans(2));
        }

        $embed = new MessageEmbed();
        $embed->setTitle("Events - {$guild->name}");
        $embed->setFooter("Timezone: " . $tz, $guild->getIconURL());
        $embed->setTimestamp(time());
        $embed->setDescription("To see these times in your timezone, run `!calendar`.");

        $x = implode(PHP_EOL, $x);
        if (mb_strlen($x) > 0) {
            $roles = MessageHelpers::splitMessage($x, ['maxLength' => 1024]);
            $firstRole = true;
            foreach ($roles as $role) {
                $embed->addField($firstRole ? "Events" : "Events (cont.)", $role);
                $firstRole = false;
            }
        } else {
            $embed->setDescription("There are no events scheduled. Add some using `!event`.");
        }

        return $embed;
    }

    public static function setCalendar(EventData $data): ?PromiseInterface
    {
        try {
            $p = new Permission("p.events.setcalendar", $data->huntress, false);
            $p->addMessageContext($data->message);
            if (!$p->resolve()) {
                return $p->sendUnauthorizedMessage($data->message->channel);
            }

            $ctxt = self::arg_substr($data->message->content, 1, 1) ?? null;
            if (mb_strlen($ctxt) == 0) {
                return $data->message->channel->send("Usage: `!setCalendar (channel mention or message URL)`");
            }


            if (is_null($x = self::channelMention($ctxt, $data->message->guild))) {
                return self::fetchMessage($data->huntress, $ctxt)->then(function (Message $importMsg) use ($data) {
                    try {
                        self::setCalendarMessage($importMsg);
                        return $data->message->channel->send("Calendar set to {$importMsg->getJumpURL()}");
                    } catch (Throwable $e) {
                        return self::exceptionHandler($data->message, $e, true);
                    }
                });
            } else {
                return $x->send("", ['embed' => self::createCalendarEmbed($data->guild)])->then(function (
                    Message $importMsg
                ) use ($data) {
                    try {
                        self::setCalendarMessage($importMsg);
                        return $data->message->channel->send("Calendar set to {$importMsg->getJumpURL()}");
                    } catch (Throwable $e) {
                        return self::exceptionHandler($data->message, $e, true);
                    }
                });
            }

        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true);
        }
    }

    private static function channelMention(string $text, Guild $guild): ?TextChannel
    {
        if (preg_match("/<#(\\d+)>/", $text, $matches)) {
            $ch = $guild->channels->resolve($matches[1]);
            if ($ch instanceof TextChannel) {
                return $ch;
            }
        }
        return null;
    }

    private static function setCalendarMessage(Message $message)
    {
        $query = $message->client->db->prepare('REPLACE INTO event_calendar (`idGuild`, `idChannel`, `idMessage`) VALUES(?, ?, ?)',
            ['integer', 'integer', 'integer']);
        $query->bindValue(1, $message->guild->id);
        $query->bindValue(2, $message->channel->id);
        $query->bindValue(3, $message->id);
        $query->execute();
    }

    public static function calendarUpdate(Huntress $bot): ?Promise
    {
        try {
            $query = $bot->db->query("select * from event_calendar");
            $p = [];
            foreach ($query->fetchAll() as $rem) {
                $guild = $bot->guilds->get($rem['idGuild']);
                $channel = $guild->channels->get($rem['idChannel']);
                if (!$channel instanceof TextChannel) {
                    $bot->log->debug("event_calendar links to non-text channel {$rem['idChannel']}");
                    continue;
                }
                return $channel->fetchMessage($rem['idMessage'])->then(function (Message $message) {
                    if ($message->author->id != $message->client->user->id) {
                        return;
                    }
                    return $message->edit("", ['embed' => self::createCalendarEmbed($message->guild)]);
                });
            }
            return all($p);
        } catch (Throwable $e) {
            captureException($e);
            $bot->log->warning($e->getMessage(), ['exception' => $e]);
        }
    }

    public static function db(Schema $schema): void
    {
        $t = $schema->createTable("event");
        $t->addColumn("idEvent", "bigint", ["unsigned" => true]);
        $t->addColumn("idGuild", "bigint", ["unsigned" => true]);
        $t->addColumn("idMember", "bigint", ["unsigned" => true]);
        $t->addColumn("time", "datetime");
        $t->addColumn("name", "string", ['customSchemaOptions' => DatabaseFactory::CHARSET]);
        $t->setPrimaryKey(["idEvent"]);
        $t->addIndex(['idGuild']);

        $t = $schema->createTable("event_calendar");
        $t->addColumn("idGuild", "bigint", ["unsigned" => true]);
        $t->addColumn("idChannel", "bigint", ["unsigned" => true]);
        $t->addColumn("idMessage", "bigint", ["unsigned" => true]);
        $t->setPrimaryKey(["idGuild"]);
    }

    public static function event(EventData $data): ?Promise
    {
        try {
            $p = new Permission("p.events.add", $data->huntress, true);
            $p->addMessageContext($data->message);
            if (!$p->resolve()) {
                return $p->sendUnauthorizedMessage($data->message->channel);
            }

            $time = self::arg_substr($data->message->content, 1, 1);
            $text = self::arg_substr($data->message->content, 2);

            if (!$time || !$text) {
                return $data->message->channel->send(self::getHelp());
            }

            if ($time == "remove") {
                $snow = \Huntress\Snowflake::parse($text);
                self::removeEvent($snow, $data->message->member);
                return $data->message->channel->send("If that was your event, it was removed :)");
            }

            // get the user's locale first
            $user_tz = Localization::fetchTimezone($data->message->member);
            if (is_null($user_tz)) {
                $user_tz = "UTC";
            }

            // get original time
            try {
                $time = trim($time);
                $time = self::readTime($time, $user_tz);
                $time->setTimezone($user_tz);
            } catch (Throwable $e) {
                return $data->message->channel->send("I couldn't figure out what time `$time` is :(");
            }

            $id = \Huntress\Snowflake::generate();

            $embed = new MessageEmbed();
            $embed->setAuthor($data->message->member->displayName,
                $data->message->member->user->getAvatarURL(64) ?? null);
            $embed->setColor($data->message->member->id % 0xFFFFFF);
            $embed->setTimestamp($time->getTimestamp());
            $embed->setTitle("Event added");
            $embed->setDescription($text);
            $embed->setFooter("Event ID " . \Huntress\Snowflake::format($id));

            $tzinfo = sprintf("%s (%s)", $time->getTimezone()->toRegionName(), $time->getTimezone()->toOffsetName());
            $embed->addField("Detected Time",
                $time->toDayDateTimeString() . PHP_EOL . $tzinfo . PHP_EOL . $time->longRelativeToNowDiffForHumans(2));

            self::addEvent($data->message, $time, $text, $id);

            return $data->message->channel->send("", ['embed' => $embed]);

        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, false);
        }
    }

    public static function getHelp(): string
    {
        return <<<HELP
**Usage**: `!event (when) (title)`

`(when)` can be one of the following:
- a relative time, such as "5 hours" "next tuesday" "5h45m". Avoid words like "in" and "at" because I don't understand them.
- an absolute time, such as "september 3rd" "2025-02-18" "5:00am". I'm pretty versatile but if I have trouble `YYYY-MM-DD HH:MM:SS AM/PM` will almost always work.

Notes:
- I will use your timezone if you've told it to me via the `!timezone` command, or UTC otherwise.
- If you have spaces in your `(when)` then you need to wrap it in double quotes, or escape the spaces. Sorry!
- To cancel an event, run `!event remove EVENT_ID`
HELP;

    }

    private static function removeEvent(int $id, GuildMember $member)
    {
        $query = $member->client->db->prepare('DELETE FROM event WHERE (`idEvent` = ?) and (`idMember` = ?)',
            ['integer', 'integer']);
        $query->bindValue(1, $id);
        $query->bindValue(2, $member->id);
        $query->execute();
    }

    private static function addEvent(Message $message, Carbon $time, string $text, int $id)
    {
        $time->setTimezone("UTC");
        $query = $message->client->db->prepare('REPLACE INTO event (`idEvent`, `idMember`, `idGuild`, `time`, `name`) VALUES(?, ?, ?, ?, ?)',
            ['integer', 'integer', 'integer', 'datetime', 'string']);
        $query->bindValue(1, $id);
        $query->bindValue(2, $message->member->id);
        $query->bindValue(3, $message->guild->id);
        $query->bindValue(4, $time);
        $query->bindValue(5, $text);
        $query->execute();

    }
}

