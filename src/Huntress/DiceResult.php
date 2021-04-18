<?php
/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;


use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\MessageEmbed;

class DiceResult
{
    /**
     * @var string
     */
    public $input;
    /**
     * @var array
     */
    public $results;
    /**
     * @var int
     */
    public $rollType;
    /**
     * @var int
     */
    public $n;
    /**
     * @var int
     */
    public $d;
    /**
     * @var array
     */
    public $mods;

    /**
     * @var GuildMember
     */
    public $member;

    /**
     * @var string
     */
    public $userRemarks;

    /**
     * @var string
     */
    public $overrideName;

    /**
     * @var string
     */
    public $overridePic;

    /**
     * @var int
     */
    public $overrideColor;

    public function giveEmbed(): MessageEmbed
    {
        $embed = new MessageEmbed();
        if (is_string($this->overrideName)) {
            $embed->setAuthor($this->overrideName, $this->overridePic ?? $this->member->user->getDisplayAvatarURL(64) ?? null);
        } elseif ($this->member instanceof GuildMember) {
            $embed->setAuthor($this->member->displayName, $this->member->user->getDisplayAvatarURL(64) ?? null);
        }

        if (!is_null($this->overrideColor)) {
            $embed->setColor($this->overrideColor);
        } elseif ($this->member instanceof GuildMember) {
            $embed->setColor($this->member->id % 0xFFFFFF);
        }

        $embed->setTimestamp(time());
        $embed->setTitle($this->getNormalizedInputString());
        $embed->setDescription($this->getNormalizedResultString());

        return $embed;
    }

    public function getNormalizedInputString(): string
    {

        if ($this->rollType == DiceHandler::DICE_ADV) {
            $type = "with advantage";
        } elseif ($this->rollType == DiceHandler::DICE_DISADV) {
            $type = "with disadvantage";
        } else {
            $type = "";
        }

        if (mb_strlen(trim($this->userRemarks)) > 0) {
            $remark = "({$this->userRemarks}) ";
        } else {
            $remark = "";
        }

        return sprintf("%s%sd%s %s %s", $remark, $this->n, $this->d, implode(" ", $this->mods), $type);
    }

    public function getNormalizedResultString(): string
    {
        $fmt = [];
        $sum = array_sum($this->mods);
        foreach ($this->results as $r) {
            $fmt[] = $r[1];
            $sum += $r[0];
        }

        return sprintf("%s %s = %s", implode(",", $fmt), implode(" ", $this->mods), $sum);
    }
}
