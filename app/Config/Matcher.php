<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Live matching service (matcher/), used by App\Libraries\MatcherClient.
 * Override per machine in .env, e.g.
 *     matcher.baseURL = 'http://localhost:8002/'
 */
class Matcher extends BaseConfig
{
    /**
     * Where matcher/ is listening. Keep the trailing slash: request paths are
     * resolved relative to it.
     */
    public string $baseURL = 'http://localhost:8002/';

    /**
     * Seconds. Matching must answer within 5 s; a match normally takes well
     * under one.
     */
    public int $timeout = 5;

    /**
     * Providers to show for a gig (the ranker's default top_k).
     */
    public int $topK = 10;
}
