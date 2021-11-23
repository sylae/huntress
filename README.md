# Huntress
Huntress is a PHP discord bot framework. Note that while it's currently very usable, things are subject to change.

## Requirements

* php 7.4+
* composer

## Installation

Add the following nonsense to your `composer.json`

```json
{
  "minimum-stability": "dev",
  "require": {
    "sylae/huntress": "dev-master"
  },
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/sylae/huntress"
    }
  ]
}
```

Note that Huntress isn't in packagist rn because it's spiced ass and I don't want some jabroni thinking this is mature code

## Usage

1. copy config.sample.php to config.php, edit as needed.
2. Check out [bot.php](bot.php) for an example of how to get it running.
3. To add your own functionality, write a class implementing `\Huntress\PluginInterface` and make sure it gets loaded.

## Examples

Check out the `keira` branch for examples in the wild:

* Identity (`/src/Huntress/Plugin/Identity.php`) changes the bot avatar daily, along with cycling through status messages
* Snowflake (`/src/Huntress/Plugin/Snowflake.php`) is a simple command, generates a custom Snowflake for low-volume ID purposes
* RythmDJ (`/src/Huntress/Plugin/RythmDJ.php`) will automatically add a role with a particular name, due to a certain unnamed music bot not letting you give @everyone DJ permissions
* Role (`/src/Huntress/Plugin/Role.php`) is a self-service role request plugin, featuring automatic inheritance as a treat
