<?php
/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Yasmin\Models\MessageEmbed;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Throwable;

/**
 * Simple dice roller / math robot.
 */
class GenesysDice implements PluginInterface
{
    use PluginHelperTrait;

    const rollTypes = [
        'b' => 'boost',
        'a' => 'ability',
        'p' => 'proficiency',
        's' => 'setback',
        'd' => 'difficulty',
        'c' => 'challenge',
    ];

    const diceEmoteMap = [
        "boost_blank" => [744576080261939300, []],
        "boost_adv" => [744576079880257616, ["advantage"]],
        "boost_adv2" => [744576079867412550, ["advantage", "advantage"]],
        "boost_succ" => [744576080178053251, ["success"]],
        "boost_succadv" => [744576080286842970, ["success", "advantage"]],

        "ability_blank" => [744576079746039948, []],
        "ability_adv" => [744576079431204875, ["advantage"]],
        "ability_adv2" => [744576079913680906, ["advantage", "advantage"]],
        "ability_succ" => [744576079879995472, ["success"]],
        "ability_succ2" => [744576079850897448, ["success", "success"]],
        "ability_succadv" => [744576079879995502, ["success", "advantage"]],

        "prof_blank" => [744576080190505033, []],
        "prof_adv" => [744576080828039268, ["advantage"]],
        "prof_adv2" => [744576080211476521, ["advantage", "advantage"]],
        "prof_succ" => [744576080601677945, ["success"]],
        "prof_succ2" => [744576080576249917, ["success", "success"]],
        "prof_succadv" => [744576080622387201, ["advantage"]],
        "prof_triumph" => [744576081163452516, ["triumph"]],

        "setback_blank" => [744576080408608818, []],
        "setback_fail" => [744576080920313903, ["failure"]],
        "setback_threat" => [744576080706535444, ["threat"]],

        "diff_blank" => [744576080266002513, []],
        "diff_fail" => [744576080471654411, ["failure"]],
        "diff_fail2" => [744576080878501908, ["failure", "failure"]],
        "diff_failthreat" => [744576080534306908, ["failure", "threat"]],
        "diff_threat" => [744576080601677835, ["threat"]],
        "diff_threat2" => [744576080702341141, ["threat", "threat"]],

        "challenge_blank" => [744576079947104338, []],
        "challenge_despair" => [744576080660136024, ["despair"]],
        "challenge_fail" => [744576080697884853, ["failure"]],
        "challenge_fail2" => [744576080605872249, ["failure", "failure"]],
        "challenge_failthreat" => [744576080744022046, ["failure", "threat"]],
        "challenge_threat" => [744576080316334172, ["threat"]],
        "challenge_threat2" => [744576080446488597, ["threat", "threat"]],
    ];

    const dicePipMap = [
        'boost' => [
            "boost_blank",
            "boost_blank",
            "boost_succ",
            "boost_succadv",
            "boost_adv2",
            "boost_adv",
        ],
        'ability' => [
            "ability_blank",
            "ability_succ",
            "ability_succ",
            "ability_succ2",
            "ability_adv",
            "ability_adv",
            "ability_succadv",
            "ability_adv2",
        ],
        'proficiency' => [
            "prof_blank",
            "prof_succ",
            "prof_succ",
            "prof_succ2",
            "prof_succ2",
            "prof_adv",
            "prof_succadv",
            "prof_succadv",
            "prof_succadv",
            "prof_adv2",
            "prof_adv2",
            "prof_triumph",
        ],
        'setback' => [
            "setback_blank",
            "setback_blank",
            "setback_fail",
            "setback_fail",
            "setback_threat",
            "setback_threat",
        ],
        'difficulty' => [
            "diff_blank",
            "diff_fail",
            "diff_fail2",
            "diff_threat",
            "diff_threat",
            "diff_threat",
            "diff_threat2",
            "diff_failthreat",
        ],
        'challenge' => [
            "challenge_blank",
            "challenge_fail",
            "challenge_fail",
            "challenge_fail2",
            "challenge_fail2",
            "challenge_threat",
            "challenge_threat",
            "challenge_failthreat",
            "challenge_failthreat",
            "challenge_threat2",
            "challenge_threat2",
            "challenge_despair",
        ],
    ];

    const symbolEmoteMap = [
        "success" => 744616260364665015,
        "advantage" => 744616260440031253,
        "triumph" => 744616260482105355,
        "failure" => 744616260461133955,
        "threat" => 744616260427579402,
        "despair" => 744616260243030059,
    ];

    public static function register(Huntress $bot)
    {

        $eh = EventListener::new()
            // ->addCommand("g")
            ->addCommand("genesys")
            ->setCallback([self::class, "genesysHandler"]);
        $bot->eventManager->addEventListener($eh);
    }

    public static function genesysHandler(EventData $data)
    {
        try {
            $p = new Permission("p.dice.roll.genesys", $data->huntress, true);
            $p->addMessageContext($data->message);
            if (!$p->resolve()) {
                return $p->sendUnauthorizedMessage($data->message->channel);
            }

            $string = self::arg_substr($data->message->content, 1);
            $pool = array_fill_keys(array_values(self::rollTypes), 0);
            foreach (mb_str_split($string) as $letter) {
                if (!array_key_exists($letter, self::rollTypes)) {
                    return self::error($data->message, "Invalid dice",
                        "I don't know what `$letter` is supposed to be.\n\n" . self::usage());
                }
                $pool[self::rollTypes[$letter]]++;
            }

            if (array_sum($pool) == 0) {
                return $data->message->reply(self::usage());
            }

            $rolls = array_fill_keys(array_keys(self::dicePipMap), []);
            $res = array_fill_keys(array_keys(self::symbolEmoteMap), 0);
            foreach ($pool as $type => $count) {
                $opts = self::dicePipMap[$type];
                $x = 0;
                while ($x < $count) {
                    $x++;
                    $roll = self::dicePipMap[$type][random_int(0, count(self::dicePipMap[$type]) - 1)];
                    $rolls[$type][] = $roll;
                    $pips = self::diceEmoteMap[$roll][1];
                    foreach ($pips as $pip) {
                        $res[$pip]++;
                    }
                }
            }

            // roll is done! let's doll it up
            $prettyResult = [];
            foreach ($rolls as $name => $results) {
                foreach ($results as $r) {
                    $prettyResult[] = (string)$data->huntress->emojis->get(self::diceEmoteMap[$r][0]);
                }
            }


            $embed = new MessageEmbed();
            $embed->setAuthor($data->message->member->displayName,
                $data->message->member->user->getAvatarURL(64) ?? null);
            $embed->setColor($data->message->member->id % 0xFFFFFF);
            $embed->setTimestamp(time());

            foreach ($res as $type => $count) {
                if ($count == 0) {
                    $embed->addField(mb_convert_case($type, MB_CASE_TITLE), "<a:blank:504961427967311873>", true);
                    continue;
                }
                $x = str_repeat((string)$data->huntress->emojis->get(self::symbolEmoteMap[$type]), $count);
                $embed->addField(mb_convert_case($type, MB_CASE_TITLE), $x, true);
            }

            return $data->message->reply(implode("", $prettyResult), ['embed' => $embed]);

        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, false);
        }
    }

    private static function usage(): string
    {
        $usage = "Usage: `!genesys (pool)`\n(pool) is a combination of letters:\n";
        foreach (self::rollTypes as $type) {
            $usage .= "- __" . mb_substr($type, 0, 1) . "__" . mb_substr($type, 1) . "\n";
        }
        return $usage;
    }
}
