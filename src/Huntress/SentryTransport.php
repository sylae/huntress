<?php

/**
 * Lacia
 * Copyright 2018-2019 Charlotte Dunois, All Rights Reserved
 *
 * Website: https://charuru.moe
 * License: -
 */

namespace Huntress;

use CharlotteDunois\Yasmin\Utils\URLHelpers;
use Closure;
use function Clue\React\Block\await;
use Clue\React\Buzz\Browser;
use Monolog\Registry;
use Psr\Http\Message\RequestInterface;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use function React\Promise\all;
use React\Promise\PromiseInterface;
use function register_shutdown_function;
use RingCentral\Psr7\Request;
use Sentry\Event;
use Sentry\HttpClient\Authentication\SentryAuthentication;
use Sentry\Options;
use Sentry\Transport\TransportInterface;
use Sentry\Util\JSON;
use function sprintf;
use Throwable;

/**
 * Some minor adjustments to this to work with Huntress codebase.
 */
class SentryTransport implements TransportInterface
{
    /**
     * The client configuration.
     * @var Options
     */
    protected $config;

    /**
     * @var SentryAuthentication
     */
    protected $auth;

    /**
     * The list of pending requests.
     * @var PromiseInterface[]
     */
    protected $pendingRequests = [];

    /**
     * event loop, used for stuff
     * @var LoopInterface
     */
    protected $loop;

    /**
     * Constructor.
     *
     * @param Options $config The client configuration
     */
    public function __construct(Options $config, LoopInterface $loop)
    {
        $this->config = $config;
        $this->loop = $loop;
        $this->auth = new SentryAuthentication($config, 'Lacia.Sentry', '0.1');

        register_shutdown_function('register_shutdown_function',
            Closure::fromCallable([$this, 'cleanupPendingRequests']));
    }

    /**
     * Destructor. Ensures that all pending requests ends before destroying this object instance.
     * @return void
     */
    public function __destruct()
    {
        $this->cleanupPendingRequests();
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

        $loop = Factory::create();
        $browser = new Browser($loop);

        $loop->addTimer(120, function () {
            Registry::getInstance("Bot")->error('Hit the Sentry Transport Loop time limit, forcing exit...');
            exit(1);
        });

        $promises = [];

        foreach ($this->pendingRequests as $id => $request) {
            $promises[] = $browser->send($request)->then(function () use ($id) {
                unset($this->pendingRequests[$id]);
            }, function (Throwable $exception) use ($id) {
                unset($this->pendingRequests[$id]);

                Registry::getInstance("Bot")->error('Caught an exception in Sentry Transport Cleanup.',
                    ['error' => $exception]);
            });
        }

        await(all($promises), $loop);
    }

    /**
     * Sends the given event.
     *
     * @param Event $event The event
     *
     * @return string|null  Returns the ID of the event or `null` if it failed to be sent
     */
    public function send(Event $event): ?string
    {
        $event->getUserContext()->setIpAddress(null);

        $request = new Request(
            'POST',
            $this->config->getDsn() . sprintf('/api/%d/store/', $this->config->getProjectId()), [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Lacia.Sentry/0.1',
        ], JSON::encode($event)
        );

        $request = $this->auth->authenticate($request);
        $request->retry = true;

        $id = $event->getId();
        $this->pendingRequests[$id] = $request;

        // On fatal error, let the shutdown hook send the request
        if (!($event->getExtraContext()['fatal'] ?? false)) {
            $this->sendRequest($request, $id);
        }

        return $id;
    }

    /**
     * Sends the request to sentry.
     *
     * @param RequestInterface $request
     *
     * @return void
     */
    protected function sendRequest(RequestInterface $request, string $id): void
    {
        URLHelpers::getHTTPClient()->withOptions([
            'timeout' => 30,
        ])->send($request)->done(function () use ($id) {
            unset($this->pendingRequests[$id]);
        }, function (Throwable $e) use ($request, $id) {
            Registry::getInstance("Bot")->debug('Unable to send event ' . $id . ' to Sentry.',
                ['error' => $e]);

            if ($request->retry) {
                Registry::getInstance("Bot")->debug('Retrying sending event ' . $id . ' to Sentry in 30 seconds');

                $request->retry = false;
                $this->loop->addTimer(30, function () use ($request, $id) {
                    $this->sendRequest($request, $id);
                });

                return;
            }

            unset($this->pendingRequests[$id]);
        });
    }
}
