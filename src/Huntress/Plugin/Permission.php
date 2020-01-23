<?php
/**
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Yasmin\Models\Permissions;
use GetOpt\ArgumentException;
use GetOpt\Command;
use GetOpt\GetOpt;
use GetOpt\Operand;
use GetOpt\Option;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission as P;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface as Promise;
use Throwable;


class Permission implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(EventListener::new()
            ->addCommand("permission")
            ->addCommand("perm")
            ->addCommand("hpm")
            ->setCallback([self::class, "hpm"])
        );
    }

    public static function hpm(EventData $data): ?Promise
    {
        try {
            $getOpt = new GetOpt();
            $getOpt->set(GetOpt::SETTING_SCRIPT_NAME, '!permission');
            $getOpt->set(GetOpt::SETTING_STRICT_OPERANDS, true);
            $commands = [];
            $commands[] = Command::create('check',
                [self::class, 'checkPerm'])->setDescription('Show the promise resolution chain')->addOperands([
                (new Operand('permission',
                    Operand::REQUIRED))->setValidation('is_string')->setDescription('The name of the permission to check.'),
                (new Operand('user',
                    Operand::OPTIONAL))->setValidation('is_string')->setDefaultValue("@self")->setDescription('The user whose permissions you want to check. Defaults to self.'),
            ])->addOptions([
                (new Option('a', 'all',
                    GetOpt::NO_ARGUMENT))->setDescription("Show all checks even if irrelevant."),
            ]);
            $commands[] = Command::create('add',
                [self::class, 'addPerm'])->setDescription('Add a permission to the database')->addOperands([
                (new Operand('permission',
                    Operand::REQUIRED))->setValidation('is_string')->setDescription('The name of the permission to add.'),
            ])->addOptions([
                    (new Option('d', 'deny',
                        GetOpt::NO_ARGUMENT))->setDescription("Deny the permission instead of allowing it."),
                    (new Option(null, 'scope',
                        GetOpt::OPTIONAL_ARGUMENT))->setDefaultValue("guild")->setDescription("Set the scope.")->setArgumentName("global|guild|channel"),
                    (new Option(null, 'all',
                        GetOpt::REQUIRED_ARGUMENT))->setDescription("Target all users within the scope."),
                    (new Option(null, 'role',
                        GetOpt::REQUIRED_ARGUMENT))->setDescription("Target a particular role, specified as an @mention.")->setArgumentName("@role"),
                    (new Option(null, 'user',
                        GetOpt::REQUIRED_ARGUMENT))->setDescription("Target a particular user specified in a format Huntress can understand.")->setArgumentName("username"),
                    (new Option(null, 'admins',
                        GetOpt::OPTIONAL_ARGUMENT))->setDescription("Target guild owners and users with the ADMINISTRATOR discord permission."),
                    (new Option(null, 'evalusers',
                        GetOpt::OPTIONAL_ARGUMENT))->setDescription("Target bot owners within the scope."),
                ]
            );
            $getOpt->addCommands($commands);
            try {
                $args = substr(strstr($data->message->content, " "), 1);
                $getOpt->process((string) $args);
            } catch (ArgumentException $exception) {
                return self::send($data->message->channel, "```\n" . $getOpt->getHelpText() . "```");
            }
            $command = $getOpt->getCommand();
            if (is_null($command)) {
                return self::send($data->message->channel, "```\n" . $getOpt->getHelpText() . "```");
            }
            return call_user_func($command->getHandler(), $getOpt, $data);
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e);
        }
    }

    public static function checkPerm(GetOpt $getOpt, EventData $data): ?Promise
    {
        if ($getOpt->getOperand("user") === "@self") {
            $user = $data->message->member;
        } else {
            $user = self::parseGuildUser($data->guild, $getOpt->getOperand("user"));
        }
        if (is_null($user)) {
            return self::error($data->message, "Unknown User",
                "Could not recognize the given user. Try using their Tag#1234, or barring that a @mention.");
        }
        $p = new P($getOpt->getOperand("permission"), $data->message->client);
        $p->addMessageContext($data->message);
        $debug = [];
        $res = $p->resolve($debug);

        $str = [];
        $str[] = "Permission `{$p->title}` for user {$user->user->tag}";
        $str[] = "";

        $end = "(unset)";
        foreach ($debug as $target => $settings) {
            foreach ($settings as $setting => $value) {
                if (is_null($value)) {
                    $v = "(unset)";
                } else {
                    $v = $value ? "**allowed**" : "~~denied~~";
                    $end = $v;
                }

                // check for relevancy
                $relevant = true;
                $note = "";
                if ($target == "TARGET_BOTADMIN" && !in_array($user->id, $data->message->client->config['evalUsers'])) {
                    $relevant = false;
                    $note = " (not bot admin, ignored)";
                }
                $isOwner = $user->id == $data->message->guild->ownerID;
                $isAdmin = $user->permissions->has(Permissions::PERMISSIONS['ADMINISTRATOR']);
                if ($target == "TARGET_GUILDOWNER" && !($isAdmin || $isOwner)) {
                    $relevant = false;
                    $note = " (not guild admin, ignored)";
                }
                if (is_null($value)) {
                    $relevant = false;
                }
                if (!$relevant && !$getOpt->getOption(all)) {
                    continue;
                }
                $str[] = sprintf("Target `%s` / Scope `%s`: %s%s", $target, $setting, $v, $note);


            }
        }
        $str[] = "";
        $str[] = "The final value is $end.";

        return $data->message->channel->send(implode(PHP_EOL, $str), ['split' => true]);
    }

    public static function addPerm(GetOpt $getOpt, EventData $data): ?Promise
    {
    }
}
