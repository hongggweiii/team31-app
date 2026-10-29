<?php

namespace App\Libraries;

use CodeIgniter\Database\Query;
use Config\Database;
use Config\RequestTimer as RequestTimerConfig;

/**
 * Per-request timing breakdown for finding bottlenecks locally. Config\Events
 * feeds it every query (DBQuery) and prints the report once the response is sent; code
 * that calls another service wraps the call in span(). The report goes to
 * stderr, i.e. the `php spark serve` console:
 *
 *   [timing] GET /create-profile 200  4012 ms total
 *     db connect      1163 ms
 *     db queries      2610 ms  7 queries
 *        262 ms  SELECT * FROM `settings` WHERE `context` IS NULL
 *        986 ms  SELECT `l`.`specialisation_id`, ... (slow)
 *     matcher            0 ms
 *     php + views      239 ms  everything else
 *     total           4012 ms
 *
 * After a redirect (a form post), the page it lands on also reports the whole
 * action: "action total  6900 ms  POST /create-gig -> GET /gigs/17/matches".
 *
 * Settings: Config\RequestTimer.
 */
final class RequestTimer
{
    private const SESSION_KEY = '__request_timer';

    /**
     * @var list<array{sql: string, ms: float}>
     */
    private static array $queries = [];

    /**
     * @var array<string, float> ms per span name
     */
    private static array $spans = [];

    public static function enabled(): bool
    {
        return ! is_cli() && config(RequestTimerConfig::class)->enabled;
    }

    public static function query(Query $query): void
    {
        self::$queries[] = ['sql' => $query->getQuery(), 'ms' => (float) $query->getDuration(6) * 1000];
    }

    /**
     * Runs $fn and adds its wall time to the span $name, even if it throws.
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    public static function span(string $name, callable $fn): mixed
    {
        $start = microtime(true);

        try {
            return $fn();
        } finally {
            self::$spans[$name] = (self::$spans[$name] ?? 0.0) + (microtime(true) - $start) * 1000;
        }
    }

    /**
     * A form post answers with a redirect, so the action the user waits for is
     * two (or more) requests. A redirect leaves its start time in the session;
     * the request that finally renders a page reports the time since then.
     *
     * @return array{float, string}|null [ms, "POST /a -> GET /b"], or null when this request isn't the end of a redirect
     */
    private static function action(string $request, int $status): ?array
    {
        // Only a session that is already open: starting one now, after the response, would fail.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $started = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);
        $chain = [...($started['chain'] ?? []), $request];

        if ($status >= 300 && $status < 400) {
            $_SESSION[self::SESSION_KEY] = ['start' => $started['start'] ?? $_SERVER['REQUEST_TIME_FLOAT'], 'chain' => $chain];

            return null;
        }

        return $started === null ? null : [(microtime(true) - $started['start']) * 1000, implode(' -> ', $chain)];
    }

    /**
     * post_system listener. post_system fires before the response is sent, so
     * the report waits until it has been (for the real status, e.g. a redirect).
     */
    public static function reportAfterResponse(): void
    {
        register_shutdown_function([self::class, 'report']);
    }

    public static function report(): void
    {
        $request = service('request');
        $path    = str_replace('/index.php', '', $request->getUri()->getPath()) ?: '/';
        // The debug toolbar's and hot reloader's own requests would drown out the pages.
        if (str_contains($request->getUri()->getQuery(), 'debugbar') || str_contains($path, '__hot-reload')) {
            return;
        }

        $config  = config(RequestTimerConfig::class);
        $total   = (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000;
        $connect = self::$queries === [] ? 0.0 : (float) Database::connect()->getConnectDuration(6) * 1000;
        $queryMs = array_sum(array_column(self::$queries, 'ms'));

        $lines = [sprintf('[timing] %s %s %d  %.0f ms total', $request->getMethod(), $path, (int) http_response_code(), $total)];
        $lines[] = sprintf('  %-14s %6.0f ms', 'db connect', $connect);
        $lines[] = sprintf('  %-14s %6.0f ms  %d queries', 'db queries', $queryMs, count(self::$queries));

        foreach (self::$queries as $q) {
            $sql     = preg_replace('/\s+/', ' ', $q['sql']);
            $lines[] = sprintf(
                '     %6.0f ms  %s%s',
                $q['ms'],
                mb_strlen($sql) > 110 ? mb_substr($sql, 0, 107) . '...' : $sql,
                $q['ms'] >= $config->slowQueryMs ? '  (slow)' : '',
            );
        }

        foreach (self::$spans as $name => $ms) {
            $lines[] = sprintf('  %-14s %6.0f ms', $name, $ms);
        }

        $lines[] = sprintf('  %-14s %6.0f ms  everything else', 'php + views', max(0.0, $total - $connect - $queryMs - array_sum(self::$spans)));
        $lines[] = sprintf('  %-14s %6.0f ms', 'total', $total);

        $action = self::action("{$request->getMethod()} {$path}", (int) http_response_code());
        if ($action !== null) {
            $lines[] = sprintf('  %-14s %6.0f ms  %s', 'action total', ...$action);
        }

        file_put_contents('php://stderr', implode(PHP_EOL, $lines) . PHP_EOL);
    }
}
