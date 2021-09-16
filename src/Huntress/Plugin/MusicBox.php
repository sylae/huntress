<?php

/*
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Yasmin\Models\Message;
use CharlotteDunois\Yasmin\Models\MessageEmbed;
use CharlotteDunois\Yasmin\Models\Permissions;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\ChildProcess\Process;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * play music via a janky bridge to a javascript bot
 *
 * @author Keira Dueck <sylae@calref.net>
 */
class MusicBox implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $eh = EventListener::new()
            ->addCommand("play")
            ->setCallback([self::class, "playHandler"]);
        $bot->eventManager->addEventListener($eh);
    }

    public static function playHandler(EventData $data): PromiseInterface
    {
        $p = new Permission("p.musicbox.play", $data->huntress, true);
        $p->addMessageContext($data->message);
        if (!$p->resolve()) {
            return $p->sendUnauthorizedMessage($data->message->channel);
        }

        if (is_null($data->message->member->voiceChannel)) {
            return $data->message->reply("You need to be in a voice chat for me to join you!");
        }

        $songURL = self::arg_substr($data->message->content, 1, 1) ?? null;
        if (is_null($songURL) || !filter_var($songURL, FILTER_VALIDATE_URL) || !str_starts_with($songURL, 'http')) {
            return $data->message->reply("Usage: `!play SONGURL`");
        }

        $vc = $data->message->member->voiceChannel;
        if (is_null($vc)) {
            return $data->message->reply("You need to be in a voice chat for me to join you!");
        }

        if (!is_null($data->guild->me->voiceChannel)) {
            return $data->message->reply("I am already playing a song in this server, sorry!");
        }

        $myPerms = $data->message->member->voiceChannel->permissionsFor($data->guild->me);
        if (!$myPerms->has(Permissions::PERMISSIONS['CONNECT']) || !$myPerms->has(Permissions::PERMISSIONS['SPEAK'])) {
            return $data->message->reply(
                "I don't seem to have the right permissions for that channel. I need to CONNECT and SPEAK"
            );
        }

        $filename = sprintf("temp/musicbox_%s.opus", $data->message->id);

        $dlCMD = sprintf(
            "youtube-dl -o %s --no-playlist -x --audio-format opus %s",
            $filename,
            escapeshellarg($songURL)
        );

        return $data->message->reply("💽")->then(function (Message $reply) use ($data, $dlCMD, $filename, $vc) {
            return self::cmd($dlCMD . " -j", $data->huntress)->then(
                function ($resp) use ($data, $dlCMD, $reply, $filename, $vc) {
                    try {
                        $songInfo = json_decode($resp['stdout'], true);
                        $embed = new MessageEmbed();
                        $embed->setAuthor(
                            $songInfo['artist'] ?? $songInfo['creator'] ?? $songInfo['channel'] ?? $songInfo['uploader'],
                            $data->huntress->user->getAvatarURL(),
                            $songInfo['channel_url'] ?? $songInfo['uploader_url']
                        );
                        $embed->addField("Duration", $songInfo['duration'], true);
                        $embed->addField("Filesize", $songInfo['filesize'], true);
                        $embed->setTitle($songInfo['track'] ?? $songInfo['title'] ?? null);
                        $embed->setURL($songInfo['webpage_url'] ?? null);
                        $embed->setThumbnail($songInfo['thumbnail'] ?? null);
                        $embed->setFooter(
                            "Requested by {$data->message->member->displayName}.",
                            $data->message->author->getAvatarURL()
                        );
                        $embed->setDescription(
                            "Attempting download and playback of `{$songInfo['extractor']}`//`{$songInfo['id']}`"
                        );
                        if (file_exists($filename)) {
                            unlink($filename);
                        }
                        return $reply->edit($reply->content, ['embed' => $embed])->then(
                            function (Message $reply) use ($data, $dlCMD, $songInfo, $filename, $vc) {
                                return self::cmd($dlCMD, $data->huntress)->then(
                                    function ($resp) use ($data, $dlCMD, $reply, $songInfo, $filename, $vc) {
                                        if (!file_exists($filename)) {
                                            // failed to dl. why?
                                            return $data->message->reply(
                                                "Failed! Please send the below files to <@297969955356540929> (`keira#7829`). code {$resp['code']}",
                                                [
                                                    'files' => [
                                                        ['name' => 'stdout.txt', 'data' => $resp['stdout']],
                                                        ['name' => 'stderr.txt', 'data' => $resp['stderr']],
                                                        [
                                                            'name' => 'songinfo.json',
                                                            'data' => json_encode($songInfo, JSON_PRETTY_PRINT),
                                                        ],
                                                    ],
                                                ]
                                            );
                                        }

                                        // we got the song!
                                        return self::cmd(
                                            sprintf("node musicbox/playFile.js %s %s", $vc->id, $filename),
                                            $data->huntress
                                        )->then(function () use ($filename) {
                                            unlink($filename);
                                        });
                                    }
                                );
                            }
                        );
                    } catch (\Throwable $e) {
                        return self::exceptionHandler($data->message, $e);
                    }
                }
            );
        });
    }

    protected static function cmd(string $command, Huntress $bot): PromiseInterface
    {
        $cmd = trim($command);
        $bot->log->debug("Running $cmd");
        if (php_uname('s') == "Windows NT") {
            $null = 'nul';
        } else {
            $null = '/dev/null';
        }
        $process = new Process($cmd, null, null, [
            ['file', $null, 'r'],
            $stdout = tmpfile(),
            $stderr = tmpfile(),
        ]);
        $process->start($bot->getLoop());
        $prom = new Deferred();

        $process->on('exit', function (int $exitcode) use ($stdout, $stderr, $prom, $bot) {
            $bot->log->debug("child process exited with code $exitcode.");

            // todo: use FileHelper filesystem nonsense for this.
            rewind($stdout);
            $stdoutD = stream_get_contents($stdout);
            fclose($stdout);

            rewind($stderr);
            $stderrD = stream_get_contents($stderr);
            fclose($stderr);

            $prom->resolve(['code' => $exitcode, 'stdout' => $stdoutD, 'stderr' => $stderrD]);
        });
        return $prom->promise();
    }
}
