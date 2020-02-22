<?php
/**
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

use React\EventLoop\LoopInterface;
use Sentry\Options;
use Sentry\Transport\TransportFactoryInterface;
use Sentry\Transport\TransportInterface;

class SentryTransportFactory implements TransportFactoryInterface
{
    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @param LoopInterface $loop
     */
    public function setLoop(LoopInterface $loop): void
    {
        $this->loop = $loop;
    }

    public function create(Options $options): TransportInterface
    {
        return new SentryTransport($options, $this->loop);
    }
}
