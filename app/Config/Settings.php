<?php

namespace Config;

use CodeIgniter\Settings\Config\Settings as BaseSettings;

/**
 * codeigniter4/settings, which Shield reads its config through (setting()).
 * The app stores no settings, so they are kept in files under
 * writable/settings instead of the `settings` table: the database handler
 * costs a query on every request just to find the table empty.
 */
class Settings extends BaseSettings
{
    public $handlers = ['file'];
}
