<?php

/*
 * Copyright (c) 2019 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress;

/**
 * Description of HuntressSnowflake
 *
 * @author Keira
 */
class Snowflake extends \CharlotteDunois\Yasmin\Utils\Snowflake
{
    /**
     * Time since UNIX epoch to Huntress epoch. (1 Jan 2019)
     * @var int
     */
    const EPOCH = 1546300800;

    public static function format(int $snow): string
    {
        return base_convert($snow, 10, 36);
    }

    public static function parse(string $snow): int
    {
        return (int) base_convert($snow, 36, 10);
    }

    /**
     * Constructor.
     * @param string|int  $snowflake
     * @throws \InvalidArgumentException
     */
    public function __construct($snowflake)
    {

        $snowflake   = (int) $snowflake;
        $this->value = $snowflake;

        $this->binary = str_pad(decbin($snowflake), 64, 0, STR_PAD_LEFT);

        $time = (string) ($snowflake >> 2);

        $this->timestamp = (int) $time + self::EPOCH;
        $this->increment = ($snowflake & 0x3);


        if ($this->timestamp < self::EPOCH || $this->increment < 0 || $this->increment >= 4) {
            throw new \InvalidArgumentException('Invalid snow in snowflake');
        }
    }

    /**
     * Deconstruct a snowflake.
     * @param string|int  $snowflake
     * @return Snowflake
     */
    public static function deconstruct($snowflake)
    {
        return (new self($snowflake));
    }

    /**
     * Generates a new snowflake.
     * @param int  $workerID   Valid values are in the range of 0-31.
     * @param int  $processID  Valid values are in the range of 0-31.
     * @return int
     */
    public static function generate(int $workerID = 1, int $processID = 0): int
    {
        $time = time();

        if ($time === self::$incrementTime) {
            self::$incrementIndex++;

            if (self::$incrementIndex >= 4) {
                sleep(1);

                $time = time();

                self::$incrementIndex = 0;
            }
        } else {
            self::$incrementIndex = 0;
            self::$incrementTime  = $time;
        }

        $time = (string) $time - self::EPOCH;

        $binary = str_pad(decbin(((int) $time)), 62, 0, STR_PAD_LEFT) . str_pad(decbin(self::$incrementIndex), 2, 0, STR_PAD_LEFT);
        return (int) bindec($binary);
    }
}
