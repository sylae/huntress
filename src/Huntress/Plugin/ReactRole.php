<?php
/*
 * Copyright (c) 2022 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Collect\Collection;
use CharlotteDunois\Yasmin\Models\Message;
use CharlotteDunois\Yasmin\Models\MessageReaction;
use CharlotteDunois\Yasmin\Models\User;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Huntress\ReactRoleSetup;
use React\Promise\PromiseInterface;

class ReactRole implements PluginInterface
{
    use PluginHelperTrait;

    protected static ?Collection $setups = null;

    public static function register(Huntress $bot)
    {
        // todo: update core HEM to include raw event data for cases like this
        $bot->on("messageReactionAdd", [self::class, "reactRoleAdd"]);
        $bot->on("messageReactionRemove", [self::class, "reactRoleRemove"]);
    }

    public static function addReactableMessage(ReactRoleSetup $setup)
    {
        if (is_null(self::$setups)) {
            self::$setups = new Collection();
        }

        $sid = sprintf("%s-%s", $setup->messageID, $setup->react);
        self::$setups->set($sid, $setup);
    }

    public static function reactRoleAdd(MessageReaction $reaction, User $reactor): ?PromiseInterface
    {
        /** @var Huntress $bot */
        $bot = $reaction->client;
        if ($reactor->id == $bot->user->id) {
            return null;
        }
        try {
            if (!self::isReactableMessage($reaction->message)) {
                return null;
            }

            $rrs = self::getMatchingReacts($reaction);
            if (is_null($rrs)) {
                $bot->log->warning("Unknown react $reaction->emoji for ReactRole");
                return $reaction->remove($reactor);
            }

            $reactMember = $reaction->message->guild->members->get($reactor->id);
            if (is_null($reactMember)) {
                // weird but ok, might happen if we havent fetched yet.
                return $reaction->remove($reactor);
            }

            $role = $reaction->message->guild->roles->get($rrs->roleID);
            if (is_null($role)) {
                $bot->log->warning("Unknown role $role for ReactRole");
                return $reaction->remove($reactor);
            }

            $p = new Permission($rrs->permission, $bot, $rrs->permissionDefault);
            $p->addMemberContext($reactMember);
            if (!$p->resolve()) {
                return $reaction->remove($reactor);
            }

            return $reactMember->addRole($role);
        } catch (\Throwable $e) {
            $bot->log->warning($e->getMessage(), ['exception' => $e]);
        }
    }

    public static function isReactableMessage(Message $message): bool
    {
        if (is_null(self::$setups)) {
            self::$setups = new Collection();
        }

        return self::$setups->filter(fn(ReactRoleSetup $v) => $v->messageID == $message->id)->count() > 0;
    }

    public static function getMatchingReacts(MessageReaction $reaction): ?ReactRoleSetup
    {
        if (is_null(self::$setups)) {
            self::$setups = new Collection();
        }

        $reactID = $reaction->emoji->id ?? $reaction->emoji->name;
        $mID = $reaction->message->id;

        return self::$setups->get(sprintf("%s-%s", $mID, $reactID));
    }

    public static function reactRoleRemove(MessageReaction $reaction, User $reactor): ?PromiseInterface
    {
        /** @var Huntress $bot */
        $bot = $reaction->client;
        if ($reactor->id == $bot->user->id) {
            return null;
        }
        try {
            if (!self::isReactableMessage($reaction->message)) {
                return null;
            }

            $rrs = self::getMatchingReacts($reaction);
            if (is_null($rrs)) {
                $bot->log->warning("Unknown react $reaction->emoji for ReactRole");
                return null;
            }

            $reactMember = $reaction->message->guild->members->get($reactor->id);
            if (is_null($reactMember)) {
                // weird but ok, might happen if we havent fetched yet.
                return null;
            }

            $role = $reaction->message->guild->roles->get($rrs->roleID);
            if (is_null($role)) {
                $bot->log->warning("Unknown role $role for ReactRole");
                return null;
            }

            $p = new Permission($rrs->permission, $bot, $rrs->permissionDefault);
            $p->addMemberContext($reactMember);
            if (!$p->resolve()) {
                return null;
            }

            return $reactMember->removeRole($role);
        } catch (\Throwable $e) {
            $bot->log->warning($e->getMessage(), ['exception' => $e]);
            return null;
        }
    }

}