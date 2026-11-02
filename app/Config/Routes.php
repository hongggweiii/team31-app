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
    $routes->post('tag-suggestions', 'TagSuggestions::suggest', ['as' => 'tag-suggestions']);

    $routes->get('taxonomy-dashboard', 'TaxonomyDashboard::proposals', ['as' => 'taxonomy-dashboard']);
    $routes->post('taxonomy-dashboard/review', 'TaxonomyDashboard::review', ['as' => 'taxonomy-review']);
    $routes->get('taxonomy-dashboard/taxonomy', 'TaxonomyDashboard::taxonomy', ['as' => 'taxonomy-manage']);
    $routes->post('taxonomy-dashboard/taxonomy', 'TaxonomyDashboard::edit', ['as' => 'taxonomy-edit']);
});

service('auth')->routes($routes);
