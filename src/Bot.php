<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

/**
 * Description of Bot
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class Bot
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
     * @var \CharlotteDunois\Yasmin\Client
     */
    public $client;

    /**
     *
     * @var array
     */
    public $config;

    public function __construct(array $config)
    {
        $this->log    = $this->setupLogger();
        $this->loop   = \React\EventLoop\Factory::create();
        $this->client = new \CharlotteDunois\Yasmin\Client([], $this->loop);
        $this->config = $config;

        $classes = get_declared_classes();
        foreach ($classes as $class) {
            if ((new \ReflectionClass($class))->implementsInterface("Huntress\PluginInterface")) {
                $this->log->addInfo("Loading plugin $class");
                $class::register($this);
            }
        }

        DatabaseFactory::make($this->config);


        $this->client->on('ready', [$this, 'readyHandler']);
        $this->client->on('message', [$this, 'messageHandler']);
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
        $this->client->login($this->config['botToken']);
        $this->loop->run();
    }

    public function readyHandler()
    {
        $this->log->info('Logged in as ' . $this->client->user->tag . ' created on ' . $this->client->user->createdAt->format('d.m.Y H:i:s'));
        $this->client->emit(PluginInterface::PLUGINEVENT_READY, $this);
    }

    public function messageHandler(\CharlotteDunois\Yasmin\Models\Message $message)
    {
        $this->log->info('[' . ($message->channel->type === 'text' ? $message->guild->name . ' #' . $message->channel->name : '(DM)') . '] ' . $message->author->tag . ': ' . $message->content);
        $preg  = "/^!(\w+)(\s|$)/";
        $match = [];
        try {
            if ($message->channel->type === 'text' && preg_match($preg, $message->content, $match)) {
                try {
                    $this->client->emit(PluginInterface::PLUGINEVENT_COMMAND_PREFIX . $match[1], $this, $message);
                } catch (\Throwable $e) {
                    $this->log->warning("Uncaught Plugin exception!", ['exception' => $e]);
                    echo PHP_EOL . $e->xdebug_message . PHP_EOL;
                }
            }
        } catch (\Throwable $e) {
            echo $e->xdebug_message;
        }
    }
}
