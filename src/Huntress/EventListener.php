<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

/**
 * Description of EventListener
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class EventListener
{
    /**
     *
     * @var callable
     */
    private $callable;

    /**
     *
     * @var array
     */
    private $events = [];

    /**
     *
     * @var array
     */
    private $guilds = [];

    /**
     *
     * @var array
     */
    private $users = [];

    /**
     *
     * @var array
     */
    private $channels = [];

    /**
     *
     * @var array
     */
    private $messages = [];

    /**
     *
     * @var array
     */
    private $commands = [];

    /**
     *
     * @var array
     */
    private $roles = [];

    /**
     *
     * @var int
     */
    private $periodic = 0;

    /**
     * Convenience function to allow easier chaining.
     * @return \Huntress\EventListener
     */
    public static function new(): EventListener
    {
        return new self();
    }

    public function setCallback(callable $call)
    {
        $this->callable = $call;
        return $this;
    }

    public function getCallback(): callable
    {
        if (!is_callable($this->callable)) {
            throw new \Exception("Callback on EventListener not set!");
        }
        return $this->callable;
    }

    public function addChannel($id): EventListener
    {
        $this->addSnowflakeArg($id, $this->channels);
        return $this;
    }

    public function addGuild($id): EventListener
    {
        $this->addSnowflakeArg($id, $this->guilds);
        return $this;
    }

    public function addMessage($id): EventListener
    {
        $this->addSnowflakeArg($id, $this->messages);
        return $this;
    }

    public function addRole($id): EventListener
    {
        $this->addSnowflakeArg($id, $this->roles);
        return $this;
    }

    public function addUser($id): EventListener
    {
        $this->addSnowflakeArg($id, $this->users);
        return $this;
    }

    public function setPeriodic(int $seconds): EventListener
    {
        if ($seconds < 1) {
            throw new \InvalidArgumentException('$seconds must be at least 1.');
        }
        $this->addEvent("periodic");
        $this->periodic = $seconds;
        return $this;
    }

    public function getPeriodic(): int
    {
        return $this->periodic;
    }

    public function addCommand(string $command): EventListener
    {
        $this->addEvent("message");
        if (!in_array($command, $this->commands)) {
            $this->commands[] = $command;
        }
        return $this;
    }

    public function addEvent(string $event): EventListener
    {
        if (!in_array($event, $this->events)) {
            $this->events[] = $event;
        }
        return $this;
    }

    private function addSnowflakeArg($id, &$target): void
    {
        if (!$this->validateSnowflakeArg($id)) {
            throw new \InvalidArgumentException("Invalid event filter {$id}. Must be '*' or an ID.");
        }
        if (!in_array($id, $target)) {
            $target[] = $id;
        }
    }

    private function validateSnowflakeArg($snow): bool
    {
        if ($snow === "*") {
            return true;
        }
        if (!is_int($snow) || $snow < 0) {
            return false;
        }
        return true;
    }

    public function match(string $type, EventData $data = null): bool
    {
        if (!in_array($type, $this->events)) {
            return false;
        }

        // if there's no data and the event matches... well ok then
        if (is_null($data)) {
            return true;
        }

        if (count($this->commands) > 0) {
            if (!is_string($data->command) || mb_strlen($data->command) < 1 || !$this->pass($data->command, $this->commands)) {
                return false;
            }
        }
        if (count($this->channels) > 0) {
            if ($data->channel instanceof \CharlotteDunois\Yasmin\Interfaces\ChannelInterface) {
                if (!$this->pass($data->channel->id, $this->channels)) {
                    return false;
                }
            }
        }
        if (count($this->guilds) > 0) {
            if ($data->guild instanceof \CharlotteDunois\Yasmin\Models\Guild) {
                if (!$this->pass($data->guild->id, $this->guilds)) {
                    return false;
                }
            }
        }
        if (count($this->messages) > 0) {
            if ($data->message instanceof \CharlotteDunois\Yasmin\Models\Message) {
                if (!$this->pass($data->message->id, $this->messages)) {
                    return false;
                }
            }
        }
        if (count($this->users) > 0) {
            if ($data->user instanceof \CharlotteDunois\Yasmin\Models\User || $data->user instanceof \CharlotteDunois\Yasmin\Models\GuildMember) {
                if (!$this->pass($data->user->id, $this->users)) {
                    return false;
                }
            }
        }
        if (count($this->roles) > 0) {
            if ($data->user instanceof \CharlotteDunois\Yasmin\Models\GuildMember) {
                if (!$this->passRoles($data->user->roles, $this->roles)) {
                    return false;
                }
            }
        }
        return true;
    }

    private function pass($needle, array $haystack): bool
    {
        if (in_array("*", $haystack)) {
            return true;
        }
        if (in_array($needle, $haystack)) {
            return true;
        }
        return false;
    }

    private function passRoles(\CharlotteDunois\Yasmin\Models\RoleStorage $needles, array $haystack): bool
    {
        if (in_array("*", $haystack) || count($haystack) == 0) {
            return true;
        }
        foreach ($haystack as $role) {
            if ($needles->has($role)) {
                return true;
            }
        }
        return false;
    }
}
