<?php
/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;


use Carbon\Carbon;
use CharlotteDunois\Collect\Collection;
use CharlotteDunois\Yasmin\Models\Message;
use CharlotteDunois\Yasmin\Models\Permissions;
use CharlotteDunois\Yasmin\Models\TextChannel;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface;
use stdClass;

class Archive implements PluginInterface
{
    use PluginHelperTrait;

    const ARCHIVER_VERSION = "1.0.0";

    public static function register(Huntress $bot)
    {
        $eh = EventListener::new()
            ->addCommand("archive")
            ->setCallback([self::class, "archive"]);
        $bot->eventManager->addEventListener($eh);
    }

    public static function archive(EventData $data): PromiseInterface
    {
        try {
            if (!in_array($data->message->author->id, $data->message->client->config['evalUsers'])) {
                return $data->message->channel->send("Due to resource use, channel archiving is limited to bot owners only. Please let them know if you need a channel archived.");
            }

            $ch = self::getChannel(self::arg_substr($data->message->content, 1, 1), $data->huntress);
            if ($ch->permissionsFor($data->guild->me)->has(Permissions::PERMISSIONS['VIEW_CHANNEL']) && $ch->permissionsFor($data->guild->me)->has(Permissions::PERMISSIONS['READ_MESSAGE_HISTORY'])) {
                $data->message->channel->send("Beginning $ch (`#{$ch->name}`) archival...");
                return self::_archive($ch, $data);
            } else {
                return $data->message->channel->send("I don't have read access to that channel! Verify I have VIEW_CHANNEL and READ_MESSAGE_HISTORY and try again.");
            }


        } catch (\Throwable $e) {
            return self::exceptionHandler($data->message, $e);
        }
    }

    private static function getChannel(string $ch, Huntress $bot): TextChannel
    {
        if (preg_match("/<#(\\d+)>/", $ch, $matches)) {
            return $bot->channels->get($matches[1]);
        }
    }

    public static function _archive(TextChannel $ch, EventData $data, Collection $carry = null)
    {
        try {
            if (is_null($carry)) {
                $args = ['limit' => 100];
            } else {
                $args = ['before' => $carry->min('id'), 'limit' => 100];
            }

            return $ch->fetchMessages($args)->then(function ($msgs) use ($ch, $data, $carry) {
                try {
                    if ($msgs->count() == 0) {
                        $format = self::encodeMessages($carry);
                        $fname = "{$ch->id}_{$ch->name}.json";
                        if (strlen($format) > (2 ** 20)) {
                            $file = ['name' => $fname . ".gz", 'data' => gzencode($format)];
                        } else {
                            $file = ['name' => $fname, 'data' => $format];
                        }
                        file_put_contents("temp/" . $file['name'], $file['data']);
                        return $data->message->channel->send("Done! {$carry->count()} messages saved.", [
                            'files' => [$file],
                        ])->then(null, function ($e) use ($data) {
                            return $data->message->channel->send("Done! Upload failed but you can grab it out of " . getcwd() . "/temp/");
                        });
                    } else {
                        if (is_null($carry)) {
                            $carry = $msgs;
                        } else {
                            $carry = $carry->merge($msgs);
                            if ($carry->count() % 1000 == 0) {
                                $rate = $carry->count() / (time() - $data->message->createdTimestamp);
                                $data->message->channel->send(sprintf("Progress: %s messages (%s/sec)", $carry->count(),
                                    number_format($rate)));
                            }
                        }
                        return call_user_func([self::class, "_archive"], $ch, $data, $carry);
                    }
                } catch (\Throwable $e) {
                    return self::exceptionHandler($data->message, $e);
                }
            });
        } catch (\Throwable $e) {
            return self::exceptionHandler($data->message, $e);
        }
    }

    private static function encodeMessages(Collection $messages): string
    {

        /** @var TextChannel $channel */
        $channel = $messages->first()->channel;

        $payload = new stdClass();
        $payload->_version = self::ARCHIVER_VERSION;
        $payload->_retrieval = [
            'time' => Carbon::now()->toAtomString(),
            'user' => $channel->client->user->tag,
            'agent' => sprintf('sylae/huntress (%s)', VERSION),
        ];
        $payload->channel = new stdClass();
        $payload->urls = [];
        $payload->users = [];
        $payload->pins = [];

        $payload->channel->id = (int)$channel->id;
        $payload->channel->name = $channel->name;
        $payload->channel->parent = (int)$channel->parentID ?? null;
        $payload->channel->topic = $channel->topic;
        $payload->channel->isNSFW = $channel->nsfw;
        $payload->channel->created = self::stamp($channel->createdTimestamp);


        $payload->messages = array_values($messages->sortCustom(function (Message $a, Message $b) {
            return $a->id <=> $b->id;
        })->map(function (Message $v) use (&$payload) {
            $x = [
                'id' => (int)$v->id,
                'author' => (int)$v->author->id,
                'content' => $v->content,
                'edited' => self::stamp($v->editedTimestamp),
                'created' => self::stamp($v->createdTimestamp),
                'attachments' => [],
                'embeds' => $v->embeds,
            ];

            if ($v->webhookID) {
                $x['webhookName'] = $v->author->username;
            }

            if ($v->pinned) {
                $payload->pins[] = (int)$v->id;
            }

            foreach ($v->attachments as $att) {
                $payload->urls[$att->id] = $att->url;
                $xx = json_decode(json_encode($att)); // god christ i know, give me a fukken break lmao
                $xx->createdTimestamp = self::stamp($xx->createdTimestamp);
                $xx->id = (int)$xx->id;
                $x['attachments'][$att->id] = $xx;
            }
            $x['attachments'] = array_values($x['attachments']);

            if (!array_key_exists($v->author->id, $payload->users)) {
                $payload->users[$v->author->id] = [
                    'id' => (int)$v->author->id,
                    'tag' => $v->author->tag,
                    'nick' => is_null($v->member) ? $v->author->username : $v->member->displayName,
                    'av' => $v->author->getDisplayAvatarURL(),
                    'webhook' => (bool)$v->webhookID,
                ];
                $payload->urls[$v->author->id] = $v->author->getDisplayAvatarURL();
            }

            return (object)$x;
        })->all());
        return json_encode($payload);
    }

    private static function stamp($s)
    {
        if (is_int($s)) {
            return Carbon::createFromTimestampUTC($s)->toAtomString();
        }
        return $s;
    }
}
