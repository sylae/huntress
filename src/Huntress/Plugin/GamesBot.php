<?php

namespace Huntress\Plugin;


use CharlotteDunois\Collect\Collection;
use CharlotteDunois\Yasmin\Models\Guild;
use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\MessageEmbed;
use CharlotteDunois\Yasmin\Utils\MessageHelpers;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Schema;
use GetOpt\ArgumentException;
use GetOpt\Command;
use GetOpt\GetOpt;
use GetOpt\Operand;
use GetOpt\Option;
use Huntress\DatabaseFactory;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface;
use Throwable;

class GamesBot implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("gamesbot")
            ->addCommand("gamebot")
            ->addCommand("gb")
            ->setCallback([self::class, "gameHandler"]));

        $bot->eventManager->addEventListener(EventListener::new()
            ->addEvent("dbSchema")
            ->setCallback([self::class, "db"])
        );
    }

    public static function db(Schema $schema): void
    {
        $t = $schema->createTable("gamesbot_games");
        $t->addColumn("idMember", "bigint", ["unsigned" => true]);
        $t->addColumn("game", "string", ['customSchemaOptions' => DatabaseFactory::CHARSET]);
        $t->setPrimaryKey(["idMember", "game"]);

        // todo: inheritence of games similar to the Role plugin
        // for example: if "pathfinder" inherits "ttrpg" then pinging ttrpg will also ping pathfinder folks
        $t2 = $schema->createTable("gamesbot_inheritance");
        $t2->addColumn("parentGame", "string", ['customSchemaOptions' => DatabaseFactory::CHARSET]);
        $t2->addColumn("childGame", "string", ['customSchemaOptions' => DatabaseFactory::CHARSET]);
        $t2->setPrimaryKey(["parentGame", "childGame"]);
    }

    public static function gameHandler(EventData $data): ?PromiseInterface
    {
        try {
            $getOpt = new GetOpt();
            $getOpt->set(GetOpt::SETTING_SCRIPT_NAME, '!gamesbot');
            $getOpt->set(GetOpt::SETTING_STRICT_OPERANDS, true);
            $commands = [];
            $commands[] = Command::create('add',
                [self::class, 'addGameHandler'])->setDescription('Add a member to a game tag')->addOperands([
                (new Operand('game',
                    Operand::REQUIRED))->setValidation('is_string')->setDescription("The game tag being added to"),
            ])->addOptions([
                (new Option("p", "player",
                    GetOpt::REQUIRED_ARGUMENT))->setDescription("The player being added (default: self)"),
            ]);
            $commands[] = Command::create('remove',
                [self::class, 'removeGameHandler'])->setDescription('Remove a member from a game tag')->addOperands([
                (new Operand('game',
                    Operand::REQUIRED))->setValidation('is_string')->setDescription("The game tag being removed from"),
            ])->addOptions([
                (new Option("p", "player",
                    GetOpt::REQUIRED_ARGUMENT))->setDescription("The player being removed (default: self)"),
            ]);
            $commands[] = Command::create('list',
                [self::class, 'listGameHandler'])->setDescription('Show valid game tags')->addOperands([]);

            $commands[] = Command::create('ping',
                [self::class, 'pingGameHandler'])->setDescription('Ping members with a game tag')->addOperands([
                (new Operand('game',
                    Operand::REQUIRED))->setValidation('is_string')->setDescription("The game tag being pinged to"),
            ])->addOptions([
                (new Option("n", "no-ping",
                    GetOpt::NO_ARGUMENT))->setDescription("Don't @ the members, just list names"),
            ]);
            $getOpt->addCommands($commands);
            try {
                $args = substr(strstr($data->message->content, " "), 1);
                $getOpt->process((string)$args);
            } catch (ArgumentException $exception) {
                return self::send($data->message->channel, "```\n" . $getOpt->getHelpText() . "```");
            }
            $command = $getOpt->getCommand();
            if (is_null($command)) {
                return self::send($data->message->channel, "```\n" . $getOpt->getHelpText() . "```");
            }
            return call_user_func($command->getHandler(), $getOpt, $data);
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, false);
        }
    }

    public static function addGameHandler(GetOpt $getOpt, EventData $data)
    {
        $game = mb_strtolower($getOpt->getOperand("game"));
        $member = self::parseGuildUser($data->guild, $getOpt->getOption("player") ?? $data->message->member);
        if (!$member instanceof GuildMember) {
            return self::error($data->message, "Invalid user",
                "I couldn't figure out who that is. Try using their tag or @ing them?");
        }
        self::addGame($member, $game);
        return $data->message->channel->send("`{$member->displayName}` has been added to `$game`");
    }

    private static function addGame(GuildMember $member, string $game)
    {
        $query = $member->client->db->prepare('REPLACE INTO gamesbot_games (`idMember`, `game`) VALUES(?, ?)',
            ['integer', 'string']);
        $query->bindValue(1, $member->id);
        $query->bindValue(2, $game);
        $query->execute();
    }

    public static function removeGameHandler(GetOpt $getOpt, EventData $data)
    {
        $game = mb_strtolower($getOpt->getOperand("game"));
        $member = self::parseGuildUser($data->guild, $getOpt->getOption("player") ?? $data->message->member);
        if (!$member instanceof GuildMember) {
            return self::error($data->message, "Invalid user",
                "I couldn't figure out who that is. Try using their tag or @ing them?");
        }
        self::removeGame($member, $game);
        return $data->message->channel->send("`{$member->displayName}` has been yote from `$game`");
    }

    private static function removeGame(GuildMember $member, string $game)
    {
        $query = $member->client->db->prepare('DELETE FROM gamesbot_games WHERE (`idMember` = ?) and (`game` = ?)',
            ['integer', 'string']);
        $query->bindValue(1, $member->id);
        $query->bindValue(2, $game);
        $query->execute();
    }

    public static function pingGameHandler(GetOpt $getOpt, EventData $data)
    {
        $game = mb_strtolower($getOpt->getOperand("game"));
        $noping = $getOpt->getOption("no-ping");
        $games = self::getGames($data->guild);

        if (!array_key_exists($game, $games)) {
            return $data->message->channel->send("No members with `$game` are present on this server");
        }

        $members = array_map(fn($v) => $data->guild->members->get($v), $games[$game]);
        if ($noping) {
            $members = array_map(fn($v) => $v->displayName, $members);
        }
        $members = implode(", ", $members);

        return $data->message->channel->send("`{$data->message->member->displayName}` wants to play `$game`\n$members");
    }

    private static function getGames(Guild $guild): array
    {
        /** @var QueryBuilder $qb */
        $qb = $guild->client->db->createQueryBuilder();
        $qb->select("*")->from("gamesbot_games");
        $res = $qb->execute()->fetchAll();
        $x = [];
        foreach ($res as $row) {
            $game = $row['game'];
            $member = $row['idMember'];
            if (!$guild->members->has($member)) {
                continue;
            }
            if (!array_key_exists($game, $x)) {
                $x[$game] = [];
            }
            $x[$game][] = $member;
        }
        return $x;
    }

    public static function listGameHandler(GetOpt $getOpt, EventData $data)
    {
        // todo sorting
        $games = new Collection(self::getGames($data->guild));

        $embed = new MessageEmbed();
        $embed->setTitle("Games - {$data->guild->name}")
            ->setColor($data->message->member->getDisplayColor());


        $entries = $games->map(function ($v, $k) use ($data) {
            $star = in_array($data->message->member->id, $v) ? "⭐" : "";
            return sprintf("%s (%s) %s", $star, count($v), $k);
        })->implode('val', PHP_EOL);

        if (mb_strlen($entries) > 0) {
            $embed->setDescription("Use `!gamesbot add GAME` to add a game\nA ⭐ indicates you have that game added\nThe number in parentheses shows how many server members have that tag");
            $roles = MessageHelpers::splitMessage($entries,
                ['maxLength' => 1024]);
            $firstRole = true;
            foreach ($roles as $role) {
                $embed->addField($firstRole ? "Games" : "Games (cont.)", $role, true);
                $firstRole = false;
            }
        }

        return $data->message->channel->send("", ['embed' => $embed]);
    }

    public static function getHelp(): string
    {
        return <<<HELP
**Usage**: `!gamesbot (command) (argument)`

valid commands:
- `add`: add yourself to a game
- `list`: show current game tags in use
- `players`: show who has a game tag, without pinging
- `ping`: ping a particular game
HELP;

    }
}

