<?php
/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
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
use Throwable;
use function Sentry\captureException;

class WormRPRedditProcessor extends RedditProcessor implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        if (self::isTestingClient()) {
            $bot->log->debug("Not adding RSS event on testing.");
        } else {
            new self($bot, "wormrpPosts", "wormrp", 30, 118981144464195584);
        }
    }

    protected function dataProcessingCallback(string $string): Collection
    {
        try {
            $items = json_decode($string)->data->children ?? null;
            if (!is_countable($items)) {
                return new Collection([]);
            }
            $lastPub = $this->getLastRSS();
            $newest = $lastPub;
            $newItems = [];
            foreach ($items as $item) {
                $published = Carbon::createFromTimestamp($item->data->created_utc);
                if ($published <= $lastPub || is_null($item->data->link_flair_text)) {
                    continue;
                }
                $newest = max($newest, $published);
                $newItems[] = (object) [
                    'title' => $item->data->title,
                    'link' => "https://www.reddit.com" . $item->data->permalink,
                    'date' => $published,
                    'category' => $item->data->link_flair_text ?? "Unflaired",
                    'body' => (strlen($item->data->selftext) > 0) ? $item->data->selftext : $item->data->url,
                    'author' => $item->data->author,
                ];
            }
            return new Collection($newItems);
        } catch (Throwable $e) {
            captureException($e);
            $this->huntress->log->addWarning($e->getMessage(), ['exception' => $e]);
            return new Collection();
        }
    }

    protected function dataPublishingCallback(object $item): bool
    {
        try {
            if (mb_strlen($item->body) > 500) {
                $item->body = substr($item->body, 0, 500) . "...";
            }
            if (mb_strlen($item->title) > 250) {
                $item->body = substr($item->title, 0, 250) . "...";
            }

            switch ($item->category) {
                case "Character":
                case "Equipment":
                case "Lore":
                case "Group":
                    $channel = 386943351062265888; // the_list
                    break;
                case "Meta":
                    $channel = 118981144464195584; // general
                    break;
                case "Event":
                case "Patrol":
                case "Non-Canon":
                default:
                    $channel = 409043591881687041; // events
            }

            $embed = new MessageEmbed();
            $embed->setTitle($item->title)->setURL($item->link)->setDescription($item->body)->setFooter($item->category)
                ->setTimestamp($item->date->timestamp);

            $redditUser = WormRP::fetchAccount($this->huntress->channels->get($channel)->guild, $item->author);
            if ($redditUser instanceof GuildMember) {
                $embed->setAuthor($redditUser->displayName, $redditUser->user->getDisplayAvatarURL(),
                    "https://reddit.com/user/" . $item->author);
            } else {
                $embed->setAuthor($item->author, '', "https://reddit.com/user/" . $item->author);
            }

            $this->huntress->channels->get($channel)->send("", ['embed' => $embed]);
        } catch (Throwable $e) {
            captureException($e);
            $this->huntress->log->addWarning($e->getMessage(), ['exception' => $e]);
            return false;
        }
        return true;
    }
}
