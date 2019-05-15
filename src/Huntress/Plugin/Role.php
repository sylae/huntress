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
        $eh = EventListener::new()
            ->addCommand("role")
            ->addCommand("roles")
            ->setCallback([self::class, "roleEntry"]);
        $bot->eventManager->addEventListener($eh);
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

    public static function toggleRole(EventData $data, string $char): ?PromiseInterface
    {
        try {
            $roles = self::getValidOptions($data->message->member);
            $res = $roles->filter(function ($v) use ($char) {
                return mb_strtolower($char) == mb_strtolower($v->name);
            });
            if (count($res) > 0) {
                foreach ($res as $role) {
                    if ($data->message->member->roles->has($role)) {
                        return $data->message->member->removeRole($role)->then(function ($member) use ($data, $role) {
                            return $data->message->channel->send("Role removed: {$role->name}");
                        }, function ($error) use ($data, $role) {
                            return $data->message->channel->send("Unable to remove role  {$role->name}. Error: $error");
                        });
                    } else {
                        return $data->message->member->addRole($role)->then(function ($member) use ($data, $role) {
                            return $data->message->channel->send("Role added: {$role->name}");
                        }, function ($error) use ($data, $role) {
                            return $data->message->channel->send("Unable to add role  {$role->name}. Error: $error");
                        });

                    }
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

    public static function giveList(EventData $data): ?ExtendedPromiseInterface
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
        // todo: allow staff to bind.
        $roles = [];
        if ($member->guild->id == 118981144464195584) {
            $roles = [
                170401543684751360,
                544030356677066773,
                170665239581425665,
                555955570486673439,
                536088978525519872,
                426501305578553362,
                555955481194266636,
                224321349462523904,
                578170677555625984,
                578190941916102659,
            ];
        }
        return $member->guild->roles->filter(function ($v, $k) use ($roles) {
            return in_array($k, $roles);
        })->sortCustom(function ($a, $b) {
            return $b->position <=> $a->position;
        });
    }

    public static function similarity(string $a, string $b): int
    {
        $a = mb_strtolower($a);
        $b = mb_strtolower($b);
        if ($a == $b) {
            return 1000000;
        }
        return (similar_text($a, $b) * 1000) - levenshtein($a, $b, 1, 5, 10);
    }

}
