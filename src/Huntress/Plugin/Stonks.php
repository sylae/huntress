<?php


namespace Huntress\Plugin;


use Aran\YahooFinanceApi\ApiClient;
use Aran\YahooFinanceApi\ApiClientFactory;
use Aran\YahooFinanceApi\Results\Quote;
use CharlotteDunois\Yasmin\Models\MessageEmbed;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface;
use function React\Promise\all;
use function React\Promise\resolve;

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
     * @var \Aran\YahooFinanceApi\ApiClient
     */
    private ApiClient $client;

    /** @var array[] */
    private array $cache;

    /** @var int */
    private int $ttl;

    public function __construct(Huntress $bot)
    {
        $this->client = ApiClientFactory::createFromLoop($bot->getLoop());
        $this->cache = [];
        $this->ttl = static::CACHE_TTL;
    }

    public static function register(Huntress $bot): void
    {
        $instance = new static($bot);
        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addCommand('stonks')
                ->setCallback([$instance, 'stonks'])
        );
    }

    public function stonks(EventData $event): PromiseInterface
    {
        $args = self::_split($event->message->content);
        if ($args[1] === 'search') {
            return $this->search($event);
        }
        $symbols = array_map('mb_strtoupper', array_slice($args, 1));
        $now = time();

        // Check which quotes must be refreshed.
        $refresh = array_filter($symbols, function($symbol) use ($now) {
           return !isset($this->cache[$symbol]) || $this->cache[$symbol]['time'] + $this->ttl < $now;
        });

        $channel = $event->message->channel;

        /** @var PromiseInterface $promise*/
        if ($refresh) {
            // Get new data.
            $promise = $channel->send('Loading stock data...')
                ->then(function () use ($refresh) {
                    return $this->client->getQuotes($refresh);
                })
                ->then(function ($data) use ($now) {
                    foreach ($data as $i => $quote) {
                        // Do not cache errors.
                        $this->cache[$quote->getSymbol()] = [
                            'time'  => $now,
                            'quote' => $quote,
                        ];
                    }
                });
        }
        else {
            // If we only use cached data, start with a blank promise.
            $promise = resolve();
        }

        // Format data.
        return $promise->then(function () use ($channel, $symbols) {
            $promises = [];
            foreach ($symbols as $symbol) {
                if (isset($this->cache[$symbol])) {
                    /** @var \Aran\YahooFinanceApi\Results\Quote $quote */
                    ['time' => $time, 'quote' => $quote] = $this->cache[$symbol];
                    $promises[] = $channel->send('', ['embed' => $this->formatQuote($symbol, $quote, $time)]);
                }
                else {
                    $promises[] = $channel->send(sprintf("No results returned for $symbol"));
                }
            }
            return all($promises);
        });
    }

    public function search(EventData $event): PromiseInterface {
        $search = self::arg_substr($event->message->content, 2);
        $channel = $event->message->channel;

        return $channel->send('Searching...')
            ->then(function () use ($search) {
                return $this->client->search($search);
            })
            ->then(function ($results) use ($search, $channel) {
                $embed = new MessageEmbed();
                $count = count($results);
                $embed->setTitle("{$count} results for \"*{$search}*\"");
                foreach ($results as $result) {
                    $embed->addField(
                        $result->getSymbol(),
                        sprintf('%s (%s, %s)', $result->getName(), $result->getTypeDisp(), $result->getExchDisp())
                    );
                }
                return $channel->send('', ['embed' => $embed]);
            });
    }

    private function formatQuote(string $symbol, Quote $quote, int $time): MessageEmbed {
        $currency = $quote->getCurrency();
        $embed = new MessageEmbed();
        $embed->setTitle("{$symbol} ({$quote->getFullExchangeName()})");
        $embed->addField(
            'Ask / Bid',
            "{$currency} {$quote->getAsk()} / {$currency} {$quote->getBid()}"
        );
        if ($quote->getMarketState()) {
            $embed->addField(
                'Market state',
                $quote->getMarketState()
            );
        }
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
        $embed->setTimestamp($time);
        return $embed;
    }

}
