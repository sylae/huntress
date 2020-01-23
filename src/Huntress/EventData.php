<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

use CharlotteDunois\Yasmin\Interfaces\GuildChannelInterface;
use CharlotteDunois\Yasmin\Models\Guild;
use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\Message;
use CharlotteDunois\Yasmin\Models\Role;
use CharlotteDunois\Yasmin\Models\User;
use JsonSerializable;

/**
 * Description of EventData
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class EventData implements JsonSerializable
{
    /**
     *
     * @var User|GuildMember
     */
    public $user;

    /**
     *
     * @var GuildChannelInterface
     */
    public $channel;

    /**
     *
     * @var Role
     */
    public $role;

    /**
     *
     * @var Message
     */
    public $message;

    /**
     *
     * @var Guild
     */
    public $guild;

    /**
     *
     * @var Huntress
     */
    public $huntress;

    /**
     *
     * @var string
     */
    public $command;

    public function jsonSerialize(): array
    {
        $x = [];
        foreach (get_object_vars($this) as $k => $v) {
            if (is_null($v)) {
                continue;
            }
            if ($k === "command") {
                $x[$k] = $v;
            } else {
                $x[$k] = $v->id;
            }
        }
        return $x;
    }
}
