<?php
/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;


use Carbon\Carbon;
use CharlotteDunois\Yasmin\Models\Message;
use CharlotteDunois\Yasmin\Models\MessageEmbed;
use CharlotteDunois\Yasmin\Models\TextChannel;
use Doctrine\DBAL\Schema\Schema;
use Huntress\DatabaseFactory;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Huntress\Snowflake;
use React\Promise\PromiseInterface as Promise;
use Throwable;

use function React\Promise\all;

class Remind implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addCommand("rem")
                ->addCommand("remind")
                ->addCommand("remindme")
                ->addCommand("reminder")
                ->setCallback([self::class, "remindMe"])
        );

        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addEvent("dbSchema")
                ->setCallback([self::class, "db"])
        );
        $bot->eventManager->addEventListener(
            EventListener::new()->setCallback([
                self::class,
                "reminderPoll",
            ])->setPeriodic(10)
        );
    }

    public static function reminderPoll(Huntress $bot): ?Promise
    {
        try {
            $query = $bot->db->query("select * from remind where timeRemind < now() limit 5");
            $p = [];
            foreach ($query->fetchAll() as $rem) {
                $r[] = self::sendReminder($bot, $rem);
            }
            return all($p);
        } catch (Throwable $e) {
            $bot->log->warning($e->getMessage(), ['exception' => $e]);
        }
    }

    private static function sendReminder(Huntress $bot, array $rem): ?Promise
    {
        try {
            /** @var TextChannel $channel */
            $channel = $bot->channels->get($rem['idChannel']);
            if (is_null($channel)) {
                return null;
            }
            $member = $channel->guild->members->get($rem['idMember']);
            if (is_null($member)) {
                return null;
            }

            $time = \CharlotteDunois\Yasmin\Utils\Snowflake::deconstruct($rem['idMessage'])->timestamp;
            $text = $rem['message'];
            $url = sprintf(
                "https://canary.discordapp.com/channels/%s/%s/%s",
                $channel->guild->id,
                $channel->id,
                $rem['idMessage']
            );

            $embed = new MessageEmbed();
            $embed->setAuthor($member->displayName, $member->user->getAvatarURL(64) ?? null);
            $embed->setColor($member->id % 0xFFFFFF);
            $embed->setTimestamp($time);
            $embed->setTitle("Reminder!");
            $embed->setDescription($text);

            $query = $bot->db->prepare('DELETE FROM remind WHERE (`idMessage` = ?)', ['integer']);
            $query->bindValue(1, $rem['idMessage']);
            $query->execute();

            return $channel->send($member . ": " . $url, ['embed' => $embed]);
        } catch (Throwable $e) {
            $bot->log->warning($e->getMessage(), ['exception' => $e]);
        }
    }

    public static function db(Schema $schema): void
    {
        $t = $schema->createTable("remind");
        $t->addColumn("idReminder", "bigint", ["unsigned" => true]);
        $t->addColumn("idMessage", "bigint", ["unsigned" => true]);
        $t->addColumn("idMember", "bigint", ["unsigned" => true]);
        $t->addColumn("idChannel", "bigint", ["unsigned" => true]);
        $t->addColumn("timeRemind", "datetime");
        $t->addColumn("message", "string", ['customSchemaOptions' => DatabaseFactory::CHARSET]);
        $t->setPrimaryKey(["idReminder"]);
    }

    public static function remindMe(EventData $data): ?Promise
    {
        try {
            $time = self::arg_substr($data->message->content, 1, 1);
            $text = self::arg_substr($data->message->content, 2);

            if (!$time || $time === 'help') {
                return $data->message->reply(self::getHelp());
            } elseif ($time === 'del' || $time === 'delete') {
                return self::deleteReminder($data, $text);
            }
            if (!$text) {
                $text = "*No reminder message left*";
            }

            // get the user's locale first
            $user_tz = Localization::fetchTimezone($data->message->member);
            if (is_null($user_tz)) {
                $user_tz = "UTC";
            }

            // get origininal time
            try {
                $time = trim($time);
                $time = self::readTime($time, $user_tz);
                $time->setTimezone($user_tz);
            } catch (Throwable $e) {
                return $data->message->reply("I couldn't figure out what time `$time` is :(");
            }

            // generate a unique identifier for removal
            $id = Snowflake::generate();

            $embed = new MessageEmbed();
            $embed->setAuthor(
                $data->message->member->displayName,
                $data->message->member->user->getAvatarURL(64) ?? null
            );
            $embed->setColor($data->message->member->id % 0xFFFFFF);
            $embed->setTimestamp($time->getTimestamp());
            $embed->setTitle("Reminder added");
            $embed->setDescription($text);

            $tzinfo = sprintf("%s (%s)", $time->getTimezone()->toRegionName(), $time->getTimezone()->toOffsetName());
            $embed->addField(
                "Detected Time",
                $time->toDayDateTimeString() . PHP_EOL . $tzinfo . PHP_EOL . $time->longRelativeToNowDiffForHumans(2)
            );
            $embed->setFooter(sprintf("Reminder %s", Snowflake::format($id)));

            self::addReminder($data->message, $time, $text, $id);

            return $data->message->reply("", ['embed' => $embed]);
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, false);
        }
    }

    public static function getHelp(): string
    {
        return <<<HELP
**Usage**: `!remind (when) (message)`

`(when)` can be one of the following:
- a relative time, such as "5 hours" "next tuesday" "5h45m". Avoid words like "in" and "at" because I don't understand them.
- an absolute time, such as "september 3rd" "2025-02-18" "5:00am". I'm pretty versatile but if I have trouble `YYYY-MM-DD HH:MM:SS AM/PM` will almost always work.

To delete a pending reminder, use the command: `!remind delete (id)`. The `(id)` value is given in the
footer of the confirmation message when the reminder is created.

Notes:
- I will use your timezone if you've told it to me via the `!timezone` command, or UTC otherwise.
- If you have spaces in your `(when)` then you need to wrap it in double quotes, or escape the spaces. Sorry!
HELP;
    }

    public static function deleteReminder(EventData $data, string $code): ?Promise
    {
        /** @var \Doctrine\DBAL\Connection $db */
        $message = $data->message;
        $db = $message->client->db;
        $id = Snowflake::parse($code);
        $reminder = $db->executeQuery("SELECT * FROM remind WHERE (`idReminder` = ?)", [$id])->fetch();

        if (!$reminder) {
            return $message->reply("No reminder matching `$code` was found.");
        }
        if ($message->member->id !== $reminder['idMember']) {
            $p = new Permission('p.reminder.delete', $data->huntress, false);
            $p->addMessageContext($data->message);
            if (!$p->resolve()) {
                return $message->reply("You cannot delete a reminder created by another user.");
            }
        }
        $stmt = $db->prepare('DELETE FROM remind WHERE (`idReminder` = ?)', ['integer']);
        $stmt->bindValue(1, $id);
        $stmt->execute();
        return $message->reply("Reminder deleted.");
    }

    private static function addReminder(Message $message, Carbon $time, string $text, int $id)
    {
        $time->setTimezone("UTC");
        $query = $message->client->db->prepare(
            'REPLACE INTO remind (`idReminder`, `idMessage`, `idMember`, `idChannel`, `timeRemind`, `message`) VALUES(?, ?, ?, ?, ?, ?)',
            ['integer', 'integer', 'integer', 'datetime', 'string', 'integer']
        );
        $query->bindValue(1, $id);
        $query->bindValue(2, $message->id);
        $query->bindValue(3, $message->member->id);
        $query->bindValue(4, $message->channel->id);
        $query->bindValue(5, $time);
        $query->bindValue(6, $text);
        $query->execute();
    }
}

