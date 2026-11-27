<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Gateway;

use Laminas\Http\Client as LaminasHttpClient;

/**
 * Builds a fresh HTTP client per request.
 *
 * Explicit rather than Magento's generated LaminasClientFactory for two reasons.
 * First, a generated factory only exists after setup:di:compile, so unit tests that
 * mock it fail in any tree that has not been compiled — an avoidable coupling
 * between a pure test and a build step. Second, "fresh per request" is a correctness
 * requirement here, not a preference: a Laminas client keeps its URI, method, headers
 * and raw body between sends, so a GET issued on a client that previously carried a
 * PUT body would send that body again. The onboarding cron makes exactly that
 * sequence — create (POST), attach bank (PUT), read back (GET) — in one process.
 *
 * Magento's Curl client is not an option: it exposes no public PUT.
 */
class HttpClientFactory
{
    /**
     * @return LaminasHttpClient
     */
    public function create(): LaminasHttpClient
    {
        return new LaminasHttpClient();
    }
}
