<?php
/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;


use Carbon\Carbon;
use CharlotteDunois\Collect\Collection;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Huntress\RedditProcessor;
use Throwable;
use function Sentry\captureException;

class RCB extends RedditProcessor implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        if (self::isTestingClient()) {
            $bot->log->debug("Not adding RSS event on testing.");
        } else {
            new self($bot, "rcbWormMemes", "WormMemes", 30, [354769211937259521, 608108475037384708]);
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
}
