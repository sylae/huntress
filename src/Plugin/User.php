<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

/**
 * Simple builtin to show user information
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class User implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;

    public static function register(\Huntress\Bot $bot)
    {
        $bot->client->on(self::PLUGINEVENT_COMMAND_PREFIX . "user", [self::class, "process"]);
    }

    public static function process(\Huntress\Bot $bot, \CharlotteDunois\Yasmin\Models\Message $message): \React\Promise\ExtendedPromiseInterface
    {
        try {
            $user = self::parseGuildUser($message->guild, str_replace(self::_split($message->content)[0], "", $message->content)) ?? $message->member;

            $ur = [];
            foreach ($user->roles->sort(function ($a, $b) {
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
            ->addField("Color", $user->displayHexColor ?? "<unset>", true)
            ->addField("Roles", implode("\n", $ur))
            ->addField("Permissions", implode("\n", $perms), true)
            ->addField("Room Permissions", implode("\n", $roomPerms), true)
            ->setColor($user->displayColor)
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
