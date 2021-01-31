<?php


namespace Huntress\Plugin;


use CharlotteDunois\Yasmin\Models\MessageEmbed;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use Scheb\YahooFinanceApi\ApiClient;
use Scheb\YahooFinanceApi\ApiClientFactory;
use Scheb\YahooFinanceApi\Results\Quote;

/**
 * Load ticker data from the Yahoo Finance API.
 *
 * @author Aran <aran@ermarian.net>
 */
class Stonks implements PluginInterface
{

    use PluginHelperTrait;

    public const CACHE_TTL = 300;

    /**
     * @var \Scheb\YahooFinanceApi\ApiClient
     */
    private ApiClient $client;

    /** @var array[] */
    private array $cache;

    /** @var int */
    private int $ttl;

    public function __construct()
    {
        $this->client = ApiClientFactory::createApiClient();
        $this->cache = [];
        $this->ttl = static::CACHE_TTL;
    }

    public static function register(Huntress $bot): void
    {
        $instance = new static();
        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addCommand('stonks')
                ->setCallback([$instance, 'stonks'])
        );
    }

    public function stonks(EventData $event)
    {
        $arg = explode(' ', strtoupper(self::arg_substr($event->message->content, 1) ?? ''));
        $channel = $event->message->channel;
        $now = time();
        $get = [];
        // Check which quotes must be refreshed.
        foreach ($arg as $symbol) {
            if (!isset($this->cache[$symbol]) || $this->cache[$symbol]['time'] + $this->ttl < $now) {
                $get[] = $symbol;
            }
        }
        // Get new data.
        if ($get) {
            $data = $this->client->getQuotes($get);
            foreach ($data as $i => $quote) {
                // Do not cache errors.
                if ($quote instanceof Quote) {
                    $this->cache[$get[$i]] = [
                        'time'  => $now,
                        'quote' => $quote,
                    ];
                }
            }
        }
        // Format data.
        $errors = [];
        $promise = \React\Promise\resolve();
        foreach ($arg as $symbol) {
            if (isset($this->cache[$symbol])) {
                /** @var \Scheb\YahooFinanceApi\Results\Quote $quote */
                ['time' => $time, 'quote' => $quote] = $this->cache[$symbol];
                $currency = $quote->getCurrency();
                $embed = new MessageEmbed();
                $embed->setTitle("{$symbol} ({$quote->getFullExchangeName()})");
                $embed->addField(
                    'Ask / Bid',
                    "{$currency} {$quote->getAsk()} / {$currency} {$quote->getBid()}"
                );
                $embed->addField(
                    'Market state',
                    $quote->getMarketState()
                );
                $embed->addField(
                    'Opening price',
                    sprintf('%s %.2f', $currency, $quote->getRegularMarketOpen())
                );
                $embed->addField(
                    $quote->getMarketState() === 'REGULAR' ? 'Market price' : 'Closing price',
                    sprintf(
                        '%s %.2f, (%+.3f%%)',
                        $currency, $quote->getRegularMarketPrice(),
                        $quote->getRegularMarketChangePercent()
                    )
                );
                if ($quote->getPostMarketPrice()) {
                    $embed->addField(
                        'Post-Market price',
                        sprintf(
                            '%s %.2f, (%+.3f%%)',
                            $currency,
                            $quote->getPostMarketPrice(),
                            $quote->getPostMarketChangePercent()
                        )
                    );
                }
                if ($quote->getPreMarketPrice()) {
                    $embed->addField(
                        'Pre-Market price',
                        sprintf(
                            '%s %.2f, (%+.3f%%)',
                            $currency,
                            $quote->getPreMarketPrice(),
                            $quote->getPreMarketChangePercent()
                        )
                    );
                }
                $embed->setFooter(sprintf("Updated: %s", date('Y-m-d H:i:s', $time)));
                $promise = $promise->then(
                    function () use ($embed, $channel) {
                        return $channel->send('', ['embed' => $embed]);
                    }
                );
            }
            else {
                $errors[] = $symbol;
            }
        }
        if ($errors) {
            $promise = $promise->then(
                function () use ($channel, $errors) {
                    return $channel->send(sprintf('No results returned for %s', implode(', ', $errors)));
                }
            );
        }
        return $promise;
    }

}
