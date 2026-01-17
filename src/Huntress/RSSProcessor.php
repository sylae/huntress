<?php

/*
 * Copyright (c) 2019-2026 MisfitMaid and contributors.
 *
 * Use of this source code is governed by the MIT Non-AI license, which can be found in the LICENSE file.
 */

namespace Huntress;

use Carbon\Carbon;
use Discord\Helpers\Collection;
use Discord\Parts\Channel\GuildAnnouncement;
use Discord\Parts\Channel\Message;
use Discord\Parts\Embed\Embed;
use League\HTMLToMarkdown\HtmlConverter;
use Throwable;
use function qp;

/**
 * Unified class for handling RSS and other syndication systems.
 */
class RSSProcessor
{
    public ?int $itemColor;
    public bool $showBody = true;
    public bool $crosspost = true;

    public function __construct(
        public Huntress $huntress,
        public string $id,
        public string $url,
        public int $interval,
        public array $channels
    ) {
        $this->huntress->eventManager->addURLEvent($this->url, $this->interval, [$this, 'eventManagerCallback']);
    }

    public static function register(Huntress $bot)
    {
    }

    public function eventManagerCallback(string $string, Huntress $bot)
    {
        $collect = $this->dataProcessingCallback($string)->sort([$this, 'sortObjects']);
        $this->huntress->getLogger()->debug("[RSS] {$this->id} - There are " . $collect->count() . " items to post.");

        /** @var RSSItem $item */
        foreach ($collect as $item) {
            $item->channels = $this->channelCheckCallback($item, $this->channels);
            $this->dataPublishingCallback($item);
        }
        if ($collect->count() > 0) {
            $time = $collect->reduce(function ($carry, RSSItem $v) {
                if ($v->date > $carry) {
                    return $v->date;
                }
                return $carry;
            });
            $this->setLastRSS($time->first());
        }
    }

    protected function dataProcessingCallback(string $string): Collection
    {
        try {
            $data = qp($string);
            $items = $data->find('item');
            if (!is_countable($items)) {
                return new Collection();
            }
            $lastPub = $this->getLastRSS();
            $newest = $lastPub;
            $newItems = [];
            foreach ($items as $item) {
                $published = new Carbon($item->find('pubDate')->text());
                if ($published <= $lastPub) {
                    continue;
                }
                $newest = max($newest, $published);

                $x = $this->getObject();
                $x->title = $item->find('title')->text();
                $x->link = $item->find('link')->text();
                $x->date = $published;
                $x->category = $item->find('category')->text();
                $x->body = new HtmlConverter(['strip_tags' => true])->convert($item->find('description')->text());

                $newItems[] = $x;
            }
            return new Collection($newItems);
        } catch (Throwable $e) {
            $this->huntress->getLogger()->warning($e->getMessage(), ['exception' => $e]);
            return new Collection();
        }
    }

    protected function getLastRSS(): Carbon
    {
        $qb = $this->huntress->db->createQueryBuilder();
        $qb->select("*")->from("rss")->where('`id` = ?')->setParameter(0, $this->id, "string");
        $res = $qb->executeQuery()->fetchAllAssociative();
        foreach ($res as $data) {
            return new Carbon($data['lastUpdate']);
        }
        return Carbon::createFromTimestamp(0);
    }

    /**
     * Override to change the "default" item deets.
     * @return RSSItem
     */
    protected function getObject(): RSSItem
    {
        $x = new RSSItem();
        $x->channels = $this->channels;
        if (is_int($this->itemColor)) {
            $x->color = $this->itemColor;
        }

        return $x;
    }

    /**
     * Override this method to customize which channel data goes to.
     *
     * @param RSSItem $item
     * @param array $channels
     *
     * @return array the new array of channels to send to
     */
    protected function channelCheckCallback(RSSItem $item, array $channels): array
    {
        return $channels;
    }

    protected function dataPublishingCallback(RSSItem $item): bool
    {
        try {
            $embed = $this->formatItemCallback($item);

            foreach ($item->channels as $channel) {
                $ch = $this->huntress->getChannel($channel);
                $prom = $ch->sendMessage("", false, $embed);
                if ($ch instanceof GuildAnnouncement && $this->crosspost) {
                    $prom->then(fn(Message $m) => $m->crosspost());
                }
            }
        } catch (Throwable $e) {
            $this->huntress->getLogger()->warning($e->getMessage(), ['exception' => $e]);
            return false;
        }
        return true;
    }

    /**
     * Override to perform any modifications to the MessageEmbed
     */
    protected function formatItemCallback(RSSItem $item): Embed
    {

        if (mb_strlen($item->body ?? "") > 500) {
            $item->body = substr($item->body, 0, 500) . "...";
        }
        if (mb_strlen($item->title ?? "") > 250) {
            $item->body = substr($item->title, 0, 250) . "...";
        }
        $embed = new Embed($this->huntress);
        $embed->setTitle($item->title)->setURL($item->link)->setTimestamp($item->date->timestamp);

        if ($this->showBody) {
            $embed->setDescription(substr($item->body, 0, 2040));
        }

        if (is_int($item->color)) {
            $embed->setColor($item->color);
        }

        if (mb_strlen($item->author ?? "") > 0) {
            $embed->setAuthor($item->author);
        }

        if (mb_strlen($item->category ?? "") > 0) {
            $embed->setFooter($item->category);
        }

        if (mb_strlen($item->image ?? "") > 0) {
            $embed->setImage($item->image);
        }

        return $embed;
    }

    protected function setLastRSS(Carbon $time): void
    {
        if ($this->getLastRSS() >= $time) {
            return;
        }
        $query = $this->huntress->db->prepare('INSERT INTO rss (`id`, `lastUpdate`) VALUES(?, ?) '
            . 'ON DUPLICATE KEY UPDATE `lastUpdate` = VALUES(`lastUpdate`);
        ', ['string', 'datetime']);
        $query->bindValue(1, $this->id);
        $query->bindValue(2, $time);
        $query->executeStatement();
    }

    public function sortObjects($a, $b): int
    {
        return $a->date <=> $b->date;
    }
}
