<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Yasmin\Models\CategoryChannel;
use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\Message;
use CharlotteDunois\Yasmin\Models\Permissions;
use CharlotteDunois\Yasmin\Models\VoiceChannel;
use GetOpt\ArgumentException;
use GetOpt\Command;
use GetOpt\GetOpt;
use GetOpt\Operand;
use GetOpt\Option;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Huntress\Snowflake;
use React\Promise\PromiseInterface;
use Throwable;

/**
 * Games server!
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class BeholdersBasement implements PluginInterface
{
    use PluginHelperTrait;

    const MODS = 619043961566134273;

    public static function register(Huntress $bot)
    {
        $eh = EventListener::new()
            ->addCommand("bb")
            ->addGuild(619043630187020299)
            ->setCallback([self::class, "commandListener"]);
        $bot->eventManager->addEventListener($eh);
        $bot->on("voiceStateUpdate", [self::class, "voiceStateHandler"]);
    }


    public static function voiceStateHandler(
        GuildMember $new,
        ?GuildMember $old
    ) {
        if ($new->guild->id == 619043630187020299 && $new->voiceChannel instanceof VoiceChannel) {
            if (is_null($new->roles->get(629830440588541962))) {
                $new->addRole(629830440588541962)->then(function () use ($new) {
                    self::send($new->guild->channels->get(624844253046112256),
                        "<@{$new->id}>, I'm going to give you the DJ role, since you're joining a voice chat.");
                });
            }
        }
    }


    public static function commandListener(EventData $data)
    {
        try {
            $getOpt = new GetOpt();
            $getOpt->set(GetOpt::SETTING_SCRIPT_NAME, '!bb');
            $getOpt->set(GetOpt::SETTING_STRICT_OPERANDS, true);
            $commands = [];
            $commands[] = Command::create('create',
                [self::class, 'create'])->setDescription('Create a new game')->addOperands([
                (new Operand('gm',
                    Operand::REQUIRED))->setValidation('is_string')->setDescription("The GM for the match, in Huntress-readable format."),
            ])->addOptions([
                (new Option("p", "private",
                    GetOpt::NO_ARGUMENT))->setDescription("Only allow access to players and staff."),
            ]);
            $commands[] = Command::create('add',
                [self::class, 'summon'])->setDescription('Add a player (or bot) to this game\'s role.')->addOperands([
                (new Operand('user',
                    Operand::REQUIRED))->setValidation('is_string')->setDescription("The user to add, in Huntress-readable format."),
            ]);
            $getOpt->addCommands($commands);
            try {
                $args = substr(strstr($data->message->content, " "), 1);
                $getOpt->process((string) $args);
            } catch (ArgumentException $exception) {
                return self::send($data->message->channel, $getOpt->getHelpText());
            }
            $command = $getOpt->getCommand();
            if (is_null($command)) {
                return self::send($data->message->channel, $getOpt->getHelpText());
            }
            return call_user_func($command->getHandler(), $getOpt, $data->message);
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e);
        }
    }

    public static function create(GetOpt $getOpt, Message $message): PromiseInterface
    {
        if (!$message->member->roles->has(self::MODS)) {
            return self::unauthorized($message);
        }
        $gm = self::parseGuildUser($message->guild, $getOpt->getOperand("gm"));
        if (!$gm instanceof GuildMember) {
            return self::error($message, "Invalid user",
                "I couldn't figure out who that is. Try using their tag or @ing them?");
        }
        return self::createGameAndRoom($gm, $message, (bool) $getOpt->getOption("private"));
    }

    private static function createGameAndRoom(GuildMember $gm, Message $message, bool $private = false): PromiseInterface
    {
        try {
            $id = Snowflake::generate();

            // create role
            return $message->guild->createRole([
                'name' => 'game-' . Snowflake::format($id),
                'mentionable' => true,
                'color' => random_int(0x0, 0xffffff),
            ], "Created on behalf of {$message->author->tag} from {$message->getJumpURL()}"
            )->then(function (\CharlotteDunois\Yasmin\Models\Role $role) use ($message, $gm, $private, $id) {
                // create channel
                return $message->guild->createChannel([
                    'name' => "game-" . Snowflake::format($id),
                    'type' => "category",
                ], "Created on behalf of {$message->author->tag} from {$message->getJumpURL()}")->then(function (
                    CategoryChannel $channel
                ) use ($message, $gm, $private, $role) {
                    try {
                        // set permissions
                        $perms = [];
                        if ($private) {
                            $perms[] = [
                                'type' => 'role', 'id' => $message->guild->id,
                                'deny' => Permissions::PERMISSIONS['VIEW_CHANNEL'],
                            ];
                        }
                        $perms[] = [
                            'type' => 'member', 'id' => $gm->id,
                            'allow' => Permissions::PERMISSIONS['VIEW_CHANNEL'] | Permissions::PERMISSIONS['MANAGE_MESSAGES'] | Permissions::PERMISSIONS['MANAGE_CHANNELS'],
                        ];
                        $perms[] = [
                            'type' => 'role', 'id' => self::MODS, 'allow' => Permissions::PERMISSIONS['VIEW_CHANNEL'],
                        ];
                        $perms[] = [
                            'type' => 'role', 'id' => $role->id, 'allow' => Permissions::PERMISSIONS['VIEW_CHANNEL'],
                        ];

                        $channel->setPermissionOverwrites($perms,
                            "Created on behalf of {$message->author->tag} from {$message->getJumpURL()}");
                        return $message->channel->send("<@&{$role->id}> and matching category have been added. Please rename them at your leisure.")->then(function ($m2) use ($role, $gm) {
                            return $gm->addRole($role);
                        });
                    } catch (Throwable $e) {
                        self::exceptionHandler($message, $e);
                    }

                }, function ($error) use ($message) {
                    self::error($message, "Error", json_encode($error));
                });
            });
        } catch (Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }

    public static function summon(GetOpt $getOpt, Message $message): PromiseInterface
    {
        return $message->channel->send("WIP. Please @ a mod to add a player to your game. Sorry for the inconvenience!");
    }
}
