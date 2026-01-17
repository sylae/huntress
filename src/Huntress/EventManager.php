<?php

/*
 * Copyright (c) 2019-2026 MisfitMaid and contributors.
 *
 * Use of this source code is governed by the MIT Non-AI license, which can be found in the LICENSE file.
 */

namespace Huntress;

use Discord\Helpers\Collection;
use Discord\Http\Request;
use Discord\Parts\Channel\GuildText;
use Discord\Parts\Channel\Message;
use Discord\Parts\Channel\Reaction;
use Discord\Parts\User\Member;
use Exception;
use React\Promise\PromiseInterface as Promise;
use Throwable;
use function React\Promise\all;

class EventManager
{
    private Huntress $huntress;

    private Collection $events;

    public function __construct(Huntress $huntress)
    {
        $this->huntress = $huntress;
        $this->events = new Collection();
        $this->huntress->getLogger()->info("[HEM] Huntress EventManager initialized");
    }

    public function addURLEvent(string $url, int $interval, callable $callable): int
    {
        return $this->addEventListener(EventListener::new()->setCallback(function (Huntress $bot) use (
            $url,
            $callable
        ) {
            try {
                return $this->huntress->getHttpClient()->get($url)->then(function (Request $data) use (
                    $bot,
                    $callable
                ) {
                    try {
                        return $callable($data->getContent(), $bot);
                    } catch (Throwable $e) {
                        $bot->getLogger()->warning($e->getMessage(), ['exception' => $e]);
                    }
                });
            } catch (Throwable $e) {
                $bot->getLogger()->warning($e->getMessage(), ['exception' => $e]);
            }
        })->setPeriodic($interval));
    }

    public function addEventListener(EventListener $listener): int
    {
        $id = $this->getEventID();
        $this->events->set($id, $listener);
        $this->huntress->getLogger()->debug("[HEM] Added event $id");
        return $id;
    }

    private function getEventID(): int
    {
        while (true) {
            $id = random_int(PHP_INT_MIN, PHP_INT_MAX);
            if ($this->events->has($id)) {
                continue;
            } else {
                return $id;
            }
        }
    }

    public function initializePeriodics()
    {
        $periodics = [];
        /** @var EventListener $event */
        foreach ($this->events as $id => $event) {
            $p = $event->getPeriodic();
            if ($p <= 0) {
                continue;
            }

            if (!array_key_exists($p, $periodics)) {
                $periodics[$p] = [];
            }
            $periodics[$p][$id] = $event;
        }
        foreach ($periodics as $interval => $events) {
            $timing = $interval / count($events);
            $this->huntress->getLogger()->debug("[HEM] Periodic interval {$interval}s has " . count($events) . " slots.");
            $this->huntress->getLoop()->addPeriodicTimer($timing, function () use ($interval, $events) {
                static $phase = [];
                if (!array_key_exists($interval, $phase)) {
                    $phase[$interval] = 0;
                }
                $fire = $phase[$interval] % count($events);
                $this->huntress->getLogger()->debug("[HEM] Firing periodic {$interval}s phase $fire/" . count($events));
                $events[$fire]->getCallback()($this->huntress);
                $phase[$interval]++;
            });
        }
    }

    public function yasminEventHandler(string $yasminType, array $args)
    {
        switch ($yasminType) {
            case "channelCreate":
            case "channelDelete":
            case "channelPinsUpdate":
            case "channelUpdate":
                // provides: ?guild channel
                $data = new EventData;
                $data->channel = $args[0];
                if ($data->channel instanceof GuildText) {
                    $data->guild = $args[0]->getGuild();
                }
                break;
            case "roleCreate":
            case "roleDelete":
            case "roleUpdate":
                // provides: guild role
                $data = new EventData;
                $data->role = $args[0];
                $data->guild = $args[0]->guild;
                break;
            case "message":
            case "messageDelete":
            case "messagereactionAdd":
            case "messageReactionRemove":
            case "messageUpdate":
                // provides: guild channel user message command
                $data = new EventData;
                if ($args[0] instanceof Reaction) {
                    $message = $args[0]->message;
                } elseif ($args[0] instanceof Message) {
                    $message = $args[0];
                } else {
                    throw new Exception("Unknown argument type passed to eventHandler");
                }
                $data->guild = $message->guild ?? null;
                $data->channel = $message->channel;
                $data->user = $message->author;
                $data->message = $message;
                $match = [];
                if (preg_match("/^!(.+?)(\s|$)/", $message->content, $match)) {
                    $data->command = $match[1];
                }
                break;

            case "guildBanAdd":
            case "guildBanRemove":
            case "guildMemberAdd":
            case "guildMemberRemove":
            case "guildMemberUpdate":
                // provides: guild user
                $data = new EventData;
                if ($args[0] instanceof Member) {
                    $data->user = $args[0];
                    $data->guild = $args[0]->guild;
                } else {
                    $data->user = $args[1];
                    $data->guild = $args[0];
                }
                break;
            case "guildCreate":
            case "guildDelete":
            case "guildUnavailable":
            case "guildUpdate":
                // provides: guild
                $data = new EventData;
                $data->guild = $args[0];
                break;
            case "userUpdate":
                // provides: user
                $data = new EventData;
                $data->user = $args[0];
                break;
            case "voiceStateUpdate";
                $data = new EventData;
                // provides: guild channel user
                $data->user = $args[0];
                $data->guild = $args[0]->guild;
                $data->channel = $args[0]->voiceChannel;
                break;
            case "ready":
            default:
                $data = null;
                break;
        }
        if ($data instanceof EventData) {
            $data->huntress = $this->huntress;
        }
        $this->huntress->getLogger()->debug("[HEM] Received event $yasminType", ['data' => $data]);
        $this->fire($yasminType, $data);
    }

    public function fire(string $type, $data = null): Promise
    {
        if ($data instanceof EventData) {
            $events = $this->returnMatchingEvents($type, $data);
        } else {
            $events = $this->returnMatchingEvents($type);
        }
        $this->huntress->getLogger()->debug("[HEM] Found " . $events->count() . " matching events.");
        $values = $events->map(function (EventListener $v) use ($data) {
            if (is_null($data)) {
                $data = $this->huntress;
            }
            return $v->getCallback()($data);
        });
        return all($values);
    }

    private function returnMatchingEvents(string $type, EventData $data = null): Collection
    {
        return $this->events->filter(function ($v) use ($type, $data) {
            return $v->match($type, $data);
        });
    }
}
