<?php
/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Collect\Collection;
use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\MessageEmbed;
use CharlotteDunois\Yasmin\Utils\MessageHelpers;
use Doctrine\DBAL\Schema\Schema;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\ExtendedPromiseInterface;
use React\Promise\PromiseInterface;
use Throwable;


/**
 * Very WIP system for role self-management
 */
class Role implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {

        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("role")
            ->addCommand("roles")
            ->setCallback([self::class, "roleEntry"])
        );

        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("bindrole")
            ->setCallback([self::class, "roleBind"])
        );

        $bot->eventManager->addEventListener(EventListener::new()
            ->addEvent("dbSchema")
            ->setCallback([self::class, "db"])
        );
    }

    public static function db(Schema $schema): void
    {
        $t = $schema->createTable("roles");
        $t->addColumn("idRole", "bigint", ["unsigned" => true]);
        $t->addColumn("idGuild", "bigint", ["unsigned" => true]);
        $t->setPrimaryKey(["idRole"]);
    }

    public static function roleEntry(EventData $data): ?ExtendedPromiseInterface
    {
        try {
            $args = self::_split($data->message->content);
            if (count($args) < 2) {
                return self::giveList($data);
            }

            $char = trim(str_replace($args[0], "", $data->message->content)); // todo: do this better
            return self::toggleRole($data, $char);
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true);
        }
    }

    private static function giveList(EventData $data): ?ExtendedPromiseInterface
    {
        try {
            $roles = self::getValidOptions($data->message->member);
            if ($roles->count() == 0) {
                return $data->message->channel->send("No roles found! Tell the server owner to bug my owner!");
            }

            $embed = new MessageEmbed();
            $embed->setTitle("Roles List - {$data->guild->name}")
                ->setDescription("Use `!role ROLE NAME` to toggle a role.\nDo **not** use an `@`!")
                ->setColor($data->message->member->getDisplayColor());

            $entries = $roles->map(function ($v) {
                return sprintf("%s (`!role %s`)", $v, $v->name);
            })->implode('val', PHP_EOL);

            $roles = MessageHelpers::splitMessage($entries,
                ['maxLength' => 1024]);
            $firstRole = true;
            foreach ($roles as $role) {
                $embed->addField($firstRole ? "Roles" : "Roles (cont.)", $role);
                $firstRole = false;
            }

            return $data->message->channel->send("", ['embed' => $embed]);
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true);
        }
    }

    private static function getValidOptions(GuildMember $member): Collection
    {

        $qb = $member->client->db->createQueryBuilder();
        $qb->select("*")->from("roles")->where('`idGuild` = ?')->setParameter(0, $member->guild->id, "integer");
        $res = $qb->execute()->fetchAll();
        $roles = array_column($res, 'idRole');

        return $member->guild->roles->filter(function ($v, $k) use ($roles) {
            return in_array($k, $roles);
        })->sortCustom(function ($a, $b) {
            return $b->position <=> $a->position;
        });
    }

    private static function toggleRole(EventData $data, string $char): ?PromiseInterface
    {
        try {
            $roles = self::getValidOptions($data->message->member);
            $res = $roles->first(function ($v) use ($char) {
                return mb_strtolower($char) == mb_strtolower($v->name);
            });
            if ($res instanceof \CharlotteDunois\Yasmin\Models\Role) {
                if ($data->message->member->roles->has($res->id)) {
                    return $data->message->member->removeRole($res)->then(function ($member) use ($data, $res) {
                        return $data->message->channel->send("Role removed: {$res->name}");
                    }, function ($error) use ($data, $res) {
                        return $data->message->channel->send("Unable to remove role  {$res->name}. Error: $error");
                    });
                } else {
                    return $data->message->member->addRole($res)->then(function ($member) use ($data, $res) {
                        return $data->message->channel->send("Role added: {$res->name}");
                    }, function ($error) use ($data, $res) {
                        return $data->message->channel->send("Unable to add role  {$res->name}. Error: $error");
                    });

                }
            } else {
                $maybe = $roles->sortCustom(function ($a, $b) use ($char) {
                    return self::similarity($char, $b->name) <=> self::similarity($char, $a->name);
                });
                if ($maybe->count() > 0) {
                    return $data->message->channel->send(sprintf("`%s` not found! Did you mean `!role %s`?", $char,
                        $maybe->first()->name));
                } else {
                    return $data->message->channel->send("No roles found! Tell the server owner to bug my owner!");
                }
            }
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true);
        }
    }

    private static function similarity(string $a, string $b): int
    {
        $a = mb_strtolower($a);
        $b = mb_strtolower($b);
        if ($a == $b) {
            return 1000000;
        }
        return (similar_text($a, $b) * 1000) - levenshtein($a, $b, 1, 5, 10);
    }

    public static function roleBind(EventData $data): ?ExtendedPromiseInterface
    {
        if (!in_array($data->message->author->id, $data->message->client->config['evalUsers'])) {
            return self::unauthorized($data->message);
        } // todo: HPM

        try {
            $args = self::_split($data->message->content);
            if (count($args) < 2 || !$data->guild->roles->has($args[1])) {
                return self::error($data->message, "Error", "Usage: `!bindrole ROLE_ID`.");
            }

            $role = $data->guild->roles->get($args[1]);

            if ($role->id == $role->guild->id) {
                return self::error($data->message, "Error", "`@everyone` is not a bindable role!");
            }

            self::addRole($role);
            return $data->message->channel->send("Role added to server bindings: {$role->name}");

        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true);
        }
    }

    private static function addRole(\CharlotteDunois\Yasmin\Models\Role $role)
    {
        $query = $role->client->db->prepare('REPLACE INTO roles (`idRole`, `idGuild`) VALUES(?, ?)',
            ['integer', 'integer']);
        $query->bindValue(1, $role->id);
        $query->bindValue(2, $role->guild->id);
        $query->execute();
    }

}
