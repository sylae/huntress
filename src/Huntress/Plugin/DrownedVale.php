<?php
/*
 * Copyright (c) 2022 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Yasmin\Models\GuildMember;
use CharlotteDunois\Yasmin\Models\MessageReaction;
use CharlotteDunois\Yasmin\Models\User;
use CharlotteDunois\Yasmin\Utils\URLHelpers;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Intervention\Image\ImageManager;
use React\Promise\ExtendedPromiseInterface as PromiseInterface;
use thiagoalessio\TesseractOCR\TesseractOCR;
use Throwable;

use function React\Promise\all;

class DrownedVale implements PluginInterface
{
    use PluginHelperTrait;

    public const ROLE_COLONIAL = 943654648252858368;
    public const ROLE_RECRUIT = 944096516593831947;
    public const ROLE_MEMBER = 943996715160182844;
    public const ROLE_TENURED = 943653875368480808;
    public const ROLE_DEPOT = 944096576249417749;
    public const ROLE_OP = 964636163312877578;
    public const ROLE_WARDEN = 944111822615748650;

    public const ROLE_VC_LOW = 965150197598523402;
    public const ROLE_VC_HIGH = 965151757657325608;

    public const CHCAT_LOW = 943996180839419946;
    public const CHCAT_HIGH = 943996278310834207;

    public const CH_LOG = 943655113854185583;
    public const GUILD = 943653352305209406;

    public const BUFFET_MSGS = [962987536932823040];

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(
            EventListener::new()->setCallback([self::class, "dviVCAccess"])
                ->addEvent("voiceStateUpdate")->addGuild(self::GUILD)
        );
        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addCommand("vc")
                ->addCommand("vcr")
                ->addGuild(self::GUILD)
                ->setCallback([self::class, "dviVCRename"])
        );

        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addCommand("stockpile")
                ->addGuild(self::GUILD)
                ->setCallback([self::class, "stockpile"])
        );

        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addEvent("message")
                ->addGuild(self::GUILD)
                ->setCallback([self::class, "clown"])
        );

        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addCommand("nuke")
                ->addCommand("nukerole")
                ->addGuild(self::GUILD)
                ->setCallback([self::class, "dviNuke"])
        );

        // todo: update core HEM to include raw event data for cases like this
        $bot->on("guildMemberUpdate", [self::class, "dviRoleLog"]);
        $bot->on("messageReactionAdd", [self::class, "dviBuffetAdd"]);
        $bot->on("messageReactionRemove", [self::class, "dviBuffetRemove"]);
    }


    public static function clown(EventData $data): ?PromiseInterface
    {
        $p = new Permission("p.dvi.clown", $data->huntress, true);
        $p->addMessageContext($data->message);
        if (!$p->resolve()) {
            return null;
        }

        if ($data->message->member->roles->has(self::ROLE_WARDEN)) {
            return $data->message->react("🤡");
        }

        return null;
    }


    // very WIP
    public static function stockpile(EventData $data): ?\React\Promise\PromiseInterface
    {
        $p = new Permission("p.dvi.stockpile", $data->huntress, false);
        $p->addMessageContext($data->message);
        if (!$p->resolve() || $data->message->author->bot || $data->message->attachments->count() == 0) {
            return null;
        }

        return URLHelpers::resolveURLToData($data->message->attachments->first()->url)->then(
            function (string $img) use ($data) {
                $im = new ImageManager();
                $items = $im->make($img);
                $items->resize(
                    1920,
                    1080,
                    function ($constraint) {
                        $constraint->aspectRatio();
                    }
                );

                $code = clone $items;
                $code->crop(192, 22, 862, 522);

                $items->crop(568, 285, 858, 218);
                $itemBlob = $items->encode("jpg");

                $ocr = new TesseractOCR();
                $blob = $code->encode('bmp');
                $ocr->imageData($blob, strlen($blob));
                $stockpileName = $ocr->run();

                return $data->message->channel->send(
                    $stockpileName,
                    ['files' => [['name' => "test.jpg", 'data' => $itemBlob]]]
                );
            }
        );
    }

    public static function dviNuke(EventData $data): ?PromiseInterface
    {
        $p = new Permission("p.dvi.nukerole", $data->huntress, false);
        $p->addMessageContext($data->message);
        if (!$p->resolve()) {
            return $p->sendUnauthorizedMessage($data->message->channel);
        }

        $roleStr = self::arg_substr($data->message->content, 1) ?? false;
        if (!$roleStr) {
            return $data->message->reply("Usage: `!nukerole ROLE`");
        }

        $rolesWeCanNuke = [
            self::ROLE_DEPOT,
            self::ROLE_OP
        ];

        $role = self::parseRole($data->guild, $roleStr);
        if (is_null($role)) {
            return $data->message->reply("Unknown role. Type it out, @ it, or paste in the role ID.");
        }
        if (!in_array($role->id, $rolesWeCanNuke)) {
            return $data->message->reply("`!nukerole` cannot be used on this role.");
        }

        $cull = $data->guild->members->filter(function (GuildMember $v) use ($role) {
            return $v->roles->has($role->id);
        });

        $pr = $data->message->reply(
            sprintf(
                "Nuking %s members from `@%s`.\nNote that large roster culls may take awhile due to Discord rate limits.",
                $cull->count(),
                $role->name
            )
        );

        return $pr->then(function () use ($cull, $role, $data) {
            $cull->map(function (GuildMember $v) use ($role, $data) {
                return $v->removeRole($role, $data->message->getJumpURL());
            });

            return all($cull->all())->then(function () use ($data) {
                return $data->message->reply("Nuking complete! :)");
            });
        });
    }

    public static function dviRoleLog(GuildMember $new, ?GuildMember $old): ?PromiseInterface
    {
        if (is_null($old)) {
            return null;
        }

        if ($new->roles->count() == $old->roles->count()) {
            return null;
        }

        $added = $new->roles->diffKeys($old->roles);
        $removed = $old->roles->diffKeys($new->roles);

        $rolesWeGiveAShitAbout = [
            self::ROLE_TENURED,
            self::ROLE_MEMBER,
            self::ROLE_RECRUIT,
            self::ROLE_DEPOT
        ];

        $x = [];
        $x[] = $added->map(function (\CharlotteDunois\Yasmin\Models\Role $v) use ($new, $rolesWeGiveAShitAbout) {
            if (!in_array($v->id, $rolesWeGiveAShitAbout)) {
                return null;
            }
            return $v->client->channels->get(self::CH_LOG)->send(
                sprintf("[DrownedVale] <@%s> had role `%s` added.", $new->id, $v->name)
            );
        });
        $x[] = $removed->map(function (\CharlotteDunois\Yasmin\Models\Role $v) use ($new, $rolesWeGiveAShitAbout) {
            if (!in_array($v->id, $rolesWeGiveAShitAbout)) {
                return null;
            }
            return $v->client->channels->get(self::CH_LOG)->send(
                sprintf("[DrownedVale] <@%s> had role `%s` removed.", $new->id, $v->name)
            );
        });

        return all($x);
    }

    public static function dviVCRename(EventData $data): ?PromiseInterface
    {
        if (is_null($data->message->member->voiceChannel) ||
            !$data->guild->channels->has($data->message->member->voiceChannel->id)
        ) {
            return $data->message->reply("You must be in a voice channel to use this command");
        }

        $p = new Permission("p.dvi.vc.rename", $data->huntress, false);
        $p->addMessageContext($data->message);
        if (!$p->resolve()) {
            return $p->sendUnauthorizedMessage($data->message->channel);
        }

        if ($name = self::arg_substr($data->message->content, 1) ?? false) {
            if (mb_strlen($name) > 100) { // discord limit
                return $data->message->reply("Voice channel name must be less than 100 chars.");
            }
            // todo: further filtering to ensure the channel name will pass muster; for now we'll just shit ourselves if discord throws an error.
            return $data->message->member->voiceChannel->setName($name, $data->message->getJumpURL())->then(
                function () use ($data) {
                    return $data->message->react("😤");
                },
                function () use ($data) {
                    return $data->message->reply("Discord rejected this name!");
                }
            );
        } else {
            return $data->message->reply("Usage: `!vc New Voice Channel Name`");
        }
    }

    public static function dviVCAccess(EventData $data): void
    {
        if ($data->user->voiceChannel?->parentID == self::CHCAT_LOW) {
            $data->user->addRole(self::ROLE_VC_LOW);
        } elseif ($data->user->roles->has(self::ROLE_VC_LOW)) {
            $data->user->removeRole(self::ROLE_VC_LOW);
        }

        if ($data->user->voiceChannel?->parentID == self::CHCAT_HIGH) {
            $data->user->addRole(self::ROLE_VC_HIGH);
        } elseif ($data->user->roles->has(self::ROLE_VC_HIGH)) {
            $data->user->removeRole(self::ROLE_VC_HIGH);
        }
    }

    public static function dviBuffetAdd(MessageReaction $reaction, User $reactor): ?PromiseInterface
    {
        /** @var Huntress $bot */
        $bot = $reaction->client;
        if ($reactor->id == $bot->user->id) {
            return null;
        }
        try {
            if (!in_array($reaction->message->id, self::BUFFET_MSGS)) {
                return null;
            }

            $reactID = $reaction->emoji->id ?? $reaction->emoji->name;

            [$roleID, $restriction] = self::getReactMapping($reactID);
            if (is_null($roleID)) {
                $bot->log->warning("Unknown react $reactID for PronounBot");
                return $reaction->remove();
            }

            $role = $reaction->message->guild->roles->get($roleID);
            if (is_null($role)) {
                $bot->log->warning("Unknown role $roleID for PronounBot");
                return $reaction->remove($reactor);
            }

            $reactMember = $reaction->message->guild->members->get($reactor->id);
            if (is_null($reactMember)) {
                // weird but ok, might happen if we havent fetched yet.
                return $reaction->remove($reactor);
            }

            $p = new Permission($restriction, $bot, false);
            $p->addMemberContext($reactMember);
            if (!$p->resolve()) {
                return $reaction->remove($reactor);
            }

            return $reactMember->addRole($role);
        } catch (Throwable $e) {
            $bot->log->warning($e->getMessage(), ['exception' => $e]);
        }
    }

    public static function getReactMapping(mixed $reactID): array
    {
        return match ($reactID) {
            "944208162112802826" => [944203243964207144, 'p.dvi.roles.qrf'], // qrf
            "958203821451001906" => [944211152668327937, 'p.dvi.roles.oper8or'], // oper8or
            "958768926941134878" => [959556988075917383, 'p.dvi.roles.logi'], // logi
            "961457534017876018" => [961353321552175164, 'p.dvi.roles.streamist'], // stream
            "🥪" => [944107391677521940, 'p.dvi.roles.sudo'], // sudo

            default => [null, true],
        };
    }

    public static function dviBuffetRemove(MessageReaction $reaction, User $reactor): ?PromiseInterface
    {
        /** @var Huntress $bot */
        $bot = $reaction->client;
        if ($reactor->id == $bot->user->id) {
            return null;
        }
        try {
            if (!in_array($reaction->message->id, self::BUFFET_MSGS)) {
                return null;
            }

            $reactID = $reaction->emoji->id ?? $reaction->emoji->name;

            [$roleID, $corpOnly] = self::getReactMapping($reactID);
            if (is_null($roleID)) {
                $bot->log->warning("Unknown react $reactID for PronounBot");
                return null;
            }

            $role = $reaction->message->guild->roles->get($roleID);
            if (is_null($role)) {
                $bot->log->warning("Unknown role $roleID for PronounBot");
                return null;
            }

            $reactMember = $reaction->message->guild->members->get($reactor->id);
            if (is_null($reactMember)) {
                // weird but ok, might happen if we havent fetched yet.
                return null;
            }

            return $reactMember->removeRole($role);
        } catch (Throwable $e) {
            $bot->log->warning($e->getMessage(), ['exception' => $e]);
        }
    }

}
