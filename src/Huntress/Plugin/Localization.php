<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use Carbon\Carbon;
use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\GuildMemberStorage;
use CharlotteDunois\Yasmin\Models\Message;
use CharlotteDunois\Yasmin\Models\MessageEmbed;
use CharlotteDunois\Yasmin\Utils\MessageHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Huntress\DatabaseFactory;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Huntress\UserLocale;
use React\Promise\PromiseInterface as Promise;
use Throwable;

/**
 * Simple builtin to show user information
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class Localization implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "timezone", [self::class, "timezone"]);
        // $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "locale", [self::class, "locale"]);
        $bot->on(self::PLUGINEVENT_DB_SCHEMA, [self::class, "db"]);

        $eh = EventListener::new()
            ->addCommand("time")
            ->setCallback([self::class, "timeHelper"]);
        $bot->eventManager->addEventListener($eh);
    }

    public static function db(Schema $schema): void
    {
        $t = $schema->createTable("locale");
        $t->addColumn("user", "bigint", ["unsigned" => true]);
        $t->addColumn("timezone", "text",
            ['customSchemaOptions' => DatabaseFactory::CHARSET, 'notnull' => false]);
        $t->addColumn("locale", "text",
            ['customSchemaOptions' => DatabaseFactory::CHARSET, 'notnull' => false]);
        $t->setPrimaryKey(["user"]);
    }

    public static function timezone(Huntress $bot, Message $message): ?Promise
    {
        try {
            $args = self::_split($message->content);
            $now = Carbon::now();
            if (count($args) > 1) {
                $query = DatabaseFactory::get()->prepare('INSERT INTO locale (user, timezone) VALUES(?, ?) '
                    . 'ON DUPLICATE KEY UPDATE timezone=VALUES(timezone);', ['integer', 'string']);
                $query->bindValue(1, $message->author->id);
                $query->bindValue(2, $args[1]);
                $query->execute();
                $string = "Your timezone has been updated to **%s**.\nI have your local time as **%s**";
            } else {
                $string = "Your timezone is currently set to **%s**.\nI have your local time as **%s**\n\nTo update, run `!timezone NewTimeZone` with one of the values in <https://www.php.net/manual/en/timezones.php>.";
            }
            $tz = new UserLocale($message->author);
            $now_tz = $tz->applyTimezone($now);
            return self::send($message->channel, sprintf($string, $tz->timezone ?? "<unset (default UTC)>",
                $tz->localeSandbox(function () use ($now_tz) {
                    return $now_tz->toDayDateTimeString();
                })));
        } catch (Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }

    public static function timeHelper(EventData $data): ?Promise
    {
        $time = str_replace(self::_split($data->message->content)[0], "", $data->message->content);
        $warn = [];
        // get the user's locale first
        $user_tz = self::fetchTimezone($data->message->member);
        if (is_null($user_tz)) {
            $warn[] = "Note: Your timezone is unset, assuming UTC. Please use `!timezone` to tell me your timezone.";
            $user_tz = "UTC";
        }

        // get origininal time
        try {
            $time = trim($time);
            $time = self::readTime($time, $user_tz);
        } catch (\Throwable $e) {
            return $data->message->channel->send("I couldn't figure out what time `$time` is :(");
        }

        // grab everyone's zone
        $tzs = self::fetchTimezones(self::getMembersWithPermission($data->channel));

        $lines = [];
        foreach ($tzs as $tz) {
            try {
                $ntime = clone $time;
                $ntime->setTimezone($tz);
                $lines[] = sprintf("**%s**: %s", $tz, $ntime->toDayDateTimeString());
            } catch (\Throwable $e) {
                // whatever
            }
        }
        $lines = implode(PHP_EOL, $lines);

        $embed = new MessageEmbed();

        $tzinfo = sprintf("%s (%s)", $time->getTimezone()->toRegionName(), $time->getTimezone()->toOffsetName());
        $embed->addField("Detected Time",
            $time->toDayDateTimeString() . PHP_EOL . $tzinfo . PHP_EOL . $time->longRelativeToNowDiffForHumans(2));

        $times = MessageHelpers::splitMessage($lines,
            ['maxLength' => 1024]);
        $firstTime = true;
        foreach ($times as $tblock) {
            $embed->addField($firstTime ? "Times" : "Times (cont.)", $tblock);
            $firstTime = false;
        }

        $embed->setTitle("Translated times for users in channel");
        $embed->setDescription("Don't see your timezone? Use the `!timezone` command.");

        return $data->message->channel->send(implode(PHP_EOL, $warn) ?? "", ['embed' => $embed]);
    }

    public static function fetchTimezone(GuildMember $member): ?string
    {
        $res = DatabaseFactory::get()->executeQuery('SELECT * FROM locale WHERE user=?',
            [$member->id], ["integer"])->fetchAll();
        foreach ($res as $row) {
            return $row['timezone'] ?? null;
        }
        return null;
    }

    public static function fetchTimezones(GuildMemberStorage $members): array
    {
        $res = DatabaseFactory::get()->executeQuery('SELECT * FROM locale WHERE user IN (?)',
            [$members->pluck("id")->all()], [Connection::PARAM_INT_ARRAY])->fetchAll();
        return array_unique(array_column($res, "timezone"));
    }
}
