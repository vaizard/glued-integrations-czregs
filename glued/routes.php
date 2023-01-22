<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

foreach ($settings['routes'] as $name => $leaf) {
    if (isset($leaf['methods'])) {
        foreach ($leaf['methods'] as $request => $method) {
              $route = $app->$request($leaf['path'], $method);
              $route = $route->setName($name);
        }    
    }
}

/**
 * Catch-all route to serve a a 204 options response
 * NOTE: make sure this route is defined last
 */
$app->options('/{routes:.+}', function (Request $request, Response $response, $args) {
    return $response->withCode(204);
})->setName('fallback_options');

/**
 * Catch-all route to serve a 404 Not Found page if none of the routes match
 * NOTE: make sure this route is defined last
 */
$app->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], '/{routes:.+}', function (Request $request, Response $response) {
    throw new HttpNotFoundException($request);
})->setName('fallback_notfound');

// $app->get('/api/skeleton/devtest', Glued\Controllers\ServiceController::class . ':health')->setName('devtest');