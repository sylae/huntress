<?php

/*
 * Copyright (c) 2019-2026 MisfitMaid and contributors.
 *
 * Use of this source code is governed by the MIT Non-AI license, which can be found in the LICENSE file.
 */

namespace Huntress;

use Carbon\Carbon;
use Discord\Parts\User\User;

class UserLocale
{
    public ?string $timezone;
    public ?string $locale;

    public function __construct(User $user)
    {
        $qb = DatabaseFactory::get()->createQueryBuilder();
        $data = $qb->select("*")->from("locale")->where("user = ?")->setParameter(0, $user->id,
            "integer")->executeQuery()->fetchAllAssociative();
        foreach ($data as $d) {
            $this->timezone = $d['timezone'];
            $this->locale = $d['locale'];
        }
    }

    public function applyTimezone(Carbon $time): Carbon
    {
        return $time->setTimezone($this->timezone ?? "UTC");
    }

    public function localeSandbox(callable $sandbox): string
    {
        return Carbon::executeWithLocale($this->locale, $sandbox);
    }
}
