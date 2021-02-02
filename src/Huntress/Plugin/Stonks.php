<?php


namespace Huntress\Plugin;


use Aran\YahooFinanceApi\ApiClient;
use Aran\YahooFinanceApi\ApiClientFactory;
use Aran\YahooFinanceApi\Results\Quote;
use CharlotteDunois\Yasmin\Models\MessageEmbed;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
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

    public const MAX_SYMBOLS = 5;

    /**
     * @var \Aran\YahooFinanceApi\ApiClient
     */
    private ApiClient $client;

    /** @var array[] */
    private array $cache;

    /** @var int */
    private int $cache_ttl;

    /** @var int */
    private int $max_symbols;

    /**
     * Construct a new plugin instance.
     *
     * @param \Huntress\Huntress $bot
     *   Huntress instance (required for the main event loop).
     * @param int $cache_ttl
     *   Cache TTL in seconds.
     * @param int $max_symbols
     *   Max symbols per request.
     */
    public function __construct(Huntress $bot, int $cache_ttl, int $max_symbols)
    {
        $this->client = ApiClientFactory::createFromLoop($bot->getLoop());
        $this->cache = [];
        $this->cache_ttl = $cache_ttl;
        $this->max_symbols = $max_symbols;
    }

    /**
     * Create and register a plugin instance.
     *
     * @param \Huntress\Huntress $bot
     */
    public static function register(Huntress $bot): void
    {
        $instance = new static($bot, static::CACHE_TTL, static::MAX_SYMBOLS);
        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addCommand('stonks')
                ->setCallback([$instance, 'stonks'])
        );
    }

    /**
     * Respond to a regular !stonks command.
     *
     * @param \Huntress\EventData $event
     *
     * @return \React\Promise\PromiseInterface
     */
    public function stonks(EventData $event): PromiseInterface
    {
        $channel = $event->message->channel;

        // Check for permission.
        $permission = new Permission('p.stonks', $event->huntress, TRUE);
        $permission->addMessageContext($event->message);
        if (!$permission->resolve()) {
            return $permission->sendUnauthorizedMessage($channel);
        }
        // Split arguments and check for "search" at start (case-sensitive).
        $args = self::_split($event->message->content);
        if ($args[1] === 'search') {
            return $this->search($event);
        }
        // Take up to $max_requests symbols to avoid spam.
        $symbols = array_map('mb_strtoupper', array_slice($args, 1, $this->max_symbols));
        $now = time();

        // Check which quotes must be refreshed.
        $refresh = array_filter($symbols, fn($symbol) => (
            !isset($this->cache[$symbol]) ||
            $this->cache[$symbol]['time'] + $this->cache_ttl < $now
        ));

        /** @var PromiseInterface $promise*/
        if ($refresh) {
            // Get new data.
            $promise = $channel->send('Loading stock data...')
                ->then(fn() => $this->client->getQuotes($refresh))
                ->then(function ($data) use ($now) {
                    foreach ($data as $i => $quote) {
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

        // Format and print data.
        return $promise->then(function () use ($channel, $symbols) {
            $promises = [];
            foreach ($symbols as $symbol) {
                if (isset($this->cache[$symbol])) {
                    /** @var \Aran\YahooFinanceApi\Results\Quote $quote */
                    ['time' => $time, 'quote' => $quote] = $this->cache[$symbol];
                    $promises[] = $channel->send('', ['embed' => $this->formatQuote($quote, $time)]);
                }
                else {
                    $promises[] = $channel->send(sprintf("No results returned for $symbol"));
                }
            }
            return all($promises);
        });
    }

    /**
     * Respond to a !stonks search command.
     *
     * @param \Huntress\EventData $event
     *
     * @return \React\Promise\PromiseInterface|null
     */
    public function search(EventData $event): ?PromiseInterface {
        $search = self::arg_substr($event->message->content, 2);
        $channel = $event->message->channel;

        // Refuse to search for an empty string.
        if (!$search) {
            return NULL;
        }

        return $channel->send('Searching...')
            ->then(fn() => $this->client->search($search))
            ->then(function ($results) use ($search, $channel) {
                $embed = new MessageEmbed();
                $count = count($results);
                $embed->setTitle(sprintf(
                    '%d results for "*%s*"',
                    $count,
                    addcslashes($search, '*\\')
                ));
                foreach ($results as $result) {
                    $embed->addField(
                        $result->getSymbol(),
                        sprintf('%s (%s, %s)', $result->getName(), $result->getTypeDisp(), $result->getExchDisp())
                    );
                }
                return $channel->send('', ['embed' => $embed]);
            });
    }

    /**
     * Create a MessageEmbed with formatted Quote data.
     *
     * @param \Aran\YahooFinanceApi\Results\Quote $quote
     * @param int $time
     *
     * @return \CharlotteDunois\Yasmin\Models\MessageEmbed
     * @throws \Exception
     */
    private function formatQuote(Quote $quote, int $time): MessageEmbed {
        $currency = $quote->getCurrency();
        $embed = new MessageEmbed();
        $embed->setTitle("{$quote->getSymbol()} ({$quote->getFullExchangeName()})");
        if ($quote->getMarketState()) {
            $embed->addField(
                'Market state',
                $quote->getMarketState()
            );
        }
        if ($quote->getRegularMarketPreviousClose()) {
            $embed->addField(
                'Previous closing price',
                sprintf("{$currency} %.2f", $quote->getRegularMarketPreviousClose())
            );
        }
        if ($quote->getRegularMarketOpen()) {
            $embed->addField(
                'Opening price',
                sprintf("{$currency} %.2f", $quote->getRegularMarketOpen())
            );
        }
        if ($quote->getRegularMarketPrice()) {
            $embed->addField(
                $quote->getMarketState() === 'REGULAR' ? 'Market price' : 'Closing price',
                sprintf(
                    "{$currency} %.2f, (%+.3f%%)",
                    $quote->getRegularMarketPrice(),
                    $quote->getRegularMarketChangePercent()
                )
            );
        }
        if ($quote->getRegularMarketDayLow() && $quote->getRegularMarketDayHigh()) {
            $embed->addField(
                'Day Low / Day High',
                sprintf(
                    "{$currency} %.2f / {$currency} %.2f",
                    $quote->getRegularMarketDayLow(),
                    $quote->getRegularMarketDayHigh()
                )
            );
        }
        if ($quote->getPostMarketPrice()) {
            $embed->addField(
                'Post-Market price',
                sprintf(
                    "{$currency} %.2f, (%+.3f%%)",
                    $quote->getPostMarketPrice(),
                    $quote->getPostMarketChangePercent()
                )
            );
        }
        if ($quote->getPreMarketPrice()) {
            $embed->addField(
                'Pre-Market price',
                sprintf(
                    "{$currency} %.2f, (%+.3f%%)",
                    $quote->getPreMarketPrice(),
                    $quote->getPreMarketChangePercent()
                )
            );
        }
        if ($quote->getAsk() && $quote->getBid()) {
            $embed->addField(
                'Ask / Bid',
                sprintf(
                    "{$currency} %.2f / {$currency} %.2f",
                    $quote->getAsk(),
                    $quote->getBid()
                )
            );
        }
        $embed->setTimestamp($time);
        return $embed;
    }

}
