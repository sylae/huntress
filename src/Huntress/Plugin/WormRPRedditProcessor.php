<?php
/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;


use Carbon\Carbon;
use CharlotteDunois\Collect\Collection;
use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\MessageEmbed;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Huntress\RedditProcessor;
use Huntress\RSSItem;
use React\ChildProcess\Process;
use React\Promise\Deferred;
use Throwable;

class WormRPRedditProcessor extends RedditProcessor implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        if (self::isTestingClient()) {
            $bot->log->debug("Not adding RSS event on testing.");
        } else {
            new self($bot, "wormrpPosts", "wormrp", 30, []);
        }
    }

    protected function channelCheckCallback(RSSItem $item, array $channels): array
    {
        $channels[] = match ($item->category) {
            "Character", "Equipment", "Lore", "Group" => 386943351062265888,
            "Meta" => 118981144464195584,
            default => 409043591881687041,
        };
        return parent::channelCheckCallback($item, $channels);
    }

    protected function formatItemCallback(RSSItem $item): MessageEmbed
    {
        $embed = parent::formatItemCallback($item);

        $redditUser = WormRP::fetchAccount($this->huntress->guilds->get(118981144464195584), $item->author);
        if ($redditUser instanceof GuildMember) {
            $embed->setAuthor($redditUser->displayName, $redditUser->user->getDisplayAvatarURL(),
                "https://reddit.com/user/" . $item->author);
        } else {
            $embed->setAuthor($item->author, '', "https://reddit.com/user/" . $item->author);
        }
    }

    protected function dataPublishingCallback(RSSItem $item): bool
    {
        try {
            parent::dataPublishingCallback($item);

            // update flair check thing
            $query = $this->huntress->db->prepare('INSERT INTO wormrp_activity (`redditName`, `lastSubActivity`) VALUES(?, ?) '
                . 'ON DUPLICATE KEY UPDATE `lastSubActivity`=GREATEST(`lastSubActivity`, VALUES(`lastSubActivity`));',
                ['string', 'datetime', 'string']);
            $query->bindValue(1, $item->author);
            $query->bindValue(2, $item->date);
            $query->execute();

            // send to approval queue sheet
            if (in_array(386943351062265888, $item->channels)) {
                $allowed = ["Equipment", "Lore", "Character"];
                if (!in_array($item->category, $allowed)) {
                    $item->category = "Other";
                }
                $req = [
                    'sheetID' => WormRP::APPROVAL_SHEET,
                    'sheetRange' => 'Queue!A10:H',
                    'action' => 'pushRow',
                    'data' => [
                        $item->date->toDateString(),
                        $item->title,
                        $item->category,
                        "Pending",
                        $item->author,
                        "",
                        "",
                        $item->link,
                    ],
                ];
                $payload = base64_encode(json_encode($req));
                $cmd = 'php scripts/pushGoogleSheet.php ' . $payload;
                $this->huntress->log->debug("Running $cmd");
                $process = new Process($cmd, null, null, []);
                $process->start($this->huntress->getLoop());
                $prom = new Deferred();

                $process->on('exit', function (int $exitcode) use ($prom) {
                    $this->huntress->log->debug("queueHandler child exited with code $exitcode.");

                    if ($exitcode == 0) {
                        $this->huntress->log->debug("queueHandler child success!");
                        $prom->resolve();
                    } else {
                        $prom->reject();
                        $this->huntress->log->debug("queueHandler child failure!");
                    }
                });
            }
        } catch (Throwable $e) {
            $this->huntress->log->warning($e->getMessage(), ['exception' => $e]);
            return false;
        }
        return true;
    }
}
