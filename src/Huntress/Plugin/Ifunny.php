<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use \Huntress\Huntress;
use \React\Promise\ExtendedPromiseInterface as Promise;
use \Intervention\Image\ImageManagerStatic as Image;
use \CharlotteDunois\Yasmin\Utils\URLHelpers;

/**
 * Deletes "permission denied" messages by Angush's bot.
 *
 * Most often used when Sidekick is around, as /r is a conflict for these two bots.
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class Ifunny implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->on(self::PLUGINEVENT_MESSAGE, [self::class, "process"]);
    }

    public static function process(Huntress $bot, \CharlotteDunois\Yasmin\Models\Message $message): ?Promise
    {
        if ($message->channel->id != 356885321071198208 || $message->author->id == $bot->user->id) {
            return null;
        }
        foreach ($message->attachments as $att) {
            if ($att->width < 100 || $att->height < 100 || $att->size > 1024 * 1024 * 10) {
                continue;
            }
            URLHelpers::resolveURLToData($att->url)->then(function (string $data) use ($message, $att) {
                try {
                    $img = Image::make($data);
                    $img->resize(500, 500, function ($constraint) {
                        $constraint->aspectRatio();
                    });
                    $img->resizeCanvas(0, 20, 'top', true, 0x171719);
                    $img->insert('data/ifunny.png', 'bottom-right');
                    $jpg = (string) $img->encode('jpg', 10);
                    $message->channel->send("Let me fix that for you, {$message->member->displayName}.", ['files' => [['name' => $att->filename . ".jpg", 'data' => $jpg]]]);
                } catch (\Throwable $e) {
                    self::exceptionHandler($message, $e);
                }
            });
        }
        return null;
    }
}
