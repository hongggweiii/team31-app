<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Provider embedding service (embedding-service/), used by
 * App\Libraries\ProviderEmbedder. Override per machine in .env, e.g.
 *     embedding.baseURL = 'http://localhost:8001/'
 */
class Embedding extends BaseConfig
{
    /**
     * Where embedding-service/ is listening. Keep the trailing slash:
     * request paths are resolved relative to it.
     */
    public string $baseURL = 'http://localhost:8001/';

    /**
     * Seconds. Encoding one profile on CPU takes a few seconds.
     */
    public int $timeout = 30;

    /**
     * Must match EMBED_DIM in embedding-service/embedding.py — and the
     * vector DB column the embeddings are pushed into.
     */
    public int $dimensions = 1024;

    /**
     * `php spark embed:auto` (run by cron every minute) embeds waiting
     * showcases once this many are waiting...
     */
    public int $autoBatchSize = 20;

    /**
     * ...or once the oldest has waited this many minutes, whichever comes first.
     */
    public int $autoMaxWaitMinutes = 15;
}
