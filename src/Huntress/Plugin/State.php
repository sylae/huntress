<?php

/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Yasmin\Models\Guild;
use CharlotteDunois\Yasmin\Models\Message;
use CharlotteDunois\Yasmin\Models\MessageEmbed;
use CharlotteDunois\Yasmin\Models\TextChannel;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface;

/**
 * Rules-read verification for the Masturbatorium.
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class State implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("state")
            ->setCallback([self::class, "process"]));
    }

    public static function process(EventData $data): ?PromiseInterface
    {
        $p = new Permission("p.moderation.state", $data->huntress, false);
        $p->addMessageContext($data->message);
        if (!$p->resolve()) {
            return $p->sendUnauthorizedMessage($data->message->channel);
        }

        $ctxt = self::arg_substr($data->message->content, 1, 1) ?? null;
        if (mb_strlen($ctxt) == 0) {
            return $data->message->reply("Usage: `!state [channel] then the rest of your message`");
        }

        if (is_null($x = self::channelMention($ctxt, $data->message->guild))) {
            $channel = $data->message->channel;
            $state = self::arg_substr($data->message->content, 1);
            $pic = $data->message->author->getAvatarURL();
        } else {
            $channel = $x;
            $state = self::arg_substr($data->message->content, 2);
            $pic = $data->message->guild->getIconURL();
        }
        $id = \Huntress\Snowflake::format(\Huntress\Snowflake::generate());

        if (mb_strlen($state) == 0) {
            return $data->message->reply("Usage: `!state [channel] then the rest of your message`");
        }

        $embed = new MessageEmbed();
        $embed->setTitle("MOD STATEMENT");
        $embed->setColor(0xd10000);
        $embed->setDescription($state);
        $embed->setTimestamp(time());
        $embed->setFooter("Mod Statement {$id}", $pic);

        $mentions = $data->message->mentions->users->map(fn($v) => (string)$v)->implode(null);
        if (mb_strlen($mentions) > 0) {
            $mentions = $mentions . " - ";
        }

        $notice = $mentions . "Please acknowledge (if appropriate to do so).";

        $promise = $channel->send($notice, ['embed' => $embed]);
        if ($channel->id != $data->message->channel->id) {
            $promise->then(function (Message $x) use ($data) {
                return $data->message->reply("Anonymous statement made in {$x->channel}!");
            });
        }
        return $promise;
    }

    private static function channelMention(string $text, Guild $guild): ?TextChannel
    {
        if (preg_match("/<#(\\d+)>/", $text, $matches)) {
            $ch = $guild->channels->resolve($matches[1]);
            if ($ch instanceof TextChannel) {
                return $ch;
            }
        }
        return null;
    }
}
