<?php

namespace App\Controllers;

/**
 * The public landing page.
 */
class Home extends BaseController
{
    /**
     * GET /
     *
     * @return string The rendered home view
     */
    public function index(): string
    {
        return view('home');
    }
}
