<?php

/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Huntress\ReactRoleSetup;

class CollieTechDev implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $pronouns = [
            "💚" => 998449776309842011, // she
            "🟡" => 998449815866318858, // they
            "🟦" => 998449794961899530, // he
            998464215822106674 => 998449835445329972, // ???
        ];

        foreach ($pronouns as $react => $id) {
            $rrs = new ReactRoleSetup();
            $rrs->permissionDefault = true;
            $rrs->permission = "p.ctd.roles.pronouns";
            $rrs->messageID = 998468106303328266;
            $rrs->react = $react;
            $rrs->roleID = $id;
            ReactRole::addReactableMessage($rrs);
        }
    }
}
