<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Yasmin\Models\Guild;
use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Utils\URLHelpers;
use GetOpt\ArgumentException;
use GetOpt\GetOpt;
use GetOpt\Operand;
use Huntress\DatabaseFactory;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Psr\Http\Message\ResponseInterface;
use React\Promise\PromiseInterface as Promise;
use Throwable;
use function React\Promise\reject;

/**
 * Edit user flairs and deal with Wiki stuff...JESUS GOD THIS CODE IS BAD
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class WormRPFlairs implements PluginInterface
{
    use PluginHelperTrait;

    const REP_REGEX = "/([+-]?[0-5X]{1})(A?[A-F])(!?[?+!-]?)/i";
    const TPL_REGEX = "/\\|\\s*?(%s)\\s*?=\\s*?(.*?)[\\n\\|]/im";

    public static function register(Huntress $bot)
    {
        $eh = EventListener::new()
            ->addCommand("rep")
            ->addCommand("reputation")
            ->addGuild(118981144464195584)
            ->setCallback([self::class, "repHandler"]);
        $bot->eventManager->addEventListener($eh);
    }

    public static function fetchAccount(
        Guild $guild,
        string $redditName
    ): ?GuildMember {
        $qb = DatabaseFactory::get()->createQueryBuilder();
        $qb->select("*")->from("wormrp_users")->where('`redditName` = ?')->setParameter(0, $redditName, "string");
        $res = $qb->execute()->fetchAll();
        foreach ($res as $data) {
            return $guild->members->get($data['user']) ?? null;
        }
        return null;
    }

    public static function repHandler(EventData $data): ?Promise
    {
        $message = $data->message;
        if (!$message->member->roles->has(456321111945248779)) {
            return self::unauthorized($message);
        }
        try {
            $getOpt = new GetOpt();
            $getOpt->set(GetOpt::SETTING_SCRIPT_NAME, '!rep');
            $getOpt->set(GetOpt::SETTING_STRICT_OPERANDS, true);

            $getOpt->addOperand((new Operand('character',
                Operand::REQUIRED))->setValidation('is_string'));
            $getOpt->addOperand((new Operand('rep',
                Operand::REQUIRED))->setValidation('is_string'));

            try {
                $args = substr(strstr($message->content, " "), 1);
                $getOpt->process((string) $args);
            } catch (ArgumentException $exception) {
                return self::send($message->channel,
                    $getOpt->getHelpText() . "Note: if you're getting this but everything looks correct, try `!rep -- <character> <rep>` instead.");
            }

            return self::editRep($getOpt->getOperand("character"), $getOpt->getOperand("rep"),
                $message->client, $data)->then(function (
                $res
            ) use ($message) {
                return $res['myMessage']->edit("`{$res['wikipage']}` has been updated.\n{$res['cmd']}");
                // return self::dump($message->channel, $res);
            }, function ($e) use ($message) {
                return self::error($message, "Editing rep failed!", $e);
            });


        } catch (Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }

    private static function editRep(string $char, string $rep, Huntress $bot, EventData $data): ?Promise
    {
        $chain = $data->message->channel->send(":crystal_ball: Checking params...")->then(function ($message) use ($rep, $data) {
            // check rep is valid
            $cd = [];
            $cd['myMessage'] = $message;
            $cd['callingUser'] = $data->message->author->tag;
            $cd['callingMessage'] = $data->message->getJumpURL();

            $matches = [];
            preg_match(self::REP_REGEX, $rep, $matches);
            if (count($matches) != 4) {
                return reject("Invalid rep string `$rep`.");
            }
            $cd['desiredRep'] = $matches;
            return $cd;
        })->then(function (array $cd) use ($char) {
            // check character is valid
            return self::getCanonicalPostTitle($cd, $char);
        })->then(function (array $cd) use ($bot) {
            return self::login($bot, $cd);
        })->then(function (array $cd) use ($bot) {
            // grab a csrf token for the edit
            return URLHelpers::getHTTPClient()->get("https://wormrp.syl.ae/w/api.php?action=query&meta=tokens&format=json",
                ['Cookie' => self::cookieString()])->then(function (
                ResponseInterface $response
            ) use ($cd) {
                // first we get a csrf token...
                self::setCookiesIfApplicable($response);
                $cd['csrf'] = json_decode((string) $response->getBody())->query->tokens->csrftoken;
                return $cd;
            });
        })->then(function (array $cd) use ($char) {
            // actually edit
            return self::editPage($cd, $char);
        })->then(function (array $cd) use ($char) {
            return URLHelpers::getHTTPClient()->get("https://wormrp.syl.ae/w/api.php?action=parse&format=json&text=%7B%7Bflair%7C%2Fu%2F" . urlencode($cd['reddituser']) . "%7D%7D&contentmodel=wikitext",
                ['Cookie' => self::cookieString()])->then(function (
                ResponseInterface $response
            ) use ($cd) {
                // get new flair tag
                self::setCookiesIfApplicable($response);
                $text = json_decode((string) $response->getBody())->parse->text->{'*'};
                $cd['flair'] = trim(html5qp($text, 'p')->text());
                if (mb_strlen($cd['flair']) > 64) {
                    // attempt to shorten it to make it maybe fit.
                    $cd['flair'] = str_replace(" | ", " ", $cd['flair']);
                }
                return $cd;
            });
        })->then(function (array $cd) {
            // push the flair to the subreddit!
            $user = escapeshellarg($cd['reddituser']);
            $flair = escapeshellarg($cd['flair']);
            $cd['cmd'] = `wormrpflair $user $flair`;
            return $cd;
        });
        return $chain;
    }

    private static function getCanonicalPostTitle(array $cd, string $char)
    {
        return URLHelpers::resolveURLToData("https://wormrp.syl.ae/w/api.php?action=ask&format=json&api_version=3&query=[[Identity::" . urlencode($char) . "]]|?Author",
            ['Cookie' => self::cookieString()])->then(function (
            string $data
        ) use ($char, $cd) {
            $data = json_decode($data)->query->results;
            if (count($data) > 1) {
                return reject("Multiple characters matching that name. This shouldn't happen, please @ keira.");
            }
            if (count($data) == 0) {
                return reject("I didn't find anyone with that name. Full list of valid names here: <https://wormrp.syl.ae/wiki/Property:Identity>");
            }

            key($data[0]);
            $cd['wikipage'] = current($data[0])->fulltext;
            $cd['reddituser'] = str_replace("/u/", "", current($data[0])->printouts->Author[0]);
            return $cd;
        }, function ($e) {
            return "I couldn't access the wiki :pensive:";
        });
    }

    private static function cookieString(): ?string
    {
        // todo: validate path etc
        $jar = self::cookiejar();
        $x = [];
        foreach ($jar as $k => $v) {
            $x[] = $k . "=" . $v['data'];
        }
        return implode("; ", $x);

    }

    private static function cookiejar(array $set = null): array
    {
        static $jar = [];
        if (!is_null($set)) {
            $jar = $set;
        }
        return $jar;
    }

    private static function login(Huntress $bot, array $cd): Promise
    {
        $browser = URLHelpers::getHTTPClient();

        // first see if we're already logged in, makes shit mucho easier :)
        return $browser->get("https://wormrp.syl.ae/w/api.php?action=query&meta=userinfo&format=json",
            ['Cookie' => self::cookieString()])->then(function (
            ResponseInterface $response
        ) use ($cd, $browser, $bot) {
            $cd['userinfo'] = json_decode((string) $response->getBody())->query->userinfo;
            if (array_key_exists('anon', $cd['userinfo'])) {
                // actually log in!
                return $browser->get("https://wormrp.syl.ae/w/api.php?action=query&meta=tokens&type=login&format=json",
                    ['Cookie' => self::cookieString()])->then(function (
                    ResponseInterface $response
                ) use ($cd) {
                    // first we get a login token...
                    self::setCookiesIfApplicable($response);
                    $cd['logintoken'] = json_decode((string) $response->getBody())->query->tokens->logintoken;
                    return $cd;
                })->then(function (array $cd) use ($bot, $browser) {
                    // then we actually log in!
                    return $browser->submit("https://wormrp.syl.ae/w/api.php",
                        [
                            'action' => 'login',
                            'format' => 'json',
                            'lgname' => $bot->config['wiki']['username'],
                            'lgpassword' => $bot->config['wiki']['password'],
                            'lgtoken' => $cd['logintoken'],
                        ], ['Cookie' => self::cookieString()])->then(function (
                        ResponseInterface $response
                    ) use ($cd) {
                        self::setCookiesIfApplicable($response);
                        $cd['loginresp2'] = (string) $response->getBody();
                        return $cd;
                    });
                });
            } else {
                // already logged in, yay!
                $cd['usedExistingLogin'] = true;
                return $cd;
            }
        });

    }

    private static function setCookiesIfApplicable(ResponseInterface $response)
    {
        $jar = self::cookiejar();
        if ($response->hasHeader("Set-Cookie")) {
            foreach ($response->getHeader('Set-Cookie') as $cookie) {
                $parts = explode(";", $cookie);
                $first = array_shift($parts);
                list ($name, $data) = explode("=", $first, 2);
                $jar[trim($name)] = ['data' => trim($data)];
                foreach ($parts as $part) {
                    $x = explode("=", $part);
                    $jar[$name][trim($x[0])] = trim($x[1] ?? "");
                }
            }
            self::cookiejar($jar);
        }

    }

    private static function editPage(array $cd, string $char)
    {
        return URLHelpers::resolveURLToData("https://wormrp.syl.ae/w/api.php?action=query&format=json&prop=revisions&titles=" . urlencode($cd['wikipage']) . "&rvprop=content&rvslots=main",
            ['Cookie' => self::cookieString()])->then(function (
            string $data
        ) use ($char, $cd) {
            $data = (array) json_decode($data)->query->pages;
            if (count($data) != 1) {
                return reject("Something's hella fucked up, please @ keira.");
            }

            key($data);
            $cd['content'] = current($data)->revisions[0]->slots->main->{'*'};
            return $cd;
        }, function ($e) {
            return "I couldn't access the wiki :pensive:";
        })->then(function (array $cd) {
            $m1 = preg_match(sprintf(self::TPL_REGEX, "rep_morality"), $cd['content'],);
            $m2 = preg_match(sprintf(self::TPL_REGEX, "rep_notoriety"), $cd['content'],);
            $m3 = preg_match(sprintf(self::TPL_REGEX, "rep_criminal"), $cd['content'],);
            if (!($m1 && $m2 && $m3)) {
                return reject("Could not find template parameters in source wikipage. Please @ keira");
            }
            $c = $cd['content'];
            $c = preg_replace(sprintf(self::TPL_REGEX, "rep_morality"), '|$1 = ' . $cd['desiredRep'][1] . PHP_EOL, $c);
            $c = preg_replace(sprintf(self::TPL_REGEX, "rep_notoriety"),
                '|$1 = ' . mb_strtoupper($cd['desiredRep'][2]) . PHP_EOL, $c);
            $c = preg_replace(sprintf(self::TPL_REGEX, "rep_criminal"), '|$1 = ' . $cd['desiredRep'][3] . PHP_EOL, $c);
            $cd['newContent'] = $c;
            return $cd;
        })->then(function (array $cd) {
            // then we actually, fucking finally, edit the page!
            return URLHelpers::getHTTPClient()->submit("https://wormrp.syl.ae/w/api.php",
                [
                    'action' => 'edit',
                    'format' => 'json',
                    'title' => $cd['wikipage'],
                    'tags' => 'wormrp-rep',
                    'text' => $cd['newContent'],
                    'summary' => "Rep edit on behalf of {$cd['callingUser']} (requested at {$cd['callingMessage']})",
                    'bot' => true,
                    'nocreate' => true,
                    'contentformat' => 'text/x-wiki',
                    'contentmodel' => 'wikitext',
                    'token' => $cd['csrf'],
                ], ['Cookie' => self::cookieString()])->then(function (
                ResponseInterface $response
            ) use ($cd) {
                self::setCookiesIfApplicable($response);
                $cd['editresp'] = json_decode((string) $response->getBody());
                return $cd;
            });
        });
    }

}
