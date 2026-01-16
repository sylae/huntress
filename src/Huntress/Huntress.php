<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\WebSockets\Event;
use Discord\WebSockets\Intents;
use Doctrine\DBAL\Connection;
use ReflectionClass;
use Throwable;

/**
 * This is the main Huntress class, mostly backend stuff tbh.
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class Huntress extends Discord
{
    protected array $config;
    public EventManager $eventManager;
    public Connection $db;

    public function __construct(array $config)
    {
        $this->config = $config;

        parent::__construct([
            'token' => $this->config['botToken'],
            'intents' => Intents::getAllIntents(),
            'loadAllMembers' => true,
        ]);

        $this->eventManager = new EventManager($this);
        $this->registerBuiltinHooks();

        $classes = get_declared_classes();
        foreach ($classes as $class) {
            if (new ReflectionClass($class)->implementsInterface("Huntress\PluginInterface")) {
                $this->getLogger()->info("Loading plugin $class");
                $class::register($this);
            }
        }

        DatabaseFactory::make($this, $this->config['database']);
        $this->db = DatabaseFactory::get();

        // legacy handlers
        $this->once('ready', [$this, 'readyHandler']);
        $this->on('message', [$this, 'messageHandler']);

        $dpEvents = new ReflectionClass(Event::class);
        foreach ($dpEvents->getConstants() as $v) {
            switch ($v) {
                case "raw":
                case "reconnect":
                case "disconnect":
                case Event::PRESENCE_UPDATE: // jesus god stop spamming this
                    continue 2;
                default:
                    $handler = function (...$args) use ($v) {
                        $this->eventManager->yasminEventHandler($v, $args);
                    };
                    break;
            }
            $this->on($v, $handler);
        }
    }

    private function registerBuiltinHooks(): void
    {
        // RSSProcessor::register($this);
        // Permission::register($this);
    }

    public function start(): void
    {
        $this->run();
    }

    public function readyHandler(): void
    {
        $this->getLogger()->info("Logged in as {$this->user->username} ({$this->user->id})");
        $this->eventManager->initializePeriodics();
        $this->emit(PluginInterface::PLUGINEVENT_READY, [$this]);
    }

    public function messageHandler(Message $message): void
    {
        $tag = ($message->guild->name ?? false) ? $message->guild->name . " #" . $message->channel->name : "DM";
        $this->getLogger()->info('[' . $tag . '] ' . $message->author->username . ': ' . $message->content);
        $preg = "/^!(\w+)(\s|$)/";
        $match = [];
        try {
            try {
                $this->emit(PluginInterface::PLUGINEVENT_MESSAGE, [$this, $message]);
            } catch (Throwable $e) {
                $this->getLogger()->warning("Uncaught Plugin exception!", ['exception' => $e]);
            }
            if (preg_match($preg, $message->content, $match)) {
                try {
                    $this->emit(PluginInterface::PLUGINEVENT_COMMAND_PREFIX . $match[1], [$this, $message]);
                } catch (Throwable $e) {
                    $this->getLogger()->warning("Uncaught Plugin exception!", ['exception' => $e]);
                }
            }
        } catch (Throwable $e) {
            $this->getLogger()->warning("Uncaught message processing exception!", ['exception' => $e]);
        }
    }

}
