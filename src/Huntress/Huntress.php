<?php

/*
 * Copyright (c) 2019-2026 MisfitMaid and contributors.
 *
 * Use of this source code is governed by the MIT Non-AI license, which can be found in the LICENSE file.
 */

namespace Huntress;

use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\WebSockets\Event;
use Discord\WebSockets\Intents;
use Doctrine\DBAL\Connection;
use Monolog\ErrorHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\IntrospectionProcessor;
use Monolog\Registry;
use ReflectionClass;
use Throwable;

class Huntress extends Discord
{
    public EventManager $eventManager;
    public Connection $db;
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;

        parent::__construct([
            'token' => $this->config['botToken'],
            'intents' => Intents::getAllIntents(),
            'loadAllMembers' => true,
            'storeMessages' => true,
            'retrieveBans' => true,

            'logger' => $this->setupLogger(),
        ]);

        $this->eventManager = new EventManager($this);
        $this->registerBuiltinHooks();

        $classes = get_declared_classes();
        foreach ($classes as $class) {
            if (new ReflectionClass($class)->implementsInterface("Huntress\PluginInterface")) {
                $this->getLogger()->info("Loading plugin $class");

                /** @var PluginInterface $class */
                $class::register($this);
            }
        }

        DatabaseFactory::make($this, $this->config['database']);
        $this->db = DatabaseFactory::get();

        // legacy handlers
        $this->once('init', [$this, 'readyHandler']);
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

    private function setupLogger(): Logger
    {
        $l_console = new StreamHandler(STDOUT, $this->config['logLevel']);
        $l_console->setFormatter(new LineFormatter(null, null, true, true));
        $l_template = new Logger("Bot");
        $l_template->pushHandler($l_console);
        ErrorHandler::register($l_template);
        if ($this->config['logLevel'] == Level::Debug) {
            $l_template->pushProcessor(new IntrospectionProcessor());
            // $l_template->pushProcessor(new GitProcessor());
        }
        Registry::addLogger($l_template);
        return $l_template;
    }

    private function registerBuiltinHooks(): void
    {
        RSSProcessor::register($this);
        Permission::register($this);
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
