<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

use CharlotteDunois\Yasmin\Client;
use CharlotteDunois\Yasmin\Models\Message;
use Doctrine\DBAL\Connection;
use Monolog\ErrorHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\GitProcessor;
use Monolog\Processor\IntrospectionProcessor;
use Monolog\Registry;
use React\EventLoop\LoopInterface;
use ReflectionClass;
use Throwable;
use function Sentry\captureException;

/**
 * This is the main Huntress class, mostly backend stuff tbh.
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class Huntress extends Client
{
    /**
     *
     * @var Logger
     */
    public $log;

    /**
     *
     * @var LoopInterface
     */
    public $loop;

    /**
     *
     * @var array
     */
    public $config;

    /**
     *
     * @var EventManager
     */
    public $eventManager;

    /**
     *
     * @var Connection
     */
    public $db;

    public function __construct(array $config, LoopInterface $loop)
    {
        $this->log = $this->setupLogger();
        $this->config = $config;
        $this->eventManager = new EventManager($this);
        $this->registerBuiltinHooks();

        parent::__construct([], $loop);

        $classes = get_declared_classes();
        foreach ($classes as $class) {
            if ((new ReflectionClass($class))->implementsInterface("Huntress\PluginInterface")) {
                $this->log->info("Loading plugin $class");
                $class::register($this);
            }
        }

        DatabaseFactory::make($this);
        $this->db = DatabaseFactory::get();

        // legacy handlers
        $this->once('ready', [$this, 'readyHandler']);
        $this->on('message', [$this, 'messageHandler']);

        $yasminEvents = new ReflectionClass('\CharlotteDunois\Yasmin\ClientEvents');
        foreach ($yasminEvents->getMethods() as $method) {
            switch ($method->name) {
                case "raw":
                case "reconnect":
                case "disconnect":
                case "presenceUpdate": // jesus god stop spamming this
                    continue 2;
                case "error":
                    $handler = [$this, 'errorHandler'];
                    break;
                case "debug":
                    $handler = function ($msg) {
                        $this->log->debug("[yasmin] " . $msg);
                    };
                    break;
                default:
                    $handler = function (...$args) use ($method) {
                        return $this->eventManager->yasminEventHandler($method->name, $args);
                    };
                    break;
            }
            $this->on($method->name, $handler);
        }
    }

    private function setupLogger(): Logger
    {
        $l_console = new StreamHandler(STDERR, "info"); // for some reason it doesnt like it being passed by var? idfk
        $l_console->setFormatter(new LineFormatter(null, null, true, true));
        $l_template = new Logger("Bot");
        $l_template->pushHandler($l_console);
        ErrorHandler::register($l_template);
        if ($this->config['logLevel'] == Logger::DEBUG) {
            $l_template->pushProcessor(new IntrospectionProcessor());
            $l_template->pushProcessor(new GitProcessor());
        }
        Registry::addLogger($l_template);
        return $l_template;
    }

    private function registerBuiltinHooks(): void
    {
        RSSProcessor::register($this);
    }

    public function start(): void
    {
        $this->login($this->config['botToken']);
        $this->loop->run();
    }

    public function readyHandler(): void
    {
        $this->log->info("Logged in as {$this->user->tag} ({$this->user->id})");
        $this->eventManager->initializePeriodics();
        $this->emit(PluginInterface::PLUGINEVENT_READY, $this);
    }

    public function messageHandler(Message $message): void
    {
        $tag = ($message->guild->name ?? false) ? $message->guild->name . " #" . $message->channel->name : "DM";
        $this->log->info('[' . $tag . '] ' . $message->author->tag . ': ' . $message->content);
        $preg = "/^!(\w+)(\s|$)/";
        $match = [];
        try {
            try {
                $this->emit(PluginInterface::PLUGINEVENT_MESSAGE, $this, $message);
            } catch (Throwable $e) {
                captureException($e);
                $this->log->warning("Uncaught Plugin exception!", ['exception' => $e]);
            }
            if (preg_match($preg, $message->content, $match)) {
                try {
                    $this->emit(PluginInterface::PLUGINEVENT_COMMAND_PREFIX . $match[1], $this, $message);
                } catch (Throwable $e) {
                    captureException($e);
                    $this->log->warning("Uncaught Plugin exception!", ['exception' => $e]);
                }
            }
        } catch (Throwable $e) {
            captureException($e);
            $this->log->warning("Uncaught message processing exception!", ['exception' => $e]);
        }
    }

    public function errorHandler(Throwable $e): void
    {
        captureException($e);
        $this->log->warning("Uncaught error!", ['exception' => $e]);
    }
}
