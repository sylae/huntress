<?php
/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;


use Carbon\Carbon;
use CharlotteDunois\Collect\Collection;
use CharlotteDunois\Yasmin\Models\MessageEmbed;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Huntress\RSSItem;
use Huntress\RSSProcessor;
use Throwable;

class RCB extends RSSProcessor implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        if (self::isTestingClient()) {
            $bot->log->debug("Not adding RSS event on testing.");
        } else {
            new self($bot, "rcbNaziWatchNP", "https://www.reddit.com/user/Nationalist_Patriot.json", 30,
                [604464023013949445]);
            new self($bot, "rcbNaziWatchTGG", "https://www.reddit.com/user/TheGreatGimmick.json", 30,
                [604464023013949445]);
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
                if ($published <= $lastPub || $item->data->subreddit_name_prefixed != "r/WormFanfic") {
                    continue;
                }
                $newest = max($newest, $published);
                $x = new RSSItem();
                $x->date = $published;
                $x->category = $item->data->link_flair_text ?? "Unflaired";
                $x->link = "https://www.reddit.com" . $item->data->permalink;
                $x->author = $item->data->author;

                if ($item->kind == "t1") { // comment
                    $x->title = "Comment on post: " . $item->data->link_title;
                    $x->body = $item->data->body;
                } elseif ($item->kind == "t3") { // post
                    $x->title = $item->data->title;
                    $x->body = (strlen($item->data->selftext) > 0) ? $item->data->selftext : $item->data->url;

                }
                $newItems[] = $x;
            }
            return new Collection($newItems);
        } catch (Throwable $e) {
            $this->huntress->log->warning($e->getMessage(), ['exception' => $e]);
            return new Collection();
        }
    }
}
