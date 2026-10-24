<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

$routes->group('', ['filter' => 'session'], static function (RouteCollection $routes): void {
    $routes->get('create-gig', 'Gigs::new', ['as' => 'gig-new']);
    $routes->post('create-gig', 'Gigs::create', ['as' => 'gig-create']);
    $routes->get('gigs/(:num)/matches', 'Gigs::matches/$1', ['as' => 'gig-matches']);
    $routes->get('create-profile', 'Showcases::new', ['as' => 'showcase-new']);
    $routes->post('create-profile', 'Showcases::create', ['as' => 'showcase-create']);
});

service('auth')->routes($routes);
