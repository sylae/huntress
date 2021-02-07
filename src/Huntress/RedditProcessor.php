<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

use Carbon\Carbon;
use CharlotteDunois\Collect\Collection;
use CharlotteDunois\Yasmin\Models\MessageEmbed;
use Throwable;

/**
 * Description of RedditProcessor
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class RedditProcessor extends RSSProcessor
{

    public function __construct(Huntress $bot, string $id, string $target, int $interval, array $channels)
    {
        $url = "https://www.reddit.com/r/$target/new.json";
        parent::__construct($bot, $id, $url, $interval, $channels);
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
                if ($published <= $lastPub) {
                    continue;
                }
                $newest = max($newest, $published);

                $x = $this->getObject();
                $x->title = $item->data->title;
                $x->link = "https://www.reddit.com" . $item->data->permalink;
                $x->date = $published;
                $x->category = $item->data->link_flair_text ?? "Unflaired";
                $x->author = $item->data->author;
                $x->color = $item->data->link_flair_background_color ?? null;
                $x->body = (strlen($item->data->selftext) > 0) ? $item->data->selftext : $item->data->url;
                if ($this->checkExtension($item->data->url)) {
                    $x->image = $item->data->url;
                }

                $newItems[] = $x;
            }
            return new Collection($newItems);
        } catch (Throwable $e) {
            $this->huntress->log->warning($e->getMessage(), ['exception' => $e]);
            return new Collection();
        }
    }

    protected function checkExtension(string $haystack): bool
    {
        $ext = [".jpg", ".jpeg", ".gif", ".png", ".webp"];
        foreach ($ext as $needle) {
            if (0 === substr_compare($haystack, $needle, -strlen($needle))) {
                return true;
            }
        }
        return false;
    }

    protected function formatItemCallback(RSSItem $item): MessageEmbed
    {
        $embed = parent::formatItemCallback($item);
        $embed->setAuthor($item->author, '',"https://reddit.com/user/" . $item->author);

        return $embed;
    }

}
