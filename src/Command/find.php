<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Command;

/**
 * Description of ping
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class find extends \Huntress\Command
{

    public function process(): \React\Promise\ExtendedPromiseInterface
    {
        $args    = $this->_split($this->message->content);
        $message = trim(str_replace($args[0], "", $this->message->content));
        $user    = $this->message->member;

        $v = $this->library->titleSearch($message, 1)[0];
        print_r($v);

        $embed = $this->easyEmbed();
        $embed->setTitle($v->title)
                ->setDescription($this->htmlToMD((string) $v->comments))
                ->setColor($user->displayColor);


        if (mb_strlen(trim($v->cover)) > 0) {

            $embed->setThumbnail($v->cover);
        }

        $l = [];
        foreach ($v->links as $link) {
            $l[] = $this->storyURL($link);
        }
        $embed->addField("Links", implode(" - ", $l), true);

        if (mb_strlen(trim($v->author)) > 0) {
            if (mb_strlen(trim($v->authorurl)) > 0) {
                $embed->addField("Author", "[{$v->author}]({$v->authorurl})");
            } else {
                $embed->addField("Author", $v->authorurl, true);
            }
        }
        if (mb_strlen(trim($v->status)) > 0) {
            $embed->addField("Status", $v->status, true);
        }

        $embed->addField("Wordcount", number_format($v->words), true);

        if (count($v->tags) > 0) {
            $embed->addField("Tags", implode(", ", $v->tags));
        }
        return $this->send("", ['embed' => $embed]);
    }

    private function storyURL(string $url): string
    {
        $regex   = "/https?\\:\\/\\/(.+?)\\//i";
        $matches = [];
        if (preg_match($regex, $url, $matches)) {
            switch ($matches[1]) {
                case "forums.spacebattles.com":
                    $tag = "SB";
                    break;
                case "forums.sufficientvelocity.com":
                    $tag = "SV";
                    break;
                case "archiveofourown.org":
                    $tag = "AO3";
                    break;
                case "www.fanfiction.net":
                case "fanfiction.net":
                    $tag = "FFN";
                    break;
                case "forum.questionablequesting.com":
                case "questionablequesting.com":
                    $tag = "QQ";
                    break;
                default:
                    return $url;
            }
            return "[$tag]($url)";
        }
        return $url;
    }
}
