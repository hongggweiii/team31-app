<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * App\Libraries\RequestTimer: after each web request, prints where the time
 * went (database connect, every query, the matcher call) to the console that
 * runs `php spark serve`. Override in .env, e.g.
 *     requestTimer.enabled = false
 */
class RequestTimer extends BaseConfig
{
    /**
     * On by default in development only.
     */
    public bool $enabled = ENVIRONMENT === 'development';

    /**
     * Queries at least this slow (ms) are marked as slow.
     */
    public int $slowQueryMs = 500;
}
