<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use \Huntress\Huntress;
use GetOpt\GetOpt;
use GetOpt\Operand;
use GetOpt\Command;
use GetOpt\ArgumentException;
use CharlotteDunois\Yasmin\Models\Message;

/**
 * Cauldron Cup / PCT Cup management
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class CauldronCup implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;
    const NOTE_PCTC = <<<NOTE
**Welcome to PCT Cup Season Two!**
As a reminder, please do not publicly share who you are competing with or what you are writing until the coordinators give you the okay. You can use this channel to ask your opponent or the coordinators any questions. You will have no less than **48 hours** to complete your snips and submit them for processing.

Your submission should be around **1k words**, no biggie if more or less but shoot for that. The link to submit snips is located in #pct-cup-green-room. Good luck!

Please note that competitors have permission to **pin** anything they might find useful. Please pin your characters once you've chosen them! :)

__What you need to do:__
1. Pick a character! Your opponent will do the same.
2. Write a snippet featuring the two characters within theme and including the emotion.
3. Submit your snippet within 48 hours of this post to the google form

Round One's **theme** is: *%s*
Your **match** is: *%s*
NOTE;
    const NOTE_CCUP = <<<NOTE
**Welcome to Cauldron Cup Season Three!**
As a reminder, please do not publicly share who you are competing with or what you are writing until the coordinators give you the okay. You can use this channel to ask your opponent or the coordinators any questions. You will have no less than **72 hours** to complete your snips and submit them for processing. *Note that if both competitors choose OCs, an additional 24 hours will be added to allow you time to familiarize yourself.*

Your submission should be around **1k words**, no biggie if more or less but shoot for that. The link to submit snips is pinned in <#565085613955612702>. Good luck!

Competitors have permission to pin anything they might find useful. Please pin your characters once you've chosen them! :)

__What you need to do:__
1. Pick a character! Your opponent will do the same.
2. Write a snippet featuring the two characters within theme and including the emotion.
3. Submit your snippet within 72 hours of this post to the form.

Please write your posts in Markdown format, with a blank line between paragraphs. Here's a cheatsheet: https://commonmark.org/help/

This round's **theme** is: *%s*
Your **match** is: *%s*
NOTE;

    public static function register(Huntress $bot)
    {
        $eh = \Huntress\EventListener::new()
            ->addCommand("ccup")
            ->addGuild(385678357745893376)
            ->setCallback([self::class, "cup"]);

        $bot->eventManager->addEventListener($eh);


        $eh2 = \Huntress\EventListener::new()
            ->addGuild(385678357745893376)
            ->addChannel(567713466148716544)
            ->addEvent("message")
            ->setCallback([self::class, "reposter"]);

        $bot->eventManager->addEventListener($eh2);
    }

    public static function reposter(\Huntress\EventData $data)
    {
        if ($data->message->author->id == $data->message->client->user->id) {
            return;
        }
        if ($data->channel->id != 567713466148716544) {
            return; // sanity check
        }
        return $data->channel->send($data->message->cleanContent)->then(function ($newmsg) use ($data) {
            return $data->message->delete();
        });
    }

    public static function cup(\Huntress\EventData $data)
    {
        if (is_null($data->message->member->roles->get(385680396555255809))) {
            return self::unauthorized($data->message);
        }
        try {
            $getOpt = new GetOpt();
            $getOpt->set(GetOpt::SETTING_SCRIPT_NAME, '!ccup');
            $getOpt->set(GetOpt::SETTING_STRICT_OPERANDS, true);
            $commands = [];
            $commands[] = Command::create('create',
                [self::class, 'create'])->setDescription('Create a match channel')->addOperands([
                (new Operand('genre', Operand::REQUIRED))->setValidation('is_string'),
                (new Operand('theme', Operand::REQUIRED))->setValidation('is_string'),
            ]);
            $commands[] = Command::create('summon',
                [self::class, 'summon'])->setDescription('Add a competitor to a match channel')->addOperands([
                (new Operand('user',
                    Operand::REQUIRED))->setValidation('is_string')->setDescription('The user you are adding, in a format Huntress can recognize.'),
            ]);
            $commands[] = Command::create('steal',
                [self::class, 'steal'])->setDescription('Claim a syl.ae/words post')->addOperands([
                (new Operand('title', Operand::REQUIRED))->setValidation('is_string'),
                (new Operand('entry', Operand::REQUIRED))->setValidation('is_string'),
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
        } catch (\Throwable $e) {
            return self::exceptionHandler($data->message, $e);
        }
    }

    public static function create(GetOpt $getOpt, Message $message)
    {
        $sf = \Huntress\Snowflake::format(\Huntress\Snowflake::generate());
        return $message->guild->createChannel([
            'name' => "ccup-secret-$sf",
            'type' => "text",
            'parent' => 391049778286559253,
        ], "Created on behalf of {$message->author->tag} from {$message->id}")->then(function (\CharlotteDunois\Yasmin\Models\TextChannel $channel) use ($message, $getOpt) {
            $channel->send(sprintf(self::NOTE_CCUP, $getOpt->getOperand("genre"), $getOpt->getOperand("theme")))->then(function (Message $m) {
                return $m->pin();
            });
            return self::send($message->channel, "<#{$channel->id}> :ok_hand:");
        }, function ($error) use ($message) {
            self::error($message, "Error", json_encode($error));
        });
    }

    public static function summon(GetOpt $getOpt, Message $message)
    {
        $user = self::parseGuildUser($message->guild, $getOpt->getOperand("user"));
        if (!$user instanceof \CharlotteDunois\Yasmin\Models\GuildMember) {
            return self::send($message->channel, "Could not recognize that user.");
        }
        return $message->channel->overwritePermissions($user, \CharlotteDunois\Yasmin\Models\Permissions::PERMISSIONS['VIEW_CHANNEL'] | \CharlotteDunois\Yasmin\Models\Permissions::PERMISSIONS['MANAGE_MESSAGES'], 0, "Created on behalf of {$message->author->tag}")
        ->then(function ($overwrites) use ($message, $user) {
            $message->delete();
            return self::send($message->channel, "$user come here.");
        }, function ($error) use ($message) {
            self::error($message, "Error", json_encode($error));
        });
    }

    public static function steal(GetOpt $getOpt, Message $message)
    {
        return self::send($message->channel, "Still working on it.");
    }
}
