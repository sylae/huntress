<?php
/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

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
        'p' => 'prof',
        's' => 'setback',
        'd' => 'diff',
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
        "prof_triumph" => [744576081163452516, []],

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
        'prof' => [
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
        'diff' => [
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
        "threat" => 744616260427579402,
        "triumph" => 744616260482105355,
        "advantage" => 744616260440031253,
        "despair" => 744616260243030059,
        "failure" => 744616260461133955,
    ];

    public static function register(Huntress $bot)
    {

        $eh = EventListener::new()
            ->addCommand("g")
            ->addCommand("genesys")
            ->setCallback([self::class, "genysysHandler"]);
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

            $usage = "Usage: `!g numDice [difficulty=6] [spec]`";

        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, false);
        }
    }
}
