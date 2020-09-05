<?php
/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
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
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\ExtendedPromiseInterface;
use React\Promise\PromiseInterface;
use Throwable;
use function Sentry\captureException;


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
            ->addCommand("inheritrole")
            ->setCallback([self::class, "roleInherit"])
        );

        $bot->eventManager->addEventListener(EventListener::new()
            ->addEvent("dbSchema")
            ->setCallback([self::class, "db"])
        );

        $bot->eventManager->addEventListener(EventListener::new()->setCallback([
            self::class,
            "pollInheritance",
        ])->setPeriodic(10));
    }

    public static function db(Schema $schema): void
    {
        $t = $schema->createTable("roles");
        $t->addColumn("idRole", "bigint", ["unsigned" => true]);
        $t->addColumn("idGuild", "bigint", ["unsigned" => true]);
        $t->setPrimaryKey(["idRole"]);

        $t2 = $schema->createTable("roles_inherit");
        $t2->addColumn("idGuild", "bigint", ["unsigned" => true]);
        $t2->addColumn("idRoleSource", "bigint", ["unsigned" => true]);
        $t2->addColumn("idRoleDest", "bigint", ["unsigned" => true]);
        $t2->setPrimaryKey(["idRoleSource", "idRoleDest"]);
    }

    public static function pollInheritance(Huntress $bot)
    {
        if (self::isTestingClient()) {
            $bot->log->debug("Not firing " . __METHOD__);
            return;
        }
        try {
            $qb = $bot->db->createQueryBuilder();
            $qb->select("*")->from("roles_inherit");
            foreach ($qb->execute()->fetchAll() as $row) {
                if (!$bot->guilds->has($row['idGuild'])) {
                    continue;
                }
                $guild = $bot->guilds->get($row['idGuild']);

                if ($guild->roles->has($row['idRoleSource']) && $guild->roles->has($row['idRoleDest'])) {
                    $guild->members->filter(function (GuildMember $v) use ($row) {
                        return $v->roles->has($row['idRoleSource']) && !$v->roles->has($row['idRoleDest']);
                    })->each(function (GuildMember $v) use ($row) {
                        $v->client->log->debug("{$v->user->tag} inherits {$row['idRoleDest']} from {$row['idRoleSource']}.");
                        $v->addRole($row['idRoleDest'], "Inherited from role <@&{$row['idRoleSource']}>");
                    });
                } else {
                    $bot->log->notice("Guild {$guild->name} has orphaned roles!");
                }
            }
        } catch (Throwable $e) {
            captureException($e);
            $bot->log->warning($e->getMessage(), ['exception' => $e]);
        }
    }

    public static function roleEntry(EventData $data): ?PromiseInterface
    {
        try {
            $p = new Permission("p.roles.toggle", $data->huntress, true);
            $p->addMessageContext($data->message);
            if (!$p->resolve()) {
                return $p->sendUnauthorizedMessage($data->message->channel);
            }
            $args = self::_split($data->message->content);
            if (count($args) < 2) {
                return self::giveList($data);
            }

            $char = trim(self::arg_substr($data->message->content, 1));
            if (in_array(mb_strtolower($char), ["landlord", "landlords"])) {
                return $data->message->channel->send("", ['files' => ['data/landlords.jpg']]);
            } else {
                return self::toggleRole($data, $char);
            }
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true);
        }
    }

    private static function giveList(EventData $data): ?PromiseInterface
    {
        try {
            $p = new Permission("p.roles.list", $data->huntress, true);
            $p->addMessageContext($data->message);
            if (!$p->resolve()) {
                return $p->sendUnauthorizedMessage($data->message->channel);
            }

            $roles = self::getValidOptions($data->message->member);
            $inherits = self::getInheritances($data->message->member);
            if ($roles->count() == 0 && $inherits->count() == 0) {
                return $data->message->channel->send("No roles found! Tell the server owner to bug my owner!");
            }

            $embed = new MessageEmbed();
            $embed->setTitle("Roles List - {$data->guild->name}")
                ->setColor($data->message->member->getDisplayColor());

            $entries = $roles->map(function ($v) {
                return sprintf("%s (`!role %s`)", $v, $v->name);
            })->implode('val', PHP_EOL);

            if (mb_strlen($entries) > 0) {
                $embed->setDescription("Use `!role ROLE NAME` to toggle a role.\nDo **not** use an `@`!");
                $roles = MessageHelpers::splitMessage($entries,
                    ['maxLength' => 1024]);
                $firstRole = true;
                foreach ($roles as $role) {
                    $embed->addField($firstRole ? "Roles" : "Roles (cont.)", $role);
                    $firstRole = false;
                }
            }

            $entries_i = $inherits->map(function ($v, $k) {
                $sources = implode(", ", array_map(function ($v) {
                    return "<@&{$v['idRoleSource']}>";
                }, $v));
                return sprintf("<@&%s> is inherited from %s", $k, $sources);
            })->implode('val', PHP_EOL);

            if (mb_strlen($entries_i) > 0) {
                $inheritance = MessageHelpers::splitMessage($entries_i,
                    ['maxLength' => 1024]);
                $firstInheritance = true;
                foreach ($inheritance as $i) {
                    $embed->addField($firstInheritance ? "Inherited Roles" : "Inherited Roles (cont.)", $i);
                    $firstInheritance = false;
                }
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

    private static function getInheritances(GuildMember $member): Collection
    {

        $qb = $member->client->db->createQueryBuilder();
        $qb->select("*")->from("roles_inherit")->where('`idGuild` = ?')->setParameter(0, $member->guild->id, "integer");
        $res = new Collection($qb->execute()->fetchAll());
        return $res->filter(function ($v) use ($member) {
            return $member->guild->roles->has($v['idRoleSource']) && $member->guild->roles->has($v['idRoleDest']);
        })->groupBy("idRoleDest");
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

    public static function roleInherit(EventData $data): ?PromiseInterface
    {
        try {
            $p = new Permission("p.roles.bind", $data->huntress, false);
            $p->addMessageContext($data->message);
            if (!$p->resolve()) {
                return $p->sendUnauthorizedMessage($data->message->channel);
            }

            $args = self::_split($data->message->content);
            if (count($args) < 3 || !$data->guild->roles->has($args[1]) || !$data->guild->roles->has($args[2])) {
                return self::error($data->message, "Error", "Usage: `!inheritrole SOURCE_ROLE_ID DEST_ROLE_ID`.");
            }

            $source = $data->guild->roles->get($args[1]);
            $dest = $data->guild->roles->get($args[2]);

            if ($source->id == $source->guild->id || $dest->id == $dest->guild->id) {
                return self::error($data->message, "Error", "`@everyone` is not a bindable role!");
            }

            self::addInheritance($source, $dest);
            return $data->message->channel->send("Role added to server role inheritance: Having `@{$source->name}` will add `@{$dest->name}`.");

        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, true);
        }
    }

    private static function addInheritance(
        \CharlotteDunois\Yasmin\Models\Role $source,
        \CharlotteDunois\Yasmin\Models\Role $dest
    ) {
        $query = $source->client->db->prepare('REPLACE INTO roles_inherit (`idRoleSource`, `idRoleDest`, `idGuild`) VALUES(?, ?, ?)',
            ['integer', 'integer', 'integer']);
        $query->bindValue(1, $source->id);
        $query->bindValue(2, $dest->id);
        $query->bindValue(3, $source->guild->id);
        $query->execute();
    }

    public static function roleBind(EventData $data): ?ExtendedPromiseInterface
    {
        try {
            $p = new Permission("p.roles.bind", $data->huntress, false);
            $p->addMessageContext($data->message);
            if (!$p->resolve()) {
                return $p->sendUnauthorizedMessage($data->message->channel);
            }

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
