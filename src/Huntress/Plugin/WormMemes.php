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
use Huntress\RedditProcessor;
use Huntress\RSSProcessor;
use Throwable;
use function Sentry\captureException;

class WormMemes extends RedditProcessor implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        if (self::isTestingClient()) {
            $bot->log->debug("Not adding RSS event on testing.");
        } else {
            new self($bot, "rcbWormMemes", "WormMemes", 30, []);
            new RSSProcessor($bot, 'wyldblowWormMemes', "https://queryfeed.net/tw?q=%40wyldblow", 30,
                [609585631818940427]);
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
                $newItems[] = (object)[
                    'title' => $item->data->title,
                    'link' => "https://www.reddit.com" . $item->data->permalink,
                    'date' => $published,
                    'category' => $item->data->link_flair_text ?? "Unflaired",
                    'body' => (strlen($item->data->selftext) > 0) ? $item->data->selftext : $item->data->url,
                    'author' => $item->data->author,
                    'isImage' => (!strlen($item->data->selftext) > 0) && $this->checkExtension($item->data->url),
                ];
            }
            return new Collection($newItems);
        } catch (Throwable $e) {
            captureException($e);
            $this->huntress->log->warning($e->getMessage(), ['exception' => $e]);
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
                case "Ward":
                    $channel = 705397126775177217;
                    break;
                case "Pact":
                    $channel = 620845142382739467;
                    break;
                case "Pale":
                case "Poof":
                    $channel = 707103734018342923;
                    break;
                case "Twig":
                    $channel = 621183537424498718;
                    break;
                case "Worm":
                case "Meta":
                default:
                    $channel = 608108475037384708;
            }

            $embed = new MessageEmbed();
            $embed->setTitle($item->title)->setURL($item->link)->setDescription($item->body)->setFooter($item->category)
                ->setTimestamp($item->date->timestamp);

            $embed->setAuthor($item->author, '', "https://reddit.com/user/" . $item->author);


            if ($item->isImage) {
                $embed->setImage($item->body);
            }

            // appropriate wormmemes channel
            $this->huntress->channels->get($channel)->send("", ['embed' => $embed]);
        } catch (Throwable $e) {
            captureException($e);
            $this->huntress->log->warning($e->getMessage(), ['exception' => $e]);
            return false;
        }
        return true;
    }
}