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
class lookup extends \Huntress\Command
{

    public function process(): \React\Promise\ExtendedPromiseInterface
    {
        $args    = $this->_split($this->message->content);
        $message = trim(str_replace($args[0], "", $this->message->content));
        $user    = $this->message->member;

        $res = $this->library->titleSearch($message, 10);

        $embed = $this->easyEmbed();
        $embed->setTitle("Browsing stories matching: `$message`")
                ->setDescription("For more details, use `!find FIC NAME`")
                ->setColor($user->displayColor);
        foreach ($res as $k => $v) {
            $title = [];
            $data  = [];

            $title[] = "{$v->title}";
            if (mb_strlen(trim($v->author)) > 0) {
                $title[] = "*by {$v->author}*";
            }
            if (mb_strlen(trim($v->status)) > 0) {
                $title[] = "*({$v->status})*";
            }

            $data[] = "*(" . number_format($v->words) . " words)*";
            if (mb_strlen(trim($v->comments)) > 0) {
                $data[] = "*(" . $this->htmlToMD((string) $v->comments) . ")*";
            }
            if (count($v->tags) > 0) {
                $data[] = "\n__Tagged__: " . implode(", ", $v->tags);
            }
            $data[] = "\n";

            $embed->addField(implode(" ", $title), implode(" ", $data));
            var_dump($v);
        }
        return $this->send("", ['embed' => $embed]);
    }
}
