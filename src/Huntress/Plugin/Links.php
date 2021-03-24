<?php
/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;


use CharlotteDunois\Collect\Collection;
use CharlotteDunois\Yasmin\Models\Guild;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Schema;
use Huntress\DatabaseFactory;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Normalizer;
use React\Promise\PromiseInterface;

class Links implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("link")
            ->addCommand("links")
            ->setCallback([self::class, "linkHandler"])
        );

        $bot->eventManager->addEventListener(EventListener::new()
            ->addEvent("dbSchema")
            ->setCallback([self::class, "db"])
        );
    }

    public static function db(Schema $schema): void
    {
        $t = $schema->createTable("links");
        $t->addColumn("idGuild", "bigint", ["unsigned" => true]);
        $t->addColumn("name", "string", ['customSchemaOptions' => DatabaseFactory::CHARSET]);
        $t->addColumn("content", "string", ['customSchemaOptions' => DatabaseFactory::CHARSET]);
        $t->setPrimaryKey(["idGuild", "name"]);
    }

    public static function linkHandler(EventData $data): ?PromiseInterface
    {
        $tp = self::_split($data->message->content);

        return match ($tp[1] ?? 'ls') {
            'list', 'ls' => self::allLinksMessage($data),
            'add', 'create' => self::addLink($data),
            'delete', 'remove', 'rm' => self::deleteLink($data),
            'help' => self::help($data),
            default => self::showLink($data),
        };
    }

    public static function allLinksMessage(EventData $data): ?PromiseInterface
    {
        $p = new Permission("p.links.list", $data->huntress, true);
        $p->addMessageContext($data->message);
        if (!$p->resolve()) {
            return $p->sendUnauthorizedMessage($data->message->channel);
        }

        $links = self::getLinks($data->guild);

        if (count($links) > 0) {
            $x = implode(", ", array_map(fn($x) => "`$x`", array_keys($links)));
            return $data->message->reply($x, ['split' => ['char' => ', ']]);
        } else {
            return $data->message->reply("This server has no links! Add some with `!link add`");
        }
    }

    private static function getLinks(Guild $guild): array
    {
        /** @var QueryBuilder $qb */
        $qb = $guild->client->db->createQueryBuilder();
        $qb->select("*")->from("links")
            ->where("idGuild = ?")
            ->orderBy("name", "asc")
            ->setParameter(1, $guild->id, "integer");
        $res = $qb->execute()->fetchAllAssociative();
        $x = [];
        foreach ($res as $row) {
            $x[$row['name']] = $row['content'];
        }
        return $x;
    }

    public static function addLink(EventData $data): ?PromiseInterface
    {
        $p = new Permission("p.links.add", $data->huntress, true);
        $p->addMessageContext($data->message);
        if (!$p->resolve()) {
            return $p->sendUnauthorizedMessage($data->message->channel);
        }

        $links = self::getLinks($data->guild);
        $tp = self::_split($data->message->content);

        $k = self::normalizeKey($tp[2] ?? "");
        if (array_key_exists($k, $links)) {
            return $data->message->reply(sprintf("`%s` already exists! To change it, please delete and re-add", $k));
        } else {
            try {
                $val = trim(self::arg_substr($data->message->content, 3) ?? "");
                if (mb_strlen($val) == 0) {
                    return $data->message->reply("You need to tell me what to add! `!link add NAME CONTENT`");
                }
                self::_addLink($data->guild, $k, $val);
                return $data->message->reply("`$k` has been added");
            } catch (\Throwable $e) {
                return self::exceptionHandler($data->message, $e, false);
            }
        }
    }

    private static function normalizeKey(string $in): string
    {
        $in = str_replace(['<', '@', '>', ' ', '`', '#', ','], '', $in);
        return mb_strtolower(Normalizer::normalize(trim($in), Normalizer::FORM_KC));
    }

    private static function _addLink(Guild $guild, string $name, string $value): void
    {
        $query = $guild->client->db->prepare('INSERT INTO links (`idGUild`, `name`, `content`) VALUES(?, ?, ?);',
            ['integer', 'string', 'string']
        );
        $query->bindValue(1, $guild->id);
        $query->bindValue(2, $name);
        $query->bindValue(3, $value);
        $query->execute();
    }

    public static function deleteLink(EventData $data): ?PromiseInterface
    {
        $p = new Permission("p.links.delete", $data->huntress, true);
        $p->addMessageContext($data->message);
        if (!$p->resolve()) {
            return $p->sendUnauthorizedMessage($data->message->channel);
        }

        $links = self::getLinks($data->guild);
        $tp = self::_split($data->message->content);

        if (count($links) == 0) {
            return $data->message->reply("This server has no links to delete! Add some with `!link add`");
        }

        $k = self::normalizeKey($tp[2] ?? "");
        if (array_key_exists($k, $links)) {
            try {
                self::_deleteLink($data->guild, $k);
                return $data->message->reply("`$k` has been deleted");
            } catch (\Throwable $e) {
                return self::exceptionHandler($data->message, $e, false);
            }
        } else {
            $near = self::getNearest($k, $links);
            return $data->message->reply(sprintf("`%s` not found! Did you mean `!link delete %s`?", $k, $near));
        }
    }

    private static function _deleteLink(Guild $guild, string $name): void
    {
        $qb = $guild->client->db->createQueryBuilder();
        $qb->delete("links")->where("idGuild = ?")->andWhere("name = ?")->setParameter(1, $guild->id,
            "integer")->setParameter(2, $name, "string");
        $qb->execute();
    }

    private static function getNearest(string $in, array $opts): string
    {
        $opts = new Collection(array_keys($opts)); // im lazy ok

        $maybe = $opts->sortCustom(function ($a, $b) use ($in) {
            return self::similarity($in, $b) <=> self::similarity($in, $a);
        });
        return $maybe->first();
    }

    private static function similarity(string $a, string $b): int
    {
        $a = self::normalizeKey($a);
        $b = self::normalizeKey($b);
        if ($a == $b) {
            return 1000000;
        }
        return (similar_text($a, $b) * 1000) - levenshtein($a, $b, 1, 5, 10);
    }

    public static function help(EventData $data): ?PromiseInterface
    {
        $p = new Permission("p.links.show", $data->huntress, true);
        $p->addMessageContext($data->message);
        if (!$p->resolve()) {
            return $p->sendUnauthorizedMessage($data->message->channel);
        }
        return $data->message->reply(<<<HELP
**View all links**: `!link`
**Show a link**: `!link (name)`
**Add a link**: `!link add (name) (content)`
**Delete a link**: `!link delete (name) (content)`

Link names cannot have spaces, but (content) can. Links are unique per discord server.
HELP
        );
    }

    public static function showLink(EventData $data): ?PromiseInterface
    {
        $p = new Permission("p.links.show", $data->huntress, true);
        $p->addMessageContext($data->message);
        if (!$p->resolve()) {
            return $p->sendUnauthorizedMessage($data->message->channel);
        }

        $links = self::getLinks($data->guild);
        $tp = self::_split($data->message->content);

        if (count($links) == 0) {
            return $data->message->reply("This server has no links! Add some with `!link add`");
        }

        $k = self::normalizeKey($tp[1]);
        if (array_key_exists($k, $links)) {
            return $data->message->reply($links[$k]);
        } else {
            $near = self::getNearest($k, $links);
            return $data->message->reply(sprintf("`%s` not found! Did you mean `!link %s`?", $k, $near));
        }
    }
}
