<?php
declare(strict_types=1);

use App\Application\Actions\Auth\LoginAction;
use App\Application\Actions\Auth\LogoutAction;
use App\Application\Actions\Auth\MeAction;
use App\Application\Actions\Auth\RefreshAction;
use App\Application\Actions\Auth\RegisterAction;
use App\Application\Middleware\JwtAuthMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy as Group;

return function (App $app) {
    $app->options('/{routes:.*}', function (Request $request, Response $response) {
        return $response;
    });

    $app->get('/ping', function (Request $request, Response $response) {
        $response->getBody()->write(json_encode(['status' => 'ok']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $app->group('/api/v1', function (Group $group) {

        // Public auth routes
        $group->post('/auth/register', RegisterAction::class);
        $group->post('/auth/login',    LoginAction::class);
        $group->post('/auth/refresh',  RefreshAction::class);

        // Protected routes
        $group->group('', function (Group $protected) {
            $protected->post('/auth/logout', LogoutAction::class);
            $protected->get('/auth/me',      MeAction::class);
        })->add(JwtAuthMiddleware::class);

    });
};