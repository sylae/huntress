<?php
/*
 * Copyright (c) 2021 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;


use Carbon\Carbon;
use CharlotteDunois\Yasmin\Models\TextChannel;

class RSSItem
{
    /**
     * @var string
     */
    public $title;

    /**
     * @var string
     */
    public $link;

    /**
     * @var Carbon
     */
    public $date;

    /**
     * @var string
     */
    public $category;

    /**
     * @var string
     */
    public $body;

    /**
     * @var string
     */
    public $author;

    /**
     * @var int
     */
    public $color;

    /**
     * @var string
     */
    public $image;

    /**
     * @var TextChannel[]
     */
    public $channels;
}
