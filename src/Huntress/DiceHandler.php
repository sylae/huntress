<?php
/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;


class DiceHandler
{
    const DICE_NORMAL = 1;
    const DICE_ADV = 2;
    const DICE_DISADV = 4;

    const REGEX_HELL = '/(\d*)\s*d\s*(\d+)((?:\s*[+-]\s*\d+)*)(?:\s+(advantage|adv|disadvantage|dis))?(?:\s+(.*))?/i';
    const REGEX_MODS = '/([+-])\s*(\d+)/i';

    public static function fromString(string $input): DiceResult
    {
        if (preg_match(self::REGEX_HELL, $input, $matches)) {
            $n = (is_numeric($matches[1])) ? (int)$matches[1] : 1;
            $d = (int)$matches[2];
            $mods = self::normalizeMods($matches[3] ?? "");
            $adv = self::parseAdvDisadvString($matches[4] ?? "");
            $remarks = trim($matches[5] ?? "");

            $res = self::roll($n, $d, $adv);

            $result = new DiceResult();
            $result->n = $n;
            $result->d = $d;
            $result->rollType = $adv;
            $result->mods = $mods;
            $result->results = $res;
            $result->input = $input;
            $result->userRemarks = $remarks;

            return $result;
        } else {
            throw new \InvalidArgumentException();
        }
    }

    private static function normalizeMods(string $in): array
    {
        $m = [];
        preg_match_all(self::REGEX_MODS, $in, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $m[] = ($match[1] . $match[2]);
        }
        return $m;
    }

    private static function parseAdvDisadvString(string $in): int
    {
        $in = trim($in);
        if (mb_strlen($in) == 0) {
            return self::DICE_NORMAL;
        } elseif (stripos($in, 'a') === 0) {
            return self::DICE_ADV;
        } elseif (stripos($in, 'd') === 0) {
            return self::DICE_DISADV;
        } else {
            throw new \Exception("idk what this is sauce boss");
        }
    }

    public static function roll(int $n, int $d, int $type = self::DICE_NORMAL): array
    {
        // sanity check
        if ($n < 1 || $d < 1) {
            throw new \Exception("Due to laws of physics, number and sides of dice must be positive integers");
        }

        $results = [];
        $x = 0;
        while ($x < $n) {
            switch ($type) {
                case self::DICE_NORMAL:
                    $raw = random_int(1, $d);
                    $results[$x] = [$raw, self::formatNumber($raw, $d)];
                    break;
                case self::DICE_ADV:
                case self::DICE_DISADV:
                    $a = random_int(1, $d);
                    $b = random_int(1, $d);
                    if ($type == self::DICE_ADV) {
                        $raw = max($a, $b);
                    } else {
                        $raw = min($a, $b);
                    }
                    $results[$x] = [$raw, self::formatAdvDis($a, $b, $d, $type)];
            }
            $x++;
        }
        return $results;
    }

    private static function formatNumber(int $raw, int $d): string
    {
        if ($raw >= $d) {
            return "**$raw**";
        }
        return (string)$raw;
    }

    public static function formatAdvDis(int $a, int $b, int $d, int $type): string
    {
        $winner = $a <=> $b;
        $af = self::formatNumber($a, $d);
        $bf = self::formatNumber($b, $d);
        if ($type == self::DICE_DISADV) {
            $winner *= -1;
        }

        if ($winner == 0) {
            return sprintf("(%s, %s)", $af, $bf);
        } elseif ($winner == 1) {
            return sprintf("(%s, ~~%s~~)", $af, $bf);
        } elseif ($winner == -1) {
            return sprintf("(~~%s~~, %s)", $af, $bf);
        }
    }
}
