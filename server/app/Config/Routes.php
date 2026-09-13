<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

// Forms are not wired to a backend yet, so POST re-renders the page
// rather than returning a 404.
$routes->match(['get', 'post'], 'create-profile', 'Home::createProfile');
$routes->match(['get', 'post'], 'create-gig', 'Home::createGig');
