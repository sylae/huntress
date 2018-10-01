<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use GetOpt\GetOpt;
use GetOpt\Operand;
use GetOpt\Option;
use GetOpt\Command;
use GetOpt\ArgumentException;

/**
 * Simple builtin to show user information
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class Sprint implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;
    const STATUS_ACTIVE   = 0;
    const STATUS_FINISHED = 1;
    const STATUS_HIDDEN   = 2;

    public static function register(\Huntress\Bot $bot)
    {
        $bot->client->on(self::PLUGINEVENT_COMMAND_PREFIX . "sprint", [self::class, "process"]);
        $bot->client->on(self::PLUGINEVENT_DB_SCHEMA, [self::class, "db"]);
        $bot->client->on(self::PLUGINEVENT_READY, [self::class, "poll"]);
    }

    public static function db(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        $t = $schema->createTable("sprint");
        $t->addColumn("sid", "integer", ["unsigned" => true, "autoincrement" => true]);
        $t->addColumn("user", "bigint", ["unsigned" => true]);
        $t->addColumn("guild", "bigint", ["unsigned" => true]);
        $t->addColumn("channel", "bigint", ["unsigned" => true]);
        $t->addColumn("words", "integer", ["unsigned" => true]);
        $t->addColumn("current", "integer", ["unsigned" => true, "default" => 0]);
        $t->addColumn("status", "integer", ["unsigned" => true, "default" => self::STATUS_ACTIVE]);
        $t->addColumn("period", "integer", ["unsigned" => true]);
        $t->addColumn("startTime", "datetime");
        $t->addColumn("endTime", "datetime");
        $t->addColumn("label", "text", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t->setPrimaryKey(["sid"]);
        $t->addIndex(["user"]);
        $t->addIndex(["endTime"]);
        $t->addIndex(["guild"]);
    }

    public static function poll(\Huntress\Bot $bot)
    {
        $bot->loop->addPeriodicTimer(1, function() use ($bot) {
            foreach ($bot->client->guilds as $guild) {
                foreach (self::getSprints($guild, null, self::STATUS_ACTIVE) as $id => $sprint) {
                    if ($sprint['endTime'] < \Carbon\Carbon::now()) {
                        $args    = [
                            $sprint['user']->id,
                            $sprint['words'],
                            $sprint['label'],
                            $sprint['endTime']->diffForHumans($sprint['startTime'], true, false, 2),
                            $id,
                        ];
                        $message = vsprintf("<@%s>, your sprint has ended!\n"
                        . "You were sprinting for %s %s, over %s.\n"
                        . "Please report your final total using `!sprint update %s final_count`.", $args);
                        self::send($sprint['channel'], $message)->then(function() use ($id) {
                            $qb = \Huntress\DatabaseFactory::get()->createQueryBuilder();
                            $qb->update("sprint")->set("status", self::STATUS_FINISHED)->where($qb->expr()->eq('sid', $id))->execute();
                        });
                    }
                }
            }
        });
    }

    public static function process(\Huntress\Bot $bot, \CharlotteDunois\Yasmin\Models\Message $message): \React\Promise\ExtendedPromiseInterface
    {
        try {
            $getOpt = new GetOpt();
            $getOpt->set(GetOpt::SETTING_SCRIPT_NAME, '!sprint');
            $getOpt->set(GetOpt::SETTING_STRICT_OPERANDS, true);

            $commands = [];

            $commands[] = Command::create('set', [self::class, 'setHandler'])->setDescription('Set a deadline')->addOperands([
                (new Operand('words', Operand::REQUIRED))->setValidation('is_numeric'),
                (new Operand('period', Operand::OPTIONAL))->setValidation('is_string')->setDefaultValue("24h"),
            ])->addOptions([
                (new Option('l', 'label', GetOpt::OPTIONAL_ARGUMENT))->setDefaultValue("words")->setDescription('The thing you are tracking (default: words)')
            ]
            );

            $commands[] = Command::create('status', [self::class, 'statusHandler'])->setDescription('Check your sprint status')->addOptions([
                (new Option('m', 'me', GetOpt::NO_ARGUMENT))->setDescription("Only show your own sprints"),
                (new Option('a', 'all', GetOpt::NO_ARGUMENT))->setDescription("Show all sprints (default: only sprints expiring later than 24 hours ago)"),
            ]);
            $commands[] = Command::create('update', [self::class, 'updateHandler'])->setDescription('Update a sprint\'s count')->addOperands([
                (new Operand('id', Operand::REQUIRED))->setValidation('is_numeric'),
                (new Operand('words', Operand::REQUIRED))->setValidation('is_numeric'),
            ]);

            $getOpt->addCommands($commands);

            try {
                $args = substr(strstr($message->content, " "), 1);
                $getOpt->process((string) $args);
            } catch (ArgumentException $exception) {
                return self::send($message->channel, $getOpt->getHelpText());
            }

            $command = $getOpt->getCommand();
            return call_user_func($command->getHandler(), $getOpt, $message);
        } catch (\Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }

    public static function setHandler(GetOpt $getOpt, \CharlotteDunois\Yasmin\Models\Message $message)
    {
        $words  = (int) $getOpt->getOperand('words');
        $period = (string) $getOpt->getOperand('period');
        $label  = $getOpt->getOption('label');
        if ($words < 1) {
            throw new \RuntimeException("`words` must be an integer greater than zero.");
        }
        $now  = \Carbon\Carbon::now();
        $time = self::readTime($period);


        $qb = \Huntress\DatabaseFactory::get()->createQueryBuilder();

        $qb->insert("sprint")->values([
            'user'      => '?',
            'guild'     => '?',
            'startTime' => '?',
            'endTime'   => '?',
            'words'     => '?',
            'period'    => '?',
            'label'     => '?',
            'channel'   => '?',
        ])
        ->setParameter(0, $message->author->id, "integer")
        ->setParameter(1, $message->guild->id, "integer")
        ->setParameter(2, $now, "datetime")
        ->setParameter(3, $time, "datetime")
        ->setParameter(4, $words, "integer")
        ->setParameter(5, $time->diffInSeconds($now, true), "integer")
        ->setParameter(6, $label, "text")
        ->setParameter(7, $message->channel->id, "integer")
        ->execute();
        $id    = $qb->getConnection()->lastInsertId();
        $line1 = sprintf("%s, you are sprinting towards %s %s over %s. Your deadline is %s.", $message->member, number_format($words), $label, $time->diffForHumans($now, true, false, 2), $time->toAtomString());
        $line2 = sprintf("I will @ you in %s when time is up, and you can use `!sprint update %s current_count` at any time. Good luck!", $message->channel, $id);
        return self::send($message->channel, $line1 . PHP_EOL . $line2);
    }

    public static function statusHandler(GetOpt $getOpt, \CharlotteDunois\Yasmin\Models\Message $message)
    {
        $me      = $getOpt->getOption('me') ? $message->author : null;
        $time    = $getOpt->getOption('all') ? null : \Carbon\Carbon::now()->subDay();
        $status  = $getOpt->getOption('all') ? null : self::STATUS_ACTIVE;
        $sprints = self::getSprints($message->guild, $me, $status, $time);
        $x       = self::formatSprintTable($sprints);

        $tag = sprintf("**Sprint status: %s**\n"
        . "User: %s // Status: `%s` // Time: `%s`\n", $message->guild->name, $me ?? "`All Users`", is_null($status) ? "All" : "Active", ($time instanceof \Carbon\Carbon) ? $time->toAtomString() : "All");

        return self::send($message->channel, $tag . "```" . PHP_EOL . $x . PHP_EOL . "```", ['split' => ['before' => '```' . PHP_EOL, 'after' => '```']]);
    }

    public static function updateHandler(GetOpt $getOpt, \CharlotteDunois\Yasmin\Models\Message $message)
    {
        $words   = (int) $getOpt->getOperand('words');
        $id      = (int) $getOpt->getOperand('id');
        $sprints = self::getSprints($message->guild, $message->author);
        if (!array_key_exists($id, $sprints)) {
            return self::error($message, "Unknown Sprint", "I can't find a sprint with that ID under your name.\nUse `!sprint status` to see your sprints.");
        }
        $now = \Carbon\Carbon::now();
        $qb  = \Huntress\DatabaseFactory::get()->createQueryBuilder();
        $qb->update("sprint")->set("current", $words)->where($qb->expr()->eq('sid', $id))->execute();

        $status = $sprints[$id]['status'];

        $curr     = $words;
        $goal     = $sprints[$id]['words'];
        $pct_goal = ($curr / $goal) * 100;

        $label = $sprints[$id]['label'];

        /* @var $begin \Carbon\Carbon */
        $begin = $sprints[$id]['startTime'];
        /* @var $end \Carbon\Carbon */
        $end   = $sprints[$id]['endTime'];

        $seconds_elapsed = $now->diffInSeconds($begin);
        $pct_time        = ($seconds_elapsed / $end->diffInSeconds($begin)) * 100;



        $embed = self::easyEmbed($message);
        $embed->setTitle("Sprint updated!")->setThumbnail($message->author->getAvatarURL())
        ->addField("Count", sprintf("%s / %s %s (%s %s)", number_format($curr), number_format($goal), $label, number_format($pct_goal, 1) . "%", ($curr >= $goal) ? ":cookie:" : ":bell:"), true)
        ->addField("Status", $status, true)
        ;
        if ($status == self::STATUS_ACTIVE) {
            $embed->addField("Duration", sprintf("%s\n*%s (%s)*", $end->diffForHumans($begin, true, false, 2), $end->diffForHumans(null, false, false, 2), number_format($pct_time, 1) . "%"));
        } else {
            $embed->addField("Duration", sprintf("%s", $end->diffForHumans($begin, true, false, 2)));
        }
        $embed->addField("Channel", sprintf("%s on %s", $sprints[$id]['channel'], $sprints[$id]['guild']->name), true);
        return self::send($message->channel, "", ['embed' => $embed]);
    }

    private static function formatSprintTable(array $sprints): string
    {
        $x     = "";
        $lines = [];
        foreach ($sprints as $id => $v) {
            $lines[] = [
                'ID'        => $id,
                'User'      => $v['user']->user->tag,
                'Count'     => number_format($v['current']),
                '/'         => '/',
                'Goal'      => number_format($v['words']),
                'Items'     => $v['label'],
                'Pct'       => number_format(($v['current'] / $v['words']) * 100, 1) . "%",
                'Duration'  => $v['endTime']->diffForHumans($v['startTime'], true, true, 2),
                'Remaining' => $v['endTime']->diffForHumans(null, false, true, 2),
            ];
        }
        $right = ['Count', 'Goal', 'Pct'];

        $len = [];
        foreach ($lines as $data) {
            foreach ($data as $col => $row) {
                $len[$col] = max($len[$col] ?? 0, mb_strlen($row));
            }
        }
        $l = "";
        foreach ($lines[0] as $key => $val) {
            $l .= str_pad($key, $len[$key], " ", in_array($key, $right) ? STR_PAD_LEFT : STR_PAD_RIGHT) . " ";
        }
        $x .= trim($l) . PHP_EOL;


        foreach ($lines as $line) {
            $l = "";
            foreach ($line as $key => $val) {
                $l .= str_pad($val, $len[$key], " ", in_array($key, $right) ? STR_PAD_LEFT : STR_PAD_RIGHT) . " ";
            }
            $x .= trim($l) . PHP_EOL;
        }
        return $x;
    }

    private static function readTime(string $r): \Carbon\Carbon
    {
        if (self::isRelativeTime($r)) {
            return self::timeRelative($r);
        } else {
            return (new \Carbon\Carbon($r))->setTimezone("UTC");
        }
    }

    private static function isRelativeTime(string $r): bool
    {
        $matches  = [];
        $nmatches = 0;
        if (preg_match_all("/((\\d+)([ywdhm]))/i", $r, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $nmatches++;
            }
        }
        $m = preg_replace("/((\\d+)([ywdhm]))/i", "", $r);
        return ($nmatches > 0 && mb_strlen(trim($m)) == 0);
    }

    private static function TimeRelative(string $r): \Carbon\Carbon
    {
        $matches = [];
        if (preg_match_all("/((\\d+)([ywdhms]))/i", $r, $matches, PREG_SET_ORDER)) {
            $time = \Carbon\Carbon::now();
            foreach ($matches as $m) {
                $num = $m[2] ?? 1;
                $typ = mb_strtolower($m[3] ?? "m");
                switch ($typ) {
                    case "y":
                        $time->addYears($num);
                        break;
                    case "w":
                        $time->addWeeks($num);
                        break;
                    case "d":
                        $time->addDays($num);
                        break;
                    case "h":
                        $time->addHours($num);
                        break;
                    case "m":
                        $time->addMinutes($num);
                        break;
                    case "s":
                        $time->addSeconds($num);
                        break;
                }
            }
            return $time;
        } else {
            throw new \Exception("Could not parse relative time.");
        }
    }

    private static function getSprints(\CharlotteDunois\Yasmin\Models\Guild $guild, \CharlotteDunois\Yasmin\Models\User $user = null, int $status = null, \Carbon\Carbon $after = null): array
    {
        $qb = \Huntress\DatabaseFactory::get()->createQueryBuilder();
        $qb->select("*")->from("sprint")->where('guild = ?')->setParameter(0, $guild->id, "integer")->orderBy("endTime", "ASC");
        if (!is_null($user)) {
            $qb->andWhere('user = ?')->setParameter(1, $user->id, "integer");
        }
        if (!is_null($status)) {
            $qb->andWhere('status = ?')->setParameter(2, $status, "integer");
        }
        if (!is_null($after)) {
            $qb->andWhere('endTime >= ?')->setParameter(3, $after, "datetime");
        }
        $res = $qb->execute()->fetchAll();
        $p   = [];
        foreach ($res as $s) {
            $p[$s['sid']] = [
                'user'      => $guild->members->get($s['user']),
                'guild'     => $guild,
                'channel'   => $guild->client->channels->get($s['channel']),
                'current'   => $s['current'],
                'status'    => $s['status'],
                'words'     => $s['words'],
                'period'    => $s['period'],
                'label'     => $s['label'],
                'startTime' => new \Carbon\Carbon($s['startTime']),
                'endTime'   => new \Carbon\Carbon($s['endTime']),
            ];
        }
        return $p;
    }
}
