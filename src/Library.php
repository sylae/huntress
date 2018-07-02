<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

/**
 * Description of Library
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class Library extends \CharlotteDunois\Yasmin\Utils\Collection
{
    public function loadFanfic()
    {
        $x = json_decode("[" . str_replace("}\n{", "},\n{", file_get_contents("worm-whats-new/Fanfic.json") . "]"));
        if (json_last_error() != JSON_ERROR_NONE) {
            throw new \Exception("Unable to load Fanfic.json! " . json_last_error_msg(), json_last_error());
        }
        foreach ($x as $k => $v) {
            $this->set($k, $v);
        }
    }

    public function titleSearch(string $search, int $results = null): array
    {
        if ($results == 1) {
            // do a quick search for an exact match to save time :v
            $key = $this->search(function ($val, $key) use ($search) {
                return (trim(mb_strtolower($search)) == trim(mb_strtolower($val->title)));
            });
            if ($key) {
                return [$this->get($key)];
            }
        }
        $index = $this->sort(function ($a, $b) use ($search) {
            return $this->similarity($search, $b->title) <=> $this->similarity($search, $a->title);
        });
        if (!is_null($results)) {
            return $index->chunk($results)->get(0);
        } else {
            return $index->data;
        }
    }

    public function similarity(string $a, string $b): int
    {
        $a = mb_strtolower($a);
        $b = mb_strtolower($b);
        if ($a == $b) {
            return 1000000;
        }
        return (similar_text($a, $b) * 1000) - levenshtein($a, $b, 1, 5, 10);
    }
}
