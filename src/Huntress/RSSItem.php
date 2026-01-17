<?php
/*
 * Copyright (c) 2021-2026 MisfitMaid and contributors.
 *
 * Use of this source code is governed by the MIT Non-AI license, which can be found in the LICENSE file.
 */

namespace Huntress;


use AllowDynamicProperties;
use Carbon\Carbon;
use Discord\Parts\Channel\Channel;

#[AllowDynamicProperties]
class RSSItem
{
    public ?string $title;

    public ?string $link;

    public ?Carbon $date;

    public ?string $category;

    public ?string $body;

    public ?string $author;

    public ?int $color;
    public ?string $image;

    /**
     * @var Channel[]
     */
    public ?array $channels;
}
