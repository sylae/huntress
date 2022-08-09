<?php

/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use Carbon\Carbon;
use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\Message;
use CharlotteDunois\Yasmin\Models\Permissions;
use CharlotteDunois\Yasmin\Utils\MessageHelpers;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\ExtendedPromiseInterface as Promise;
use React\Promise\PromiseInterface;
use Throwable;

class User implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "user", [self::class, "process"]);

        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addCommand("roster")
                ->setCallback([self::class, "roster"])
        );

        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addCommand("av")
                ->setCallback([self::class, "av"])
        );
    }

    public static function roster(EventData $data): ?PromiseInterface
    {
        if (is_null($data->guild)) {
            return $data->message->reply("Must be run in a server, not in DMs.");
        }

        $p = new Permission("p.userutils.roster", $data->huntress, true);
        $p->addMessageContext($data->message);
        if (!$p->resolve()) {
            return $p->sendUnauthorizedMessage($data->message->channel);
        }

        $roleStr = self::arg_substr($data->message->content, 1) ?? false;
        if (!$roleStr) {
            return $data->message->reply("Usage: `!roster ROLE`");
        }

        $role = self::parseRole($data->guild, $roleStr);
        if (is_null($role)) {
            return $data->message->reply("Unknown role. Type it out, @ it, or paste in the role ID.");
        }

        $nameWidth = 0;
        $roleLines = $data->guild->members->filter(function (GuildMember $v) use ($role) {
            return $v->roles->has($role->id);
        })->sortCustom(function (GuildMember $a, GuildMember $b) {
            return $a->joinedTimestamp <=> $b->joinedTimestamp;
        })->map(function (GuildMember $v) use (&$nameWidth) {
            $name = sprintf("%s (%s)", $v->displayName, $v->user->tag);
            $time = sprintf(
                "%s (%s)",
                Carbon::createFromTimestamp($v->joinedTimestamp)->toAtomString(),
                Carbon::createFromTimestamp($v->joinedTimestamp)->shortRelativeToNowDiffForHumans(Carbon::now(), 2)
            );
            $nameWidth = max(mb_strwidth($name), $nameWidth);
            return [$name, $time];
        });

        $x = [];
        $x[] = "```";
        foreach ($roleLines as $v) {
            $x[] = sprintf(
                "%s  %s",
                self::mb_str_pad($v[0], $nameWidth, " ", STR_PAD_RIGHT),
                $v[1]
            );
        }
        $x[] = "```";

        return $data->message->reply(
            implode(PHP_EOL, $x),
            ['split' => ['before' => '```json' . PHP_EOL, 'after' => '```']]
        );
    }

    /**
     * Multibyte String Pad
     *
     * Functionally, the equivalent of the standard str_pad function, but is capable of successfully padding multibyte
     * strings.
     *
     * @link    https://gist.github.com/rquadling/c9ff12755fc412a6f0d38f6ac0d24fb1
     * @license unknown
     *
     * @param string $input The string to be padded.
     * @param int $length The length of the resultant padded string.
     * @param string $padding The string to use as padding. Defaults to space.
     * @param int $padType The type of padding. Defaults to STR_PAD_RIGHT.
     * @param string $encoding The encoding to use, defaults to UTF-8.
     *
     * @return string A padded multibyte string.
     */
    public static function mb_str_pad(
        string $input,
        int $length,
        string $padding = ' ',
        int $padType = STR_PAD_RIGHT,
        string $encoding = 'UTF-8'
    ): string {
        $result = $input;
        if (($paddingRequired = $length - mb_strlen($input, $encoding)) > 0) {
            switch ($padType) {
                case STR_PAD_LEFT:
                    $result =
                        mb_substr(str_repeat($padding, $paddingRequired), 0, $paddingRequired, $encoding) .
                        $input;
                    break;
                case STR_PAD_RIGHT:
                    $result =
                        $input .
                        mb_substr(str_repeat($padding, $paddingRequired), 0, $paddingRequired, $encoding);
                    break;
                case STR_PAD_BOTH:
                    $leftPaddingLength = floor($paddingRequired / 2);
                    $rightPaddingLength = $paddingRequired - $leftPaddingLength;
                    $result =
                        mb_substr(str_repeat($padding, $leftPaddingLength), 0, $leftPaddingLength, $encoding) .
                        $input .
                        mb_substr(str_repeat($padding, $rightPaddingLength), 0, $rightPaddingLength, $encoding);
                    break;
            }
        }

        return $result;
    }

    public static function process(Huntress $bot, Message $message): ?Promise
    {
        try {
            $user = self::parseGuildUser(
                    $message->guild,
                    str_replace(self::_split($message->content)[0], "", $message->content)
                ) ?? $message->member;

            $ur = [];
            foreach (
                $user->roles->sortCustom(function ($a, $b) {
                    return $b->position <=> $a->position;
                }) as $id => $role
            ) {
                if ($role->name == "@everyone") {
                    continue;
                }
                $ur[] = "<@&{$id}> ({$id})";
            }
            if (count($ur) == 0) {
                $ur[] = "<no roles>";
            }

            $perms = self::permissionsToArray($user->permissions);
            $roomPerms = self::permissionsToArray($user->permissionsIn($message->channel));

            $embed = self::easyEmbed($message);
            $embed->setTitle("User Information")
                //         ->setDescription(substr("```json\n" . json_encode($user, JSON_PRETTY_PRINT) . "\n```", 0, 2048))
                ->addField("ID", $user->id, true)
                ->addField("Username", $user->user->username . "#" . $user->user->discriminator, true)
                ->addField("Nick", $user->nickname ?? "<unset>", true)
                ->addField("Color", $user->displayHexColor ?? "<unset>", true);

            $roles = MessageHelpers::splitMessage(
                implode("\n", $ur),
                ['maxLength' => 1024]
            );
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
        } catch (Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }

    private static function permissionsToArray(Permissions $p): array
    {
        $perm = [];
        foreach (Permissions::PERMISSIONS as $name => $mask) {
            if ($p->has($name)) {
                $perm[] = $p->resolveToName($mask);
            }
        }
        return $perm;
    }

    public static function av(EventData $data): ?Promise
    {
        try {
            $user = self::parseGuildUser(
                    $data->guild,
                    self::arg_substr($data->message->content, 1) ?? ""
                ) ?? $data->message->member;

            return $data->message->reply($user->displayName . "'s av: " . $user->getDisplayAvatarURL());
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e);
        }
    }
}
