<?php
/*
 * Copyright (c) 2022 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

use Carbon\Carbon;
use CharlotteDunois\Collect\Collection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

class QueueItem
{
    public const STATE_PENDING = 0;
    public const STATE_CLAIMED = 1;
    public const STATE_APPROVED = 2;

    public int $idPost;
    public string $flair;
    public string $author;
    public string $url;
    public string $title;
    public Carbon $postTime;
    public ?Carbon $claimTime = null;
    public ?Carbon $approvalTime = null;
    public ?int $idApprover1 = null;
    public ?int $idApprover2 = null;

    public static function getQueue(Connection $db): Collection
    {
        $query = $db->executeQuery("select * from wormrp_queue order by postTime ASC");
        $c = new Collection();
        while ($res = $query->fetchAssociative()) {
            $x = self::createFromDBrow($res);
            $c->set($x->idPost, $x);
        }
        return $c;
    }

    public static function createFromDBrow(array $res): self
    {
        $x = new self();

        foreach ($res as $k => $v) {
            if (is_null($v)) {
                continue;
            }

            $x->$k = match ($k) {
                "postTime", "claimTime", "approvalTime" => new Carbon($v),
                default => $v,
            };
        }

        return $x;
    }

    public static function getSingleItem(Connection $db, int $id): ?self
    {
        $query = $db->executeQuery(
            "select * from wormrp_queue where idPost = ?",
            [$id],
            [ParameterType::INTEGER]
        );
        if ($res = $query->fetchAssociative()) {
            return self::createFromDBrow($res);
        } else {
            return null;
        }
    }

    public static function getRedditNameFromDiscord(Connection $db, int $idUser): ?string
    {
        $query = $db->executeQuery(
            "select * from wormrp_users where user = ?",
            [$idUser],
            [ParameterType::INTEGER]
        );
        if ($res = $query->fetchAssociative()) {
            return $res['redditName'];
        }
        return null;
    }

    public function getStateClass(): string
    {
        return match ($this->getState()) {
            self::STATE_PENDING => "pending",
            self::STATE_CLAIMED => "claimed",
            self::STATE_APPROVED => "approved",
        };
    }

    public function getState(): int
    {
        if ($this->approvalTime instanceof Carbon) {
            return self::STATE_APPROVED;
        } elseif ($this->claimTime instanceof Carbon) {
            return self::STATE_CLAIMED;
        } else {
            return self::STATE_PENDING;
        }
    }
}