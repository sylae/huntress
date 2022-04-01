<?php
/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Yasmin\Models\MessageReaction;
use CharlotteDunois\Yasmin\Models\User;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\ExtendedPromiseInterface as Promise;
use Throwable;


/**
 * Very WIP system for role self-management
 */
class PronounBot implements PluginInterface
{
    use PluginHelperTrait;

    protected const COLONIAL = 943654648252858368;
    protected const MEMBER = 943996715160182844;
    protected const TENURED = 943653875368480808;

    public static function register(Huntress $bot)
    {
        $bot->on("messageReactionAdd", [self::class, "addHandler"]);
        $bot->on("messageReactionRemove", [self::class, "removeHandler"]);
    }

    public static function addHandler(MessageReaction $reaction, User $reactor): ?Promise
    {
        /** @var Huntress $bot */
        $bot = $reaction->client;
        if ($reactor->id == $bot->user->id) {
            return null;
        }
        try {
            $reactMessages = self::getReactMessages();

            if (!in_array($reaction->message->id, $reactMessages)) {
                return null;
            }

            $reactID = $reaction->emoji->id ?? $reaction->emoji->name;

            [$roleID, $restriction] = self::getReactMapping($reactID);
            if (is_null($roleID)) {
                $bot->log->warning("Unknown react $reactID for PronounBot");
                return $reaction->remove();
            }

            $role = $reaction->message->guild->roles->get($roleID);
            if (is_null($role)) {
                $bot->log->warning("Unknown role $roleID for PronounBot");
                return $reaction->remove($reactor);
            }

            $reactMember = $reaction->message->guild->members->get($reactor->id);
            if (is_null($reactMember)) {
                // weird but ok, might happen if we havent fetched yet.
                return $reaction->remove($reactor);
            }

            $isTenured = $reactMember->roles->has(self::TENURED);
            $isMember = $reactMember->roles->has(self::MEMBER) || $isTenured;
            $isColonial = $reactMember->roles->has(self::COLONIAL) || $isMember;

            if (!$isColonial && $restriction == self::COLONIAL) {
                return $reaction->remove($reactor);
            }
            if (!$isMember && $restriction == self::MEMBER) {
                return $reaction->remove($reactor);
            }
            if (!$isTenured && $restriction == self::TENURED) {
                return $reaction->remove($reactor);
            }

            return $reactMember->addRole($role);
        } catch (Throwable $e) {
            $bot->log->warning($e->getMessage(), ['exception' => $e]);
        }
    }

    public static function getReactMessages(): array
    {
        return [
            944208809746923520,
            944268056924930158,
            944268288228229211,
        ];
    }

    public static function getReactMapping(mixed $reactID): array
    {
        return match ($reactID) {
            "944208162112802826" => [944203243964207144, self::COLONIAL], // qrf
            "958203821451001906" => [944211152668327937, self::COLONIAL], // oper8or
            "958768926941134878" => [959556988075917383, self::COLONIAL], // logi
            "🥪" => [944107391677521940, self::TENURED], // sudo

            default => [null, true],
        };
    }

    public static function removeHandler(MessageReaction $reaction, User $reactor): ?Promise
    {
        /** @var Huntress $bot */
        $bot = $reaction->client;
        if ($reactor->id == $bot->user->id) {
            return null;
        }
        try {
            $reactMessages = self::getReactMessages();

            if (!in_array($reaction->message->id, $reactMessages)) {
                return null;
            }

            $reactID = $reaction->emoji->id ?? $reaction->emoji->name;

            [$roleID, $corpOnly] = self::getReactMapping($reactID);
            if (is_null($roleID)) {
                $bot->log->warning("Unknown react $reactID for PronounBot");
                return null;
            }

            $role = $reaction->message->guild->roles->get($roleID);
            if (is_null($role)) {
                $bot->log->warning("Unknown role $roleID for PronounBot");
                return null;
            }

            $reactMember = $reaction->message->guild->members->get($reactor->id);
            if (is_null($reactMember)) {
                // weird but ok, might happen if we havent fetched yet.
                return null;
            }

            return $reactMember->removeRole($role);
        } catch (Throwable $e) {
            $bot->log->warning($e->getMessage(), ['exception' => $e]);
        }
    }

}
