<?php
/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\Permissions;
use Hoa\Compiler\Llk\Llk;
use Hoa\Compiler\Visitor\Dump;
use Hoa\File\Read;
use Hoa\Visitor\Element;
use Hoa\Visitor\Visit;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface;
use Throwable;

/**
 * Simple dice roller / math robot.
 */
class Dice implements Visit, PluginInterface
{
    use PluginHelperTrait;

    private const KNOWN_DICEBOTS = [
        261302296103747584, // avrae
        559331529378103317, // 5ecrawler
    ];

    public static function register(Huntress $bot)
    {
        $eh = EventListener::new()
            ->addCommand("roll")
            ->setCallback([self::class, "process"]);
        $bot->eventManager->addEventListener($eh);

        $eh = EventListener::new()
            ->addCommand("rolldebug")
            ->setCallback([self::class, "processDebug"]);
        $bot->eventManager->addEventListener($eh);

        $eh = EventListener::new()
            ->addCommand("succ")
            ->setCallback([self::class, "v20Handler"]);
        $bot->eventManager->addEventListener($eh);

        $eh = EventListener::new()
            ->addCommand("rollchar")
            ->setCallback([self::class, "rollChar"]);
        $bot->eventManager->addEventListener($eh);
    }

    public static function rollChar(EventData $data): ?PromiseInterface
    {
        $x = [];
        $n = 0;
        while ($n < 6) {
            $n++;
            $d = [];
            $d[] = random_int(1, 6);
            $d[] = random_int(1, 6);
            $d[] = random_int(1, 6);
            $d[] = random_int(1, 6);
            arsort($d);
            $x[] = array_values($d);
        }

        $send = implode("\n", array_map(function ($v) {
            $low = array_pop($v);
            $x = implode(", ", $v);
            $x .= ", ~~" . $low . "~~ = ";
            $x .= array_sum($v);
            return $x;
        }, $x));

        return $data->message->channel->send(sprintf("%s rolled a character!\n%s", $data->message->member, $send));
    }

    public static function processDebug(EventData $data)
    {
        return self::process($data, true);
    }

    public static function process(EventData $data, bool $useDebug = false)
    {
        try {
            // don't do anything if another dicebot with the same prefix is here!
            $count = self::getMembersWithPermission($data->channel,
                Permissions::PERMISSIONS['SEND_MESSAGES'] | Permissions::PERMISSIONS['VIEW_CHANNEL'])->filter(function (GuildMember $v) {
                return in_array($v->id, self::KNOWN_DICEBOTS);
            })->count();
            if ($count > 0) {
                $data->huntress->log->info("Not rolling due to another bot with matching prefix.");
                return;
            }

            $p = new Permission("p.dice.roll", $data->huntress, true);
            $p->addMessageContext($data->message);
            if (!$p->resolve()) {
                return $p->sendUnauthorizedMessage($data->message->channel);
            }

            $roll = trim(self::arg_substr($data->message->content, 1));
            if (mb_strlen($roll) == 0) {
                return self::send($data->channel, ":thinking:");
            }
            $debug = "";
            $result = self::rollDice($roll, $debug);
            $data->message->client->log->debug($debug);
            return self::send($data->channel, sprintf("%s rolled `%s` : %s%s", $data->message->author, $roll, $result,
                ($useDebug) ? "\n```\n" . $debug . "\n```" : ""));
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, false);
        }
    }

    public static function rollDice(string $in, string &$debug = ""): string
    {
        // 1. Load grammar.
        $compiler = Llk::load(new Read('data/dice.pp'));

        // 2. Parse a data.
        $ast = $compiler->parse($in);

        // 3. Dump the AST.
        $dump = new Dump();
        $debug = $dump->visit($ast);

        $parser = new self();
        return $parser->visit($ast);
    }

    /**
     * This code is deadass like 900% copied from the hoa/compiler docs. I'm real bad at smart stuff.
     */
    public function visit(Element $element, &$handle = null, $eldnah = null)
    {
        $type = $element->getId();
        $children = $element->getChildren();
        if (null === $handle) {
            $handle = function ($x = null) {
                return $x;
            };
        }
        $acc = &$handle;
        switch ($type) {
            case '#negative':
                $children[0]->accept($this, $a, $eldnah);
                $acc = function () use ($a, $acc) {
                    return $acc(-$a());
                };
                break;
            case '#addition':
                $children[0]->accept($this, $a, $eldnah);
                $acc = function ($b) use ($a, $acc) {
                    return $acc($a() + $b);
                };
                $children[1]->accept($this, $acc, $eldnah);
                break;
            case '#substraction':
                $children[0]->accept($this, $a, $eldnah);
                $acc = function ($b) use ($a, $acc) {
                    return $acc($a()) - $b;
                };
                $children[1]->accept($this, $acc, $eldnah);
                break;
            case '#multiplication':
                $children[0]->accept($this, $a, $eldnah);
                $acc = function ($b) use ($a, $acc) {
                    return $acc($a() * $b);
                };
                $children[1]->accept($this, $acc, $eldnah);
                break;
            case '#roll':
                $children[0]->accept($this, $a, $eldnah);
                $acc = function ($b) use ($a, $acc) {
                    $size = (int) $b;
                    $num = (int) $a();
                    if ($size < 1 || $num < 1) {
                        throw new \RuntimeException('Dice can only use positive integers.');
                    }
                    $vals = [];
                    for ($i = 0; $i < $num; $i++) {
                        $vals[] = random_int(1, $size);
                    }
                    return $acc(array_sum($vals));
                };
                $children[1]->accept($this, $acc, $eldnah);
                break;
            case '#division':
                $children[0]->accept($this, $a, $eldnah);
                $parent = $element->getParent();
                if (null === $parent ||
                    $type === $parent->getId()) {
                    $acc = function ($b) use ($a, $acc) {
                        if (0.0 === $b) {
                            throw new \RuntimeException('Division by zero is not possible.');
                        }
                        return $acc($a()) / $b;
                    };
                } else {
                    if ('#fakegroup' !== $parent->getId()) {
                        $classname = get_class($element);
                        $group = new $classname(
                            '#fakegroup',
                            null,
                            [$element],
                            $parent
                        );
                        $element->setParent($group);
                        $this->visit($group, $acc, $eldnah);
                        break;
                    } else {
                        $acc = function ($b) use ($a, $acc) {
                            if (0.0 === $b) {
                                throw new \RuntimeException('Division by zero is not possible.');
                            }
                            return $acc($a() / $b);
                        };
                    }
                }
                $children[1]->accept($this, $acc, $eldnah);
                break;
            case '#exp':
                $children[0]->accept($this, $a, $eldnah);
                $acc = function ($b) use ($a, $acc) {
                    return $acc($a() ** $b);
                };
                $children[1]->accept($this, $acc, $eldnah);
                break;
            case '#fakegroup':
            case '#group':
                $children[0]->accept($this, $a, $eldnah);
                $acc = function () use ($a, $acc) {
                    return $acc($a());
                };
                break;
            case 'token':
                $value = $element->getValueValue();
                $out = null;
                if ('id' === $element->getValueToken()) {
                    return $value;
                } else {
                    $out = (float) $value;
                }
                $acc = function () use ($out, $acc) {
                    return $acc($out);
                };
                break;
        }
        if (null === $element->getParent()) {
            return $acc();
        }
    }

    public static function v20Handler(EventData $data)
    {
        try {
            $p = new Permission("p.dice.roll.v20", $data->huntress, true);
            $p->addMessageContext($data->message);
            if (!$p->resolve()) {
                return $p->sendUnauthorizedMessage($data->message->channel);
            }

            $usage = "Usage: `!succ numDice [difficulty=6] [spec]`";

            $parts = self::_split($data->message->content);
            if (count($parts) < 2 || count($parts) > 4 || !is_numeric($parts[1])) {
                return $data->message->channel->send($usage);
            } else {
                $num = $parts[1];
                if ($num < 1 || $num > 100) {
                    return $data->message->channel->send("wtf how many dice is this");
                }
            }


            $diff = 6;
            if (count($parts) > 2 && is_numeric($parts[2])) {
                $diff = $parts[2];
                if ($num < 1 || $num > 100) {
                    return $data->message->channel->send("difficulty what the fuck");
                }
            }

            $spec = false;
            if (count($parts) == 4 && $parts[3] == "spec") {
                $spec = true;
            }

            $rolls = [];
            $succs = 0;
            $count = 0;

            while ($count < $num) {
                $count++;
                $r = random_int(1, 10);
                if ($r == 1) {
                    $succs--;
                    $rolls[] = "~~**1**~~";
                } elseif ($r == 10 && $spec) {
                    $succs++;
                    $succs++;
                    $rolls[] = "**10**";
                } else {
                    if ($r >= $diff) {
                        $succs++;
                        $rolls[] = $r;
                    } else {
                        $rolls[] = "~~$r~~";
                    }
                }
            }

            $specmsg = $spec ? " (with specialty)" : "";
            $message = sprintf("%s rolled **%s** dice at difficulty **%s**%s\n**%s successes** (%s)",
                $data->message->member,
                $num,
                $diff, $specmsg, $succs, implode(", ", $rolls));
            return $data->message->channel->send($message);
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e, false);
        }
    }
}
