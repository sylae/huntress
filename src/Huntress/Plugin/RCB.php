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
                if ($item->kind == "t1") { // comment
                    $newItems[] = (object)[
                        'title' => "Comment on post: " . $item->data->link_title,
                        'link' => "https://www.reddit.com" . $item->data->permalink,
                        'date' => $published,
                        'category' => $item->data->link_flair_text ?? "Unflaired",
                        'body' => $item->data->body,
                        'author' => $item->data->author,
                        'isImage' => false,
                    ];
                } elseif ($item->kind == "t3") { // post
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
                ->setTimestamp($item->date->timestamp)->setAuthor($item->author, '',
                    "https://reddit.com/user/" . $item->author);
            if ($item->isImage) {
                $embed->setImage($item->body);
            }
            foreach ($this->channels as $channel) {
                $this->huntress->channels->get($channel)->send("", ['embed' => $embed]);
            }
        } catch (Throwable $e) {
            $this->huntress->log->warning($e->getMessage(), ['exception' => $e]);
            return false;
        }
        return true;
    }

}
