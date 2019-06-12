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
use Doctrine\DBAL\Schema\Schema;
use League\HTMLToMarkdown\HtmlConverter;
use Throwable;
use function qp;
use function Sentry\captureException;

/**
 * Unified class for handling RSS and other syndication systems.
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class RSSProcessor
{
    /**
     *
     * @var Huntress
     */
    public $huntress;

    /**
     *
     * @var string
     */
    public $id;

    /**
     *
     * @var int
     */
    public $interval;

    /**
     *
     * @var string
     */
    public $url;

    /**
     *
     * @var int
     */
    public $channel;

    /**
     *
     * @var int
     */
    public $itemColor;

    /**
     * @var bool
     */
    public $showBody = true;

    public function __construct(Huntress $bot, string $id, string $url, int $interval, int $channel)
    {
        $this->huntress = $bot;
        $this->id = $id;
        $this->url = $url;
        $this->interval = $interval;
        $this->channel = $channel;

        $this->huntress->eventManager->addURLEvent($this->url, $this->interval, [$this, 'eventManagerCallback']);
    }

    public static function db(Schema $schema): void
    {
        $t = $schema->createTable("rss");
        $t->addColumn("id", "string", ['length' => 255, 'customSchemaOptions' => DatabaseFactory::CHARSET]);
        $t->addColumn("lastUpdate", "datetime");
        $t->setPrimaryKey(["id"]);
    }

    public static function register(Huntress $bot)
    {
        $dbEv = EventListener::new()->addEvent("dbSchema")->setCallback([self::class, 'db']);
        $bot->eventManager->addEventListener($dbEv);
    }

    public function eventManagerCallback(string $string, Huntress $bot)
    {
        $collect = $this->dataProcessingCallback($string)->sortCustom([$this, 'sortObjects']);
        $this->huntress->log->addDebug("[RSS] {$this->id} - There are " . $collect->count() . " items to post.");
        foreach ($collect as $item) {
            $this->dataPublishingCallback($item);
        }
        if ($collect->count() > 0) {
            $this->setLastRSS($collect->max('date'));
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
                $newItems[] = (object) [// todo: make this its own class
                    'title' => $item->find('title')->text(),
                    'link' => $item->find('link')->text(),
                    'date' => $published,
                    'category' => $item->find('category')->text(),
                    'body' => (new HtmlConverter(['strip_tags' => true]))->convert($item->find('description')->text()),
                ];
            }
            return new Collection($newItems);
        } catch (Throwable $e) {
            captureException($e);
            $this->huntress->log->addWarning($e->getMessage(), ['exception' => $e]);
            return new Collection();
        }
    }

    protected function getLastRSS(): Carbon
    {
        $qb = $this->huntress->db->createQueryBuilder();
        $qb->select("*")->from("rss")->where('`id` = ?')->setParameter(0, $this->id, "string");
        $res = $qb->execute()->fetchAll();
        foreach ($res as $data) {
            return new Carbon($data['lastUpdate']);
        }
        return Carbon::createFromTimestamp(0);
    }

    protected function dataPublishingCallback(object $item): bool
    {
        try {
            $embed = new MessageEmbed();
            $embed->setTitle($item->title)->setURL($item->link)->setTimestamp($item->date->timestamp)->setFooter($item->category);
            if ($this->showBody) {
                $embed->setDescription(substr($item->body, 0, 2040));
            }
            if (is_int($this->itemColor)) {
                $embed->setColor($this->itemColor);
            }
            $this->huntress->channels->get($this->channel)->send("", ['embed' => $embed]);
        } catch (Throwable $e) {
            captureException($e);
            $this->huntress->log->addWarning($e->getMessage(), ['exception' => $e]);
            return false;
        }
        return true;
    }

    protected function setLastRSS(Carbon $time)
    {
        if ($this->getLastRSS() >= $time) {
            return;
        }
        $query = $this->huntress->db->prepare('INSERT INTO rss (`id`, `lastUpdate`) VALUES(?, ?) '
            . 'ON DUPLICATE KEY UPDATE `lastUpdate` = VALUES(`lastUpdate`);
        ', ['string', 'datetime']);
        $query->bindValue(1, $this->id);
        $query->bindValue(2, $time);
        $query->execute();
    }

    public function sortObjects($a, $b): int
    {
        return $a->date <=> $b->date;
    }
}
