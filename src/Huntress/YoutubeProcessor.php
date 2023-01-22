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
use CharlotteDunois\Yasmin\Utils\DataHelpers;
use League\HTMLToMarkdown\HtmlConverter;
use Throwable;

/**
 * Description of RedditProcessor
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class YoutubeProcessor extends RSSProcessor
{

    public function __construct(Huntress $bot, string $id, string $target, int $interval, array $channels)
    {
        $url = "https://www.youtube.com/feeds/videos.xml?channel_id=$target";
        parent::__construct($bot, $id, $url, $interval, $channels);
    }

    protected function dataProcessingCallback(string $string): Collection
    {
        try {
            $data = qp($string);
            $items = $data->find('entry');
            if (!is_countable($items)) {
                return new Collection();
            }
            $lastPub = $this->getLastRSS();
            $newest = $lastPub;
            $newItems = [];
            foreach ($items as $item) {
                $published = new Carbon($item->find('published')->text());
                if ($published <= $lastPub) {
                    continue;
                }
                $newest = max($newest, $published);

                $x = $this->getObject();
                $x->title = $item->find('media|title')->text();
                $x->link = $item->find('link')->attr("href");
                $x->date = $published;
                $x->image = $item->find('media|thumbnail')->attr("url");
                $x->author = $item->find('author > name')->text();
                $x->body = $item->find('media|description')->text();
                $x->_authorURL = $item->find('author > uri')->text();

                $newItems[] = $x;
            }
            return new Collection($newItems);
        } catch (Throwable $e) {
            $this->huntress->log->warning($e->getMessage(), ['exception' => $e]);
            return new Collection();
        }
    }

    protected function formatItemCallback(RSSItem $item): MessageEmbed
    {
        $embed = parent::formatItemCallback($item);
        $embed->setAuthor($item->author, '', $item->_authorURL);

        return $embed;
    }

}
