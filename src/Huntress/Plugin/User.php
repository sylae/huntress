<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use \Huntress\Huntress;
use \React\Promise\ExtendedPromiseInterface as Promise;

/**
 * Simple builtin to show user information
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class User implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "user", [self::class, "process"]);
    }

    public static function process(Huntress $bot, \CharlotteDunois\Yasmin\Models\Message $message): ?Promise
    {
        try {
            $user = self::parseGuildUser($message->guild, str_replace(self::_split($message->content)[0], "", $message->content)) ?? $message->member;

            $ur = [];
            foreach ($user->roles->sortCustom(function ($a, $b) {
                return $b->position <=> $a->position;
            }) as $id => $role) {
                if ($role->name == "@everyone") {
                    continue;
                }
                $ur[] = "<@&{$id}> ({$id})";
            }
            if (count($ur) == 0) {
                $ur[] = "<no roles>";
            }

            $perms     = self::permissionsToArray($user->permissions);
            $roomPerms = self::permissionsToArray($user->permissionsIn($message->channel));

            $embed = self::easyEmbed($message);
            $embed->setTitle("User Information")
            //         ->setDescription(substr("```json\n" . json_encode($user, JSON_PRETTY_PRINT) . "\n```", 0, 2048))
            ->addField("ID", $user->id, true)
            ->addField("Username", $user->user->username . "#" . $user->user->discriminator, true)
            ->addField("Nick", $user->nickname ?? "<unset>", true)
            ->addField("Color", $user->displayHexColor ?? "<unset>", true);

            $roles     = \CharlotteDunois\Yasmin\Utils\MessageHelpers::splitMessage(implode("\n", $ur), ['maxLength' => 1024]);
            $firstRole = true;
            foreach ($roles as $role) {
                $embed->addField($firstRole ? "Roles" : "Roles (cont.)", $role);
                $firstRole = false;
            }
            $embed->addField("Permissions", implode("\n", $perms), true)
            ->addField("Room Permissions", implode("\n", $roomPerms), true)
            ->setColor($user->getDisplayColor())
            ->setThumbnail($user->user->getAvatarURL());
            return self::send($message->channel, "", ['embed' => $embed]);
        } catch (\Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }

    private static function permissionsToArray(\CharlotteDunois\Yasmin\Models\Permissions $p): array
    {
        $perm = [];
        foreach (\CharlotteDunois\Yasmin\Models\Permissions::PERMISSIONS as $name => $mask) {
            if ($p->has($name)) {
                $perm[] = $p->resolveToName($mask);
            }
        }
        return $perm;
    }
}
