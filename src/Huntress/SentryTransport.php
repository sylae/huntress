<?php

/**
 * Lacia
 * Copyright 2018-2019 Charlotte Dunois, All Rights Reserved
 *
 * Website: https://charuru.moe
 * License: -
 */

namespace Huntress;

/**
 * Some minor adjustments to this to work with Huntress codebase.
 */
class SentryTransport implements \Sentry\Transport\TransportInterface
{
    /**
     * The client configuration.
     * @var \Sentry\Options
     */
    protected $config;

    /**
     * @var \Sentry\HttpClient\Authentication\SentryAuthentication
     */
    protected $auth;

    /**
     * The list of pending requests.
     * @var \React\Promise\PromiseInterface[]
     */
    protected $pendingRequests = [];

    /**
     * event loop, used for stuff
     * @var \React\EventLoop\LoopInterface
     */
    protected $loop;

    /**
     * Constructor.
     * @param \Sentry\Options  $config  The client configuration
     */
    function __construct(\Sentry\Options $config, \React\EventLoop\LoopInterface $loop)
    {
        $this->config = $config;
        $this->loop   = $loop;
        $this->auth   = new \Sentry\HttpClient\Authentication\SentryAuthentication($config, 'Lacia.Sentry', '0.1');

        \register_shutdown_function('register_shutdown_function', \Closure::fromCallable(array($this, 'cleanupPendingRequests')));
    }

    /**
     * Destructor. Ensures that all pending requests ends before destroying this object instance.
     * @return void
     */
    function __destruct()
    {
        $this->cleanupPendingRequests();
    }

    /**
     * Sends the given event.
     * @param \Sentry\Event  $event  The event
     * @return string|null  Returns the ID of the event or `null` if it failed to be sent
     */
    function send(\Sentry\Event $event): ?string
    {
        $event->getUserContext()->setIpAddress(null);

        $request = new \RingCentral\Psr7\Request(
        'POST',
        $this->config->getDsn() . \sprintf('/api/%d/store/', $this->config->getProjectId()), array(
            'Content-Type' => 'application/json',
            'User-Agent'   => 'Lacia.Sentry/0.1'
        ), \Sentry\Util\JSON::encode($event)
        );

        $request        = $this->auth->authenticate($request);
        $request->retry = true;

        $id                         = $event->getId();
        $this->pendingRequests[$id] = $request;

        // On fatal error, let the shutdown hook send the request
        if (!($event->getExtraContext()['fatal'] ?? false)) {
            $this->sendRequest($request, $id);
        }

        return $id;
    }

    /**
     * Sends the request to sentry.
     * @param \Psr\Http\Message\RequestInterface  $request
     * @return void
     */
    protected function sendRequest(\Psr\Http\Message\RequestInterface $request, string $id): void
    {
        \CharlotteDunois\Yasmin\Utils\URLHelpers::getHTTPClient()->withOptions(array(
            'timeout' => 30
        ))->send($request)->done(function () use ($id) {
            unset($this->pendingRequests[$id]);
        }, function (\Throwable $e) use ($request, $id) {
            \Monolog\Registry::getInstance("Bot")->debug('Unable to send event ' . $id . ' to Sentry.', ['error' => $e]);

            if ($request->retry) {
                \Monolog\Registry::getInstance("Bot")->debug('Retrying sending event ' . $id . ' to Sentry in 30 seconds');

                $request->retry = false;
                $this->loop->addTimer(30, function () use ($request, $id) {
                    $this->sendRequest($request, $id);
                });

                return;
            }

            unset($this->pendingRequests[$id]);
        });
    }

    /**
     * Cleanups the pending requests by forcing them to be sent.
     * @return void
     */
    protected function cleanupPendingRequests(): void
    {
        if (empty($this->pendingRequests)) {
            return;
        }

        $loop    = \React\EventLoop\Factory::create();
        $browser = new \Clue\React\Buzz\Browser($loop);

        $loop->addTimer(120, function () {
            \Monolog\Registry::getInstance("Bot")->error('Hit the Sentry Transport Loop time limit, forcing exit...');
            exit(1);
        });

        $promises = array();

        foreach ($this->pendingRequests as $id => $request) {
            $promises[] = $browser->send($request)->then(function () use ($id) {
                unset($this->pendingRequests[$id]);
            }, function (\Throwable $exception) use ($id) {
                unset($this->pendingRequests[$id]);

                \Monolog\Registry::getInstance("Bot")->error('Caught an exception in Sentry Transport Cleanup.', ['error' => $exception]);
            });
        }

        \Clue\React\Block\await(\React\Promise\all($promises), $loop);
    }
}
