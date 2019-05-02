<?php

/*
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

use CharlotteDunois\Collect\Collection;
use React\Promise\PromiseInterface as Promise;
use CharlotteDunois\Yasmin\Utils\URLHelpers;

/**
 * Description of EventManager
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class EventManager
{
    /**
     *
     * @var Huntress
     */
    private $huntress;

    /**
     *
     * @var Collection
     */
    private $events;

    public function __construct(Huntress $huntress)
    {
        $this->huntress = $huntress;
        $this->events   = new Collection();
        $this->huntress->log->addInfo("[HEM] Huntress EventManager initialized");
    }

    public function fire(string $type, $data = null): Promise
    {
        if ($data instanceof EventData) {
            $events = $this->returnMatchingEvents($type, $data);
        } else {
            $events = $this->returnMatchingEvents($type);
        }
        $this->huntress->log->debug("[HEM] Found " . $events->count() . " matching events.");
        $values = $events->map(function (EventListener $v, int $k) use ($data) {
            if (is_null($data)) {
                $data = $this->huntress;
            }
            return $v->getCallback()($data);
        });
        return \React\Promise\all($values);
    }

    private function returnMatchingEvents(string $type, EventData $data = null): Collection
    {
        return $this->events->filter(function ($v, $k) use ($type, $data) {
            return $v->match($type, $data);
        });
    }

    public function addEventListener(EventListener $listener): int
    {
        $id = $this->getEventID();
        $this->events->set($id, $listener);
        $this->huntress->log->debug("[HEM] Added event $id");
        return $id;
    }

    public function addURLEvent(string $url, int $interval, callable $callable): int
    {
        return $this->addEventListener(EventListener::new()->setCallback(function (Huntress $bot) use ($url, $callable) {
            try {
                return URLHelpers::resolveURLToData($url)->then(function (string $data) use ($bot, $callable) {
                    try {
                        return $callable($data, $bot);
                    } catch (\Throwable $e) {
                        \Sentry\captureException($e);
                        $bot->log->addWarning($e->getMessage(), ['exception' => $e]);
                    }
                });
            } catch (\Throwable $e) {
                \Sentry\captureException($e);
                $bot->log->addWarning($e->getMessage(), ['exception' => $e]);
            }
        })->setPeriodic($interval));
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
        $periodics = $this->events->filter(function ($v, $k) {
            return ($v->getPeriodic() > 0);
        })->groupBy(function ($v, $k) {
            return $v->getPeriodic();
        });
        foreach ($periodics as $interval => $events) {
            $timing = $interval / count($events);
            $this->huntress->log->debug("[HEM] Periodic interval {$interval}s has " . count($events) . " slots.");
            $this->huntress->loop->addPeriodicTimer($timing, function () use ($interval, $events) {
                static $phase = [];
                if (!array_key_exists($interval, $phase)) {
                    $phase[$interval] = 0;
                }
                $fire = $phase[$interval] % count($events);
                $this->huntress->log->debug("[HEM] Firing periodic {$interval}s phase $fire/" . count($events));
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
                // provides: guild channel
                $data          = new EventData;
                $data->channel = $args[0];
                $data->guild   = $args[0]->getGuild();
                break;
            case "roleCreate":
            case "roleDelete":
            case "roleUpdate":
                // provides: guild role
                $data          = new EventData;
                $data->role    = $args[0];
                $data->guild   = $args[0]->guild;
                break;
            case "message":
            case "messageDelete":
            case "messagereactionAdd":
            case "messageReactionRemove":
            case "messageUpdate":
                // provides: guild channel user message command
                $data          = new EventData;
                if ($args[0] instanceof \CharlotteDunois\Yasmin\Models\MessageReaction) {
                    $message = $args[0]->message;
                } elseif ($args[0] instanceof \CharlotteDunois\Yasmin\Models\Message) {
                    $message = $args[0];
                } else {
                    throw new \Exception("Unknown argument type passed to eventHandler");
                }
                $data->guild   = $message->guild;
                $data->channel = $message->channel;
                $data->user    = $message->author;
                $data->message = $message;
                $match         = [];
                if (preg_match("/^!(\w+)(\s|$)/", $message->content, $match)) {
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
                if ($args[0] instanceof \CharlotteDunois\Yasmin\Models\GuildMember) {
                    $data->user  = $args[0];
                    $data->guild = $args[0]->guild;
                } else {
                    $data->user  = $args[1];
                    $data->guild = $args[0];
                }
                break;
            case "guildCreate":
            case "guildDelete":
            case "guildUnavailable":
            case "guildUpdate":
                // provides: guild
                $data        = new EventData;
                $data->guild = $args[0];
                break;
            case "presenceUpdate":
            case "userUpdate":
                // provides: user
                $data        = new EventData;
                if ($args[0] instanceof \CharlotteDunois\Yasmin\Models\Presence) {
                    $data->user = $args[0]->user;
                } else {
                    $data->user = $args[0];
                }
                break;
            case "voiceStateUpdate";
                $data          = new EventData;
                // provides: guild channel user
                $data->user    = $args[0];
                $data->guild   = $args[0]->guild;
                $data->channel = $args[0]->voiceChannel;
                break;
            case "ready":
            default:
                $data          = null;
                break;
        }
        $this->huntress->log->debug("[HEM] Received event $yasminType", ['data' => $data]);
        $this->fire($yasminType, $data);
    }
}
