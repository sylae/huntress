<?php

/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Yasmin\Utils\URLHelpers;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Intervention\Image\ImageManager;
use React\Promise\PromiseInterface;
use Throwable;

use function React\Promise\all;

/**
 * The best plugin
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class IFunny implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addEvent("message")
                ->setCallback([self::class, "process"])
        );
    }

    public static function process(EventData $data): ?PromiseInterface
    {
        $p = new Permission("p.ifunny", $data->huntress, false);
        $p->addMessageContext($data->message);
        if ($data->message->author->bot || !$p->resolve() || $data->message->attachments->count() == 0) {
            return null;
        }

        $x = [];
        foreach ($data->message->attachments as $att) {
            if ($att->width < 100 || $att->height < 100 || $att->size > 1024 * 1024 * 10) {
                continue;
            }

            // only fire on some images unless override flag
            $p = new Permission("p.ifunny.always", $data->huntress, false);
            $p->addMessageContext($data->message);
            if (!$p->resolve() && random_int(1, 10) != 1) {
                continue;
            }

            $x[] = URLHelpers::resolveURLToData($att->url)->then(
                function (string $bin) use ($data, $att) {
                    try {
                        $im = new ImageManager();
                        $img = $im->make($bin);
                        $img->resize(
                            500,
                            500,
                            function ($constraint) {
                                $constraint->aspectRatio();
                            }
                        );
                        $img->resizeCanvas(0, 20, 'top', true, 0x171719);
                        $img->insert('data/ifunny.png', 'bottom-right');
                        $jpg = (string)$img->encode('jpg', 10);
                        return $data->message->channel->send(
                            "Let me fix that for you, {$data->message->member->displayName}.",
                            ['files' => [['name' => $att->filename . ".jpg", 'data' => $jpg]]]
                        );
                    } catch (Throwable $e) {
                        $data->huntress->log->warning($e->getMessage(), ['exception' => $e]);
                        return null;
                    }
                }
            );
        }
        return all($x);
    }
}
