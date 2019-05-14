<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

use \Carbon\Carbon;
use \CharlotteDunois\Collect\Collection;
use \CharlotteDunois\Yasmin\Models\MessageEmbed;

/**
 * Description of RedditProcessor
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class RedditProcessor extends RSSProcessor
{

    public function __construct(Huntress $bot, string $id, string $target, int $interval, int $channel)
    {
        $url = "https://www.reddit.com/r/$target/new.json";
        parent::__construct($bot, $id, $url, $interval, $channel);
    }

    protected function dataProcessingCallback(string $string): Collection
    {
        try {
            $items = json_decode($string)->data->children;
            if (!is_countable($items)) {
                return new Collection([]);
            }
            $lastPub  = $this->getLastRSS();
            $newest   = $lastPub;
            $newItems = [];
            foreach ($items as $item) {
                $published = Carbon::createFromTimestamp($item->data->created_utc);
                if ($published <= $lastPub) {
                    continue;
                }
                $newest     = max($newest, $published);
                $newItems[] = (object) [
                    'title'    => $item->data->title,
                    'link'     => "https://www.reddit.com" . $item->data->permalink,
                    'date'     => $published,
                    'category' => $item->data->link_flair_text ?? "Unflaired",
                    'body'     => (strlen($item->data->selftext) > 0) ? $item->data->selftext : $item->data->url,
                    'author'   => $item->data->author,
                ];
            }
            return new Collection($newItems);
        } catch (\Throwable $e) {
            \Sentry\captureException($e);
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
            $embed = new MessageEmbed();
            $embed->setTitle($item->title)->setURL($item->link)->setDescription($item->body)->setFooter($item->category)
            ->setTimestamp($item->date->timestamp)->setAuthor($item->author, '', "https://reddit.com/user/" . $item->author);
            $this->huntress->channels->get($this->channel)->send("", ['embed' => $embed]);
        } catch (\Throwable $e) {
            \Sentry\captureException($e);
            $this->huntress->log->addWarning($e->getMessage(), ['exception' => $e]);
            return false;
        }
        return true;
    }
}
