<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

use CharlotteDunois\Collect\Collection;
use CharlotteDunois\Yasmin\Interfaces\GuildChannelInterface;
use CharlotteDunois\Yasmin\Models\Guild;
use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\Message;
use CharlotteDunois\Yasmin\Models\MessageEmbed;
use CharlotteDunois\Yasmin\Models\Permissions;
use CharlotteDunois\Yasmin\Models\TextChannel;
use Doctrine\DBAL\Schema\Schema;
use React\Promise\PromiseInterface;

/**
 * This handles all of the data related to a single permission or group
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class Permission
{
    const SETTING_GLOBAL = 0;
    const SETTING_GUILD = 1;
    const SETTING_CHANNEL = 2;
    const TARGET_GLOBAL = 0;
    const TARGET_ROLE = 1;
    const TARGET_USER = 2;
    const TARGET_GUILDOWNER = 3;
    const TARGET_BOTADMIN = 4;
    /**
     *
     * @var string
     */
    public $title;
    /**
     * @var bool
     */
    public $default;
    /**
     *
     * @var Collection
     */
    private $entries;
    /**
     *
     * @var Huntress
     */
    private $huntress;
    /**
     *
     * @var EventData
     */
    private $context;

    public function __construct(string $permission, Huntress $huntress, bool $default = false)
    {
        $this->huntress = $huntress;
        $this->title = $permission;
        $this->default = $default;
        $this->entries = $this->getAllPerms();
        $this->context = new EventData();
    }

    private function getAllPerms(): Collection
    {
        $qb = $this->huntress->db->createQueryBuilder();
        $qb->select("*")->from("permissions")->where("title = ?")->setParameter(1, $this->title, "string");
        return new Collection($qb->execute()->fetchAll());
    }

    public static function register(Huntress $huntress)
    {
        $dbEv = EventListener::new()->addEvent("dbSchema")->setCallback([self::class, "db"]);
        $huntress->eventManager->addEventListener($dbEv);
    }

    public static function db(Schema $schema): void
    {
        $t = $schema->createTable("permissions");
        $t->addColumn("title", "string",
            ['length' => 255, 'customSchemaOptions' => DatabaseFactory::CHARSET]);
        $t->addColumn("value", "boolean", ['default' => true]);
        $t->addColumn("settingType", "smallint", ["unsigned" => true]);
        $t->addColumn("setting", "bigint", ["unsigned" => true]);
        $t->addColumn("targetType", "smallint", ["unsigned" => true]);
        $t->addColumn("target", "bigint", ["unsigned" => true, 'notnull' => false]);
        $t->setPrimaryKey(["title", "settingType", "setting", "targetType", "target"]);
        $t->addIndex(["title"]);
    }

    /**
     * @return Collection
     */
    public function getEntries(): Collection
    {
        return $this->entries;
    }

    public function addGuildContext(Guild $guild): Permission
    {
        $this->context->guild = $guild;
        return $this;
    }

    public function addMemberContext(GuildMember $member): Permission
    {
        $this->context->user = $member;
        $this->context->guild = $member->guild;
        return $this;
    }

    public function addChannelContext(GuildChannelInterface $channel): Permission
    {
        $this->context->channel = $channel;
        $this->context->guild = $channel->guild;
        return $this;
    }

    public function addMessageContext(Message $message): Permission
    {
        $this->context->guild = $message->guild;
        $this->context->channel = $message->channel;
        $this->context->user = $message->member ?? $message->author;
        return $this;
    }

    /**
     *
     * How this works:
     * First we grab all the valid setting->target options that could apply, and then we use check() to cascade down the
     * list of options. check will return true (allowed), false (disallowed), or null (ignore) for each option.
     *
     * @param array $debug
     *
     * @return bool
     * @todo complex permissions with multiple roles
     *
     */
    public function resolve(array &$debug = []): bool
    {
        [$settings, $targets] = $this->getValidChecks();

        $carry = $this->default;
        $debug['default'] = $carry;

        foreach ($targets as $targetType) {
            $debug[$this->reverseConst($targetType, "TARGET")] = [];
            foreach ($settings as $settingType) {
                $res = $this->check($settingType, $targetType);
                $debug[$this->reverseConst($targetType, "TARGET")][$this->reverseConst($settingType, "SETTING")] = $res;
                if (!is_null($res)) {
                    $carry = $res;
                }
            }
        }

        return $carry;
    }

    private function getValidChecks(): array
    {
        $settings = [self::SETTING_GLOBAL];
        $targets = [self::TARGET_GLOBAL];

        $guild = !is_null($this->context->guild);
        $channel = !is_null($this->context->channel);
        $user = !is_null($this->context->user);

        if ($guild) {
            $settings[] = self::SETTING_GUILD;
        }
        if ($channel) {
            $settings[] = self::SETTING_CHANNEL;
        }
        if ($user) {
            $targets[] = self::TARGET_USER;
            $targets[] = self::TARGET_BOTADMIN;
        }
        if ($user && $guild) {
            $targets[] = self::TARGET_GUILDOWNER;
            $targets[] = self::TARGET_ROLE;
        }

        sort($settings);
        sort($targets);
        return [$settings, $targets];
    }

    private function reverseConst(int $check, string $prefix): string
    {
        $reflect = new \ReflectionClass($this);
        $con = array_filter($reflect->getConstants(), function ($v, $k) use ($prefix) {
            return stripos($k, $prefix) === 0;
        }, ARRAY_FILTER_USE_BOTH);
        return array_search($check, $con);
    }

    private function check(int $setting, int $target): ?bool
    {
        $items = $this->entries->filter(function ($v) use ($setting, $target) {
            return ($v['settingType'] == $setting) && ($v['targetType'] == $target);
        });

        // additional filtering
        switch ($target) {
            case self::TARGET_USER:
                $items = $this->filterUser($this->context->user, $items);
                break;
            case self::TARGET_ROLE:
                $items = $this->filterRole($this->context->user, $items);
                break;
            case self::TARGET_GLOBAL:
            case self::TARGET_GUILDOWNER:
            case self::TARGET_BOTADMIN:
            default:
                break;
        }

        // get this out of the way first
        if ($items->count() == 0) {
            return null;
        }

        // okay now let's turn this into a permissions set.
        $entry = $items->first();
        switch ($target) {
            case self::TARGET_USER:
            case self::TARGET_GLOBAL:
                // we dont need to do any fancy checks here, as long as it matches we're good!
                return (bool) $entry['value'];
            case self::TARGET_GUILDOWNER:
                // see if they're a guild owner (admin), othwerwise who gives a fuck
                $isOwner = $this->context->user->id == $this->context->guild->ownerID;
                $isAdmin = $this->context->user->permissions->has(Permissions::PERMISSIONS['ADMINISTRATOR']);
                if ($isOwner || $isAdmin) {
                    return (bool) $entry['value'];
                }
                break;
            case self::TARGET_BOTADMIN:
                // bot admin is an easy check
                if (in_array($this->context->user->id, $this->huntress->config['evalUsers'])) {
                    return (bool) $entry['value'];
                }
                break;
            case self::TARGET_ROLE:
                // we have to get a little recursive with the role check, yikes!
                return $items->sortCustom(function ($a, $b) {
                    $aRole = $this->context->user->roles->get($a['target']);
                    $bRole = $this->context->user->roles->get($b['target']);
                    return $aRole->position <=> $bRole->position;
                })->reduce(function ($c, $v) {
                    return (bool) $v['value'];
                }, null);
            default:
                return null;
        }
        return null;
    }

    private function filterUser(GuildMember $target, Collection $items): Collection
    {
        return $items->filter(function ($v) use ($target) {
            return $target->id == $v['target'];
        });
    }

    private function filterRole(GuildMember $target, Collection $items): Collection
    {
        return $items->filter(function ($v) use ($target) {
            return $target->roles->has($v['target']);
        });
    }

    public function sendUnauthorizedMessage(TextChannel $channel): ?PromiseInterface
    {
        $embed = new MessageEmbed();
        $embed->setTitle("Unauthorized!");
        $embed->setDescription(sprintf("You lack the required permission (`%s`) to use this command. Please try again later.",
            $this->title));
        $embed->setTimestamp(time());
        if ($this->context->user instanceof GuildMember) {
            $member = $this->context->user;
            $embed->setColor($member->getDisplayColor() ?? $member->id % 0xFFFFFF);
            $embed->setAuthor($member->displayName, $member->user->getAvatarURL(64) ?? null);
        }

        return $channel->send("", ['embed' => $embed]);
    }

}
