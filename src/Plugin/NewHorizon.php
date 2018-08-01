<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

/**
 * Simple builtin to show user information
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class NewHorizon implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;

    public static function register(\Huntress\Bot $bot)
    {
        $bot->client->on("voiceStateUpdate", [self::class, "voiceStateHandler"]);
    }

    public static function voiceStateHandler(\CharlotteDunois\Yasmin\Models\GuildMember $new, ?\CharlotteDunois\Yasmin\Models\GuildMember $old)
    {
        if ($new->guild->id == "450657331068403712" && $new->voiceChannel instanceof \CharlotteDunois\Yasmin\Models\VoiceChannel) {
            $role = $new->guild->roles->get("474058052916477955");
            if (is_null($new->roles->get("474058052916477955"))) {
                $new->addRole($role)->then(function () use ($new) {
                    self::send($new->guild->channels->get("468082034045222942"), "<@{$new->id}>, I'm going to give you the DJ role, since you're joining a voice chat.");
                });
            }
        }
    }
}
