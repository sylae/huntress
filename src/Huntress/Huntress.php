<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

/**
 * This is the main Huntress class, mostly backend stuff tbh.
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class Huntress extends \CharlotteDunois\Yasmin\Client
{
    /**
     *
     * @var \Monolog\Logger
     */
    public $log;

    /**
     *
     * @var \React\EventLoop\LoopInterface
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
     * @var \Doctrine\DBAL\Connection
     */
    public $db;

    public function __construct(array $config, \React\EventLoop\LoopInterface $loop)
    {
        $this->log          = $this->setupLogger();
        $this->config       = $config;
        $this->eventManager = new EventManager($this);
        $this->registerBuiltinHooks();

        parent::__construct(['shardCount' => 1], $loop);

        $classes = get_declared_classes();
        foreach ($classes as $class) {
            if ((new \ReflectionClass($class))->implementsInterface("Huntress\PluginInterface")) {
                $this->log->addInfo("Loading plugin $class");
                $class::register($this);
            }
        }

        DatabaseFactory::make($this);
        $this->db = DatabaseFactory::get();

        // legacy handlers
        $this->on('ready', [$this, 'readyHandler']);
        $this->on('message', [$this, 'messageHandler']);

        $yasminEvents = new \ReflectionClass('\CharlotteDunois\Yasmin\ClientEvents');
        foreach ($yasminEvents->getMethods() as $method) {
            switch ($method->name) {
                case "raw":
                case "reconnect":
                case "disconnect":
                    continue 2;
                case "error":
                    $handler = [$this, 'errorHandler'];
                    break;
                case "debug":
                    $handler = function($msg) {
                        $this->log->debug("[yasmin] " . $msg);
                    };
                    break;
                default:
                    $handler = function(...$args) use ($method) {
                        return $this->eventManager->yasminEventHandler($method->name, $args);
                    };
                    break;
            }
            $this->on($method->name, $handler);
        }
    }

    private function setupLogger(): \Monolog\Logger
    {
        $l_console  = new \Monolog\Handler\StreamHandler(STDERR, $this->config['logLevel']);
        $l_console->setFormatter(new \Monolog\Formatter\LineFormatter(null, null, true, true));
        $l_template = new \Monolog\Logger("Bot");
        $l_template->pushHandler($l_console);
        \Monolog\ErrorHandler::register($l_template);
        if ($this->config['logLevel'] == \Monolog\Logger::DEBUG) {
            $l_template->pushProcessor(new \Monolog\Processor\IntrospectionProcessor());
            $l_template->pushProcessor(new \Monolog\Processor\GitProcessor());
        }
        \Monolog\Registry::addLogger($l_template);
        return $l_template;
    }

    public function start()
    {
        $this->login($this->config['botToken']);
        $this->loop->run();
    }

    public function readyHandler()
    {
        $this->log->info("Logged in as {$this->user->tag} ({$this->user->id})");
        $this->eventManager->initializePeriodics();
        $this->emit(PluginInterface::PLUGINEVENT_READY, $this);
    }

    private function registerBuiltinHooks()
    {
        RSSProcessor::register($this);
    }

    public function messageHandler(\CharlotteDunois\Yasmin\Models\Message $message)
    {
        $tag   = ($message->guild->name ?? false) ? $message->guild->name . " #" . $message->channel->name : "DM";
        $this->log->info('[' . $tag . '] ' . $message->author->tag . ': ' . $message->content);
        $preg  = "/^!(\w+)(\s|$)/";
        $match = [];
        try {
            try {
                $this->emit(PluginInterface::PLUGINEVENT_MESSAGE, $this, $message);
            } catch (\Throwable $e) {
                \Sentry\captureException($e);
                $this->log->warning("Uncaught Plugin exception!", ['exception' => $e]);
            }
            if (preg_match($preg, $message->content, $match)) {
                try {
                    $this->emit(PluginInterface::PLUGINEVENT_COMMAND_PREFIX . $match[1], $this, $message);
                } catch (\Throwable $e) {
                    \Sentry\captureException($e);
                    $this->log->warning("Uncaught Plugin exception!", ['exception' => $e]);
                }
            }
        } catch (\Throwable $e) {
            \Sentry\captureException($e);
            $this->log->warning("Uncaught message processing exception!", ['exception' => $e]);
        }
    }

    public function errorHandler(\Throwable $e)
    {
        \Sentry\captureException($e);
        $this->log->warning("Uncaught error!", ['exception' => $e]);
    }
}
