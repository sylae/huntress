<?php
/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;


use Carbon\Carbon;
use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\Message;
use Huntress\DatabaseFactory;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Huntress\RSSProcessor;
use Huntress\YoutubeProcessor;
use React\Promise\PromiseInterface;
use Throwable;

class MisfitDiscord implements PluginInterface
{
    use PluginHelperTrait;

    public const ROLE_TWITCH_SUBS = 961760285021073418;
    public const ROLE_PATREON_SUBS = 788327678733320203;
    public const ROLE_ALL_SUBS = 1058532438046933014;
    public const CHANNEL_LOG = 790180678905888799;
    public const GUILD = 788326497177698315;

    public const CHANNEL_VODS_MISFIT = 894404211398291466;
    public const CHANNEL_VODS_ZED = 896145169483722764;
    public const CHANNEL_VODS_CBC = 1063882052337934436;


    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(
            EventListener::new()->setCallback([
                self::class,
                "pollActiveCheck",
            ])->setPeriodic(10)
        );

        new YoutubeProcessor($bot, "yt_misfitvods", "UCBzuDs8qZKGmuM8B1JzXLMA", 300, [self::CHANNEL_VODS_CBC, self::CHANNEL_VODS_MISFIT]);
        new YoutubeProcessor($bot, "yt_misfitmain", "UCSsTzqsjWo6xzRaoWlriESw", 300, [self::CHANNEL_VODS_CBC, self::CHANNEL_VODS_MISFIT]);
        new YoutubeProcessor($bot, "yt_zedmain", "UCelaffPVvQ_NMO0Ut9OqzkA", 300, [self::CHANNEL_VODS_ZED, self::CHANNEL_VODS_CBC]);
        new YoutubeProcessor($bot, "yt_nashmain", "UCbduWGOXSML0Y_68gYN6qWQ", 300, [self::CHANNEL_VODS_CBC]);
    }

    public static function pollActiveCheck(Huntress $bot)
    {
        if (self::isTestingClient()) {
            $bot->log->debug("Not firing " . __METHOD__);
            return;
        }
        try {

            return $bot->guilds->get(self::GUILD)->members->each(function (GuildMember $v) {
                $shouldHave = $v->roles->has(self::ROLE_TWITCH_SUBS) || $v->roles->has(self::ROLE_PATREON_SUBS);
                $has = $v->roles->has(self::ROLE_ALL_SUBS);

                if ($shouldHave && !$has) {
                    $v->guild->channels->get(self::CHANNEL_LOG)->send("Adding Maids role to $v");
                    $v->addRole(self::ROLE_ALL_SUBS);
                }

                if ($has && !$shouldHave) {
                    $v->guild->channels->get(self::CHANNEL_LOG)->send("Removing Maids role from $v");
                    $v->removeRole(self::ROLE_ALL_SUBS);
                }
            });
        } catch (Throwable $e) {
            $bot->log->warning($e->getMessage(), ['exception' => $e]);
        }
    }
}
