<?php

/*
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

use \Carbon\Carbon;
use \CharlotteDunois\Collect\Collection;
use \CharlotteDunois\Yasmin\Models\MessageEmbed;

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

    public function __construct(Huntress $bot, string $id, string $url, int $interval, int $channel)
    {
        $this->huntress = $bot;
        $this->id       = $id;
        $this->url      = $url;
        $this->interval = $interval;
        $this->channel  = $channel;

        $this->huntress->eventManager->addURLEvent($this->url, $this->interval, [$this, 'eventManagerCallback']);
    }

    public function eventManagerCallback(string $string, Huntress $bot)
    {
        $collect = $this->dataProcessingCallback($string);
        foreach ($collect as $item) {
            $this->dataPublishingCallback($item);
        }
        if ($collect->count() > 0) {
            $this->setLastRSS($collect->max('date'));
        }
    }

    private function dataProcessingCallback(string $string): Collection
    {
        try {
            $data     = \qp($string);
            $items    = $data->find('item');
            $lastPub  = $this->getLastRSS();
            $newest   = $lastPub;
            $newItems = [];
            foreach ($items as $item) {
                $published = new \Carbon\Carbon($item->find('pubDate')->text());
                if ($published <= $lastPub) {
                    continue;
                }
                $newest     = max($newest, $published);
                $newItems[] = (object) [// todo: make this its own class
                    'title'    => $item->find('title')->text(),
                    'link'     => $item->find('link')->text(),
                    'date'     => $published,
                    'category' => $item->find('category')->text(),
                    'body'     => (new \League\HTMLToMarkdown\HtmlConverter(['strip_tags' => true]))->convert($item->find('description')->text()),
                ];
            }
            return new Collection($newItems);
        } catch (\Throwable $e) {
            \Sentry\captureException($e);
            $this->huntress->log->addWarning($e->getMessage(), ['exception' => $e]);
        }
    }

    private function dataPublishingCallback(object $item): bool
    {
        try {
            $embed = new MessageEmbed();
            $embed->setTitle($item->title)->setURL($item->link)->setDescription($item->body)->setTimestamp($item->date->timestamp)->setFooter($item->category);
            if (is_int($this->itemColor)) {
                $embed->setColor($this->itemColor);
            }
            $this->huntress->channels->get($this->channel)->send("", ['embed' => $embed]);
        } catch (\Throwable $e) {
            \Sentry\captureException($e);
            $this->huntress->log->addWarning($e->getMessage(), ['exception' => $e]);
            return false;
        }
        return true;
    }

    private function getLastRSS(): Carbon
    {
        $qb  = $this->huntress->db->createQueryBuilder();
        $qb->select("*")->from("rss")->where('`id` = ?')->setParameter(0, $this->id, "string");
        $res = $qb->execute()->fetchAll();
        foreach ($res as $data) {
            return new Carbon($data['lastUpdate']);
        }
        return Carbon::createFromTimestamp(0);
    }

    private function setLastRSS(Carbon $time)
    {
        $query = $this->huntress->db->prepare('INSERT INTO rss (`id`, `lastUpdate`) VALUES(?, ?) '
        . 'ON DUPLICATE KEY UPDATE `lastUpdate` = VALUES(`lastUpdate`);
        ', ['string', 'datetime']);
        $query->bindValue(1, $this->id);
        $query->bindValue(2, max($time, $this->getLastRSS()));
        $query->execute();
    }

    public static function db(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        $t = $schema->createTable("rss");
        $t->addColumn("id", "string", ['length' => 255, 'customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t->addColumn("lastUpdate", "datetime");
        $t->addIndex(["id"]);
    }

    public static function register(Huntress $bot)
    {
        $dbEv = EventListener::new()->addEvent("dbSchema")->setCallback([self::class, 'db']);
        $bot->eventManager->addEventListener($dbEv);
    }
}
