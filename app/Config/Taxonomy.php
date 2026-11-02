<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Taxonomy service (taxonomy-service/), used by App\Libraries\TaxonomyClient.
 * Override per machine in .env, e.g.
 *     taxonomy.baseURL = 'http://localhost:8003/'
 * The service reads its own taxonomy.* settings from the same .env.
 */
class Taxonomy extends BaseConfig
{
    /**
     * Where taxonomy-service/ is listening. Keep the trailing slash: request
     * paths are resolved relative to it.
     */
    public string $baseURL = 'http://localhost:8003/';

    /**
     * Seconds. A suggestion is an OpenAI call of a few seconds; the service
     * itself gives up on OpenAI after taxonomy.openaiTimeout (20). Kept under
     * PHP's max_execution_time (30), so a slow service gives the user an error
     * message instead of a killed request.
     */
    public int $timeout = 25;

    /**
     * Sent as X-Service-Token when set. The service requires it when the same
     * .env key, taxonomy.serviceToken, is set.
     */
    public string $serviceToken = '';
}
