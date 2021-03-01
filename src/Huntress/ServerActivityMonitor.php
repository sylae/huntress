<?php
/*
 * Copyright (c) 2021 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

use CharlotteDunois\Yasmin\Models\Guild;
use CharlotteDunois\Yasmin\Models\Message;

class ServerActivityMonitor
{
    public int $messages = 0;
    public int $botMessages = 0;

    public function __construct(
        public Guild $guild
    ) {
        $this->createDataStore();
    }

    /**
     * @todo use thread
     */
    private function createDataStore(bool $force = false)
    {
        $exe = (PHP_OS == "WINNT") ? "wsl rrdtool" : "rrdtool";
        $filename = "temp/serveractivity/{$this->guild->id}_messages.rrd";

        if (!file_exists("temp/serveractivity")) {
            mkdir("temp/serveractivity");
        }

        // DS:name:type:timeout:min:max
        // RRA:method:xff:lastx:rows
        $command = "$exe create $filename --step 60 " .
            "DS:messages:ABSOLUTE:120:0:U " .
            "DS:botmessages:ABSOLUTE:120:0:U " .
            "DS:users:GAUGE:120:0:U " .
            "RRA:AVERAGE:0.5:1:1440 " . // 1 day @ 1 minute resolution
            "RRA:AVERAGE:0.5:15:672 " . // 1 week @ 15 minute resolution
            "RRA:AVERAGE:0.5:60:720 " . // 30 days @ 1 hour resolution
            "RRA:AVERAGE:0.5:1440:365"; // 365 days @ 1 day resolution

        if (!file_exists($filename) || $force) {
            $this->guild->client->log->info("Creating server activity logger file $filename...",
                ['guild' => $this->guild->name]);
            $return = `$command`;
            $this->guild->client->log->debug($return);
        }
    }

    public function addMessage(Message $message)
    {
        $this->messages++;

        if ($message->author->bot || $message->author->webhook || $message->system) {
            $this->botMessages++;
        }
    }

    /**
     * Commits the data to RRD repo and resets the counters
     */
    public function commit()
    {
        $this->addData();
        $this->wipe();
    }

    /**
     * @todo use thread
     */
    private function addData()
    {
        $exe = (PHP_OS == "WINNT") ? "wsl rrdtool" : "rrdtool";
        $filename = "temp/serveractivity/{$this->guild->id}_messages.rrd";

        $mess = $this->messages;
        $bmess = $this->botMessages;
        $mem = $this->guild->memberCount;

        $command = "$exe update $filename N:$mess:$bmess:$mem";
        $this->guild->client->log->info("Updating server activity $filename...", ['guild' => $this->guild->name]);
        $return = `$command`;
        $this->guild->client->log->debug($return);
    }

    private function wipe()
    {
        $this->messages = 0;
        $this->botMessages = 0;
    }
}
