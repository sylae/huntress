<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Command;

/**
 * Description of ping
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class evaluate extends \Huntress\Command
{
    /**
     * Whether or not to echo out exception traces
     * @var bool
     */
    protected $traces = false;

    public function process(): \React\Promise\ExtendedPromiseInterface
    {
        return new \React\Promise\Promise(function() {
            if (!in_array($this->message->author->id, $this->config['evalUsers'])) {
                return $this->unauthorized();
            } else {
                $args     = $this->_split($this->message->content);
                $message  = str_replace($args[0], "", $this->message->content);
                $message  = str_replace(['```php', '```'], "", $message);
                $response = $this->eval($message);
                if (is_string($response)) {
                    return $this->send($response);
                } else {
                    return $this->send("```json" . PHP_EOL . json_encode($response, JSON_PRETTY_PRINT) . PHP_EOL . "```");
                }
            }
        });
    }

    private function eval(string $commands)
    {

        try {
            return eval($commands);
        } catch (\Throwable $e) {
            $this->exceptionHandler($e, $this->traces);
        }
    }
}
