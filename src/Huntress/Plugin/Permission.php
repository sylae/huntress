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
                    (new Option(null, 'dryrun',
                        GetOpt::NO_ARGUMENT))->setDescription("Don't actually commit the changes, just test."),
                    (new Option(null, 'scope',
                        GetOpt::OPTIONAL_ARGUMENT))->setDefaultValue("guild")->setDescription("Set the scope.")->setArgumentName("global|guild|channel"),
                    (new Option(null, 'all',
                        GetOpt::NO_ARGUMENT))->setDescription("Target all users within the scope."),
                    (new Option(null, 'role',
                        GetOpt::REQUIRED_ARGUMENT))->setDescription("Target a particular role, specified as an @mention.")->setArgumentName("@role"),
                    (new Option(null, 'user',
                        GetOpt::REQUIRED_ARGUMENT))->setDescription("Target a particular user specified in a format Huntress can understand.")->setArgumentName("username"),
                    (new Option(null, 'admins',
                        GetOpt::NO_ARGUMENT))->setDescription("Target guild owners and users with the ADMINISTRATOR discord permission."),
                    (new Option(null, 'evalusers',
                        GetOpt::NO_ARGUMENT))->setDescription("Target bot owners within the scope."),
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
                if (!$relevant && !$getOpt->getOption("all")) {
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
        try {
            $allow = is_null($getOpt->getOption("deny")) ? 1 : 0; // idfk it's being picky about casting


            // step one, setting validation
            $settings = ['global', 'guild', 'channel'];
            if (!in_array($getOpt->getOption("scope"), $settings)) {
                return self::error($data->message, "Invalid scope",
                    "`--scope` must be one of `global`, `guild`, or `channel`. If not provided it will assume `guild`.");
            }
            switch ($getOpt->getOption("scope")) {
                case "global":
                    $setting = P::SETTING_GLOBAL;
                    $settingValue = 0;
                    $settingPretty = "Everywhere";
                    break;
                case "guild":
                default:
                    $setting = P::SETTING_GUILD;
                    $settingValue = $data->guild->id;
                    $settingPretty = "Within this guild (`{$data->guild->name}`)";
                    break;
                case "channel":
                    $setting = P::SETTING_CHANNEL;
                    $settingValue = $data->channel->id;
                    $settingPretty = "Inside this channel ({$data->channel})";
                    break;
            }

            // step two, target validation
            $targets = 0;
            $target = P::TARGET_GLOBAL;
            $targetValue = 0;
            if (!is_null($getOpt->getOption("admins"))) {
                $targets++;
                $target = P::TARGET_GUILDOWNER;
                $targetPretty = "Administrators";
            }
            if (!is_null($getOpt->getOption("evalusers"))) {
                $targets++;
                $target = P::TARGET_BOTADMIN;
                $targetPretty = "Bot owners";
            }
            if (!is_null($getOpt->getOption("role"))) {
                $targets++;
                $target = P::TARGET_ROLE;
                $trole = self::parseRole($data->message->guild, $getOpt->getOption("role"));
                $targetValue = $trole->id;
                $targetPretty = "Users with the @{$trole->name} role (`{$targetValue}`)";
            }
            if (!is_null($getOpt->getOption("user"))) {
                $targets++;
                $target = P::TARGET_USER;
                $targetValue = self::parseGuildUser($data->message->guild, $getOpt->getOption("user"))->id;
                $targetPretty = "The user <@{$targetValue}> in particular.";
            }
            if (!is_null($getOpt->getOption("all")) || $targets == 0) {
                $targets++;
                $target = P::TARGET_GLOBAL;
                $targetPretty = "Anybody";
            }
            if ($targets > 1) {
                return self::error($data->message, "Invalid target",
                    "Only one target (`--all`, `--role`, `--user`, `--admins`, `--evalusers`) can be chosen. If not provided it will assume `--all`.");
            }
            if (is_null($targetValue)) {
                return self::error($data->message, "Invalid target",
                    "An invalid value has been supplied for `--role` or `--user`. Users should use display name, Tag#1234, or @mentions. Roles should use ID or @mention.");
            }

            $permission = $getOpt->getOperand("permission");
            if (stripos($permission, "p.huntress.hpm") === 0 && !in_array($data->message->member->id,
                    $data->message->client->config['evalUsers'])) {
                return self::error($data->message, "Change disallowed",
                    "The `p.huntress.hpm` namespace is hardcoded so that only the bot owners can edit it. Please try again later.");
            }

            $overridePermissions = [];
            $canDo = self::hasPermissionPermission($setting, $target, $data, $overridePermissions);

            if ($canDo) {
                if (!$getOpt->getOption("dryrun")) {
                    $query = $data->message->client->db->prepare('REPLACE INTO permissions (`title`, `value`, `settingType`, `setting`, `targetType`, `target`) VALUES(?, ?, ?, ?, ?, ?)',
                        ['string', 'boolean', 'integer', 'integer', 'integer', 'integer']);
                    $query->bindValue(1, $permission);
                    $query->bindValue(2, $allow);
                    $query->bindValue(3, $setting);
                    $query->bindValue(4, $settingValue);
                    $query->bindValue(5, $target);
                    $query->bindValue(6, $targetValue);
                    $query->execute();
                    $msgBegin = "The following permission has been added:\n";
                } else {
                    $msgBegin = "Your command would add the following:\n";
                }
                return $data->message->channel->send(sprintf($msgBegin . "**%s**: `%s`\n**Scope**: %s\n**Target**: %s\n",
                    $allow ? "Allow" : "Deny", $permission,
                    $settingPretty, $targetPretty));
            } else {
                return self::error($data->message, "Change disallowed",
                    sprintf("%s, or the `%s` override permission.", $overridePermissions[0], $overridePermissions[1]));
            }
        } catch (Throwable $e) {
            return self::exceptionHandler($data->message, $e);
        }
    }

    private static function hasPermissionPermission(int $setting, int $target, EventData $data, array &$overridePermissions = []): bool
    {

        $default = false;
        if ($setting == P::SETTING_GLOBAL || $target == P::TARGET_BOTADMIN) {
            if (in_array($data->message->member->id, $data->message->client->config['evalUsers'])) {
                $default = true;
            }
            $overridePermissions[0] = "Must be bot owner";
        } elseif ($setting == P::SETTING_GUILD) {
            $isOwner = $data->message->member->id == $data->message->guild->ownerID;
            $isAdmin = $data->message->member->permissions->has(Permissions::PERMISSIONS['ADMINISTRATOR']);
            if ($isAdmin || $isOwner) {
                $default = true;
            }
            $overridePermissions[0] = "Must be guild owner or have the ADMINISTRATOR discord permission";
        } elseif ($setting == P::SETTING_CHANNEL) {
            if ($data->message->member->permissionsIn($data->channel)->has(Permissions::PERMISSIONS['MANAGE_CHANNELS'])) {
                $default = true;
            }
            $overridePermissions[0] = "Must have the MANAGE_CHANNEL discord permission";
        }

        // now let's check overrides
        switch ($setting) {
            case P::SETTING_GLOBAL:
                $overSetting = "global";
                break;
            case P::SETTING_GUILD:
                $overSetting = "guild";
                break;
            case P::SETTING_CHANNEL:
                $overSetting = "channel";
                break;
        }
        switch ($target) {
            case P::TARGET_GLOBAL:
                $overTarget = "all";
                break;
            case P::TARGET_ROLE:
                $overTarget = "role";
                break;
            case P::TARGET_USER:
                $overTarget = "user";
                break;
            case P::TARGET_GUILDOWNER:
                $overTarget = "admin";
                break;
            case P::TARGET_BOTADMIN:
                $overTarget = "evaluser";
                break;
        }

        $checkedPerm = sprintf("p.huntress.hpm.edit.%s-%s", $overSetting, $overTarget);
        $p = new P($checkedPerm, $data->message->client, $default);
        $overridePermissions[1] = $checkedPerm;
        return $p->resolve();
    }
}
