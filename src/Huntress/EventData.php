<?php

/*
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

/**
 * Description of EventData
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class EventData implements \JsonSerializable
{
    /**
     *
     * @var \CharlotteDunois\Yasmin\Models\User|\CharlotteDunois\Yasmin\Models\GuildMember
     */
    public $user;

    /**
     *
     * @var \CharlotteDunois\Yasmin\Models\GuildChannelInterface
     */
    public $channel;

    /**
     *
     * @var \CharlotteDunois\Yasmin\Models\Role
     */
    public $role;

    /**
     *
     * @var \CharlotteDunois\Yasmin\Models\Message
     */
    public $message;

    /**
     *
     * @var \CharlotteDunois\Yasmin\Models\Guild
     */
    public $guild;

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
